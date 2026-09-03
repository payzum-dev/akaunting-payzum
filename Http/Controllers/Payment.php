<?php

namespace Modules\Payzum\Http\Controllers;

use App\Abstracts\Http\PaymentController;
use App\Events\Document\PaymentReceived;
use App\Http\Requests\Portal\InvoicePayment as PaymentRequest;
use App\Models\Document\Document;
use App\Traits\Omnipay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Omnipay\Common\Exception\InvalidRequestException;

class Payment extends PaymentController
{
    use Omnipay;

    public $alias = 'payzum';

    public $type = 'redirect';

    /**
     * Create the Payzum hosted-checkout invoice and send the buyer to it.
     *
     * Reached by the POST the core redirect component fires when the customer
     * clicks Confirm. The Omnipay trait handles the response: on redirect it
     * stores the Payzum payment id as the reference and returns the checkout
     * URL as JSON for the portal JS to follow.
     */
    public function confirm(Document $invoice, PaymentRequest $request)
    {
        $this->createGateway();

        return $this->purchase($invoice, $request, [
            'description' => trans_choice('general.invoices', 1) . ' ' . $invoice->document_number,
            'notifyUrl' => $this->getNotifyUrl($invoice),
        ]);
    }

    /**
     * Handle the buyer's return from the hosted checkout page.
     *
     * Crypto confirmation is asynchronous: when the buyer comes back the
     * payment is usually still confirming on-chain — and this route trusts
     * nothing the browser carries anyway. It only shows a "processing"
     * notice; the invoice is marked paid exclusively from the signed
     * server-to-server `notify` callback.
     */
    public function return(Document $invoice, Request $request)
    {
        flash(trans('payzum::general.payment.processing'))->success();

        return redirect($this->getFinishUrl($invoice));
    }

    /**
     * Handle the Payzum IPN (server-to-server payment notification).
     *
     * The Omnipay driver verifies the HMAC-SHA-512 signature over the raw
     * request bytes (with a replay window) before any payload field is
     * readable — a forged, stale, or malformed delivery throws and is
     * rejected without touching the invoice.
     */
    public function notify(Document $invoice, Request $request)
    {
        $payzum_log = $this->logger;

        $gateway = $this->createGateway();

        $notification = $gateway->acceptNotification();

        try {
            // Accessors verify the HMAC signature on first use and throw on a
            // forged, stale, or malformed delivery.
            $notification->getTransactionStatus();
        } catch (InvalidRequestException $e) {
            $payzum_log->info('PAYZUM :: IPN REJECTED: ' . $e->getMessage());

            return response('Invalid notification', 400);
        }

        if (!empty($this->setting['debug'])) {
            $payzum_log->info('PAYZUM :: IPN PAYLOAD: ', $notification->getData());
        }

        if ($notification->getTransactionId() !== (string) $invoice->id) {
            $payzum_log->info('PAYZUM :: ORDER ID MISMATCH! ' . $notification->getTransactionId());

            return response('Order mismatch', 400);
        }

        if (!$notification->isSuccessful()) {
            // Pending, confirming, failed, or expired: acknowledge without
            // crediting. A `finished` delivery arrives on its own if the
            // payment completes.
            $payzum_log->info('PAYZUM :: NOT COMPLETED: ' . $notification->getPaymentStatus());

            return response('OK', 200);
        }

        $payload = $notification->getData();

        // What the hosted checkout charged: the trait creates the invoice for
        // the outstanding balance, not the document total.
        $amount_due = $invoice->amount - $invoice->paid;

        $amount_match = isset($payload['price_amount'])
            && (double) $payload['price_amount'] == (double) $amount_due;

        $currency_match = isset($payload['price_currency'])
            && strtolower((string) $payload['price_currency']) == strtolower($invoice->currency_code);

        if (!$amount_match) {
            $payzum_log->info('PAYZUM :: TOTAL PAID MISMATCH! ' . ($payload['price_amount'] ?? 'null'));
        }

        if (!$currency_match) {
            $payzum_log->info('PAYZUM :: CURRENCY MISMATCH! ' . ($payload['price_currency'] ?? 'null'));
        }

        if ($amount_match && $currency_match) {
            $recorded = $this->recordPayment($invoice, $request, $amount_due, $notification->getTransactionReference());

            if ($recorded === null) {
                // Deliveries are retried; a redelivered `finished` event must
                // be a no-op, not a second transaction.
                $payzum_log->info('PAYZUM :: ALREADY PAID, IGNORING REDELIVERY :: Invoice: ' . $invoice->id);

                return response('OK', 200);
            }

            if ($recorded === false) {
                // The transaction did not make it into the books. Answering 200
                // here would tell Payzum the delivery succeeded and stop the
                // retries, leaving money received against an unpaid invoice
                // with nothing but a log line to show for it.
                $payzum_log->info('PAYZUM :: PAYMENT NOT RECORDED, ASKING FOR A RETRY :: Invoice: ' . $invoice->id);

                return response('Payment not recorded', 500);
            }

            $payzum_log->info('PAYZUM :: Payment Received for Invoice: ' . $invoice->id . ' - Payment ID: ' . $notification->getTransactionReference());
        }

        return response('OK', 200);
    }

    /**
     * Record the payment against the invoice, at most once.
     *
     * The invoice row is locked and re-read first: a redelivered notification
     * can arrive while the previous one is still being processed, and two
     * check-then-act paths would otherwise both create a transaction for the
     * same money. That lock is the only reason a transaction is here, so the
     * transaction has to decide the outcome on the books alone — never on
     * whether a side effect of the event happened to succeed.
     *
     * @return bool|null true when this call recorded the payment, null when it
     *                   was already settled, false when nothing was written
     */
    protected function recordPayment(Document $invoice, Request $request, $amount_due, $reference)
    {
        return DB::transaction(function () use ($invoice, $request, $amount_due, $reference) {
            $locked = Document::query()->whereKey($invoice->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status == 'paid') {
                return null;
            }

            $paid_before = $invoice->paid;

            $this->setReference($invoice, $reference);

            try {
                $this->dispatchPaidEvent($invoice, $request, $amount_due);
            } catch (\Throwable $e) {
                // PaymentReceived runs two core listeners in one synchronous
                // call: CreateDocumentTransaction, which writes the money, and
                // SendDocumentPaymentNotification, which renders a PDF invoice
                // and hands it to the mailer. That notification is ShouldQueue,
                // but Akaunting ships QUEUE_CONNECTION=sync, so on a default
                // install the SMTP call happens right here — inside the
                // transaction, after the books have already moved.
                //
                // Letting it escape rolls the whole transaction back: an
                // unreachable mail host, an expired TLS certificate or a
                // rendering error would un-pay an invoice the buyer really did
                // pay, and the retry would find the same broken mailer and fail
                // the same way. Money must not depend on the mail server.
                //
                // So the exception never decides the outcome. The outcome is
                // decided below by whether the books actually moved: if the
                // financial listener is the one that failed, `paid` is
                // unchanged and this still returns false, so the caller answers
                // 500 and asks Payzum to retry. If the notification is the one
                // that failed, `paid` is raised and the payment commits — the
                // merchant loses an email, not the payment.
                $this->logger->info('PAYZUM :: PAID EVENT LISTENER FAILED :: Invoice: ' . $invoice->id . ' :: ' . $e->getMessage());
            }

            $this->forgetReference($invoice);

            // The core swallows a rejected transaction (an amount that fails
            // its over-payment check is flashed, not thrown), so the only
            // trustworthy signal is whether the books actually moved.
            $invoice->refresh();

            return $invoice->paid > $paid_before;
        });
    }

    /**
     * Dispatch the paid event for the amount that was actually charged.
     *
     * The core helper always reports the document total, which the banking job
     * then rejects as an over-payment on any invoice that had a previous part
     * payment — silently, leaving the invoice unpaid. This is the same event
     * with the outstanding balance instead.
     */
    public function dispatchPaidEvent($invoice, $request, $amount = null)
    {
        if ($amount === null) {
            parent::dispatchPaidEvent($invoice, $request);

            return;
        }

        $request['company_id'] = $invoice->company_id;
        $request['account_id'] = setting($this->alias . '.account_id', setting('default.account'));
        $request['amount'] = $amount;
        $request['payment_method'] = $this->alias;
        $request['reference'] = $this->getReference($invoice);
        $request['type'] = 'income';

        event(new PaymentReceived($invoice, $request));
    }

    /**
     * The IPN endpoint lives on the `signed` route group (company
     * identification + model binding both require either a signed URL or an
     * authenticated user, and an IPN has neither), so the URL sent to Payzum
     * at purchase time carries a permanent Laravel signature. The payment
     * notification itself is additionally HMAC-signed by the gateway.
     */
    public function getNotifyUrl($invoice)
    {
        return URL::signedRoute('signed.' . $this->alias . '.invoices.notify', [$invoice->id]);
    }

    /**
     * Build the Omnipay gateway from the module settings.
     */
    public function createGateway()
    {
        $this->create('Payzum', null, request());

        $this->gateway->initialize([
            'apiKey' => $this->setting['api_key'] ?? '',
            'webhookSecret' => $this->setting['webhook_secret'] ?? '',
            'testMode' => isset($this->setting['mode']) && $this->setting['mode'] == 'sandbox',
            'payCurrency' => !empty($this->setting['pay_currency']) ? $this->setting['pay_currency'] : null,
        ]);

        return $this->gateway;
    }
}
