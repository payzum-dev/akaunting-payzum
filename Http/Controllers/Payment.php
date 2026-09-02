<?php

namespace Modules\Payzum\Http\Controllers;

use App\Abstracts\Http\PaymentController;
use App\Http\Requests\Portal\InvoicePayment as PaymentRequest;
use App\Models\Document\Document;
use App\Traits\Omnipay;
use Illuminate\Http\Request;
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

        $amount_match = isset($payload['price_amount'])
            && (double) $payload['price_amount'] == (double) ($invoice->amount - $invoice->paid);

        $currency_match = isset($payload['price_currency'])
            && strtolower((string) $payload['price_currency']) == strtolower($invoice->currency_code);

        if (!$amount_match) {
            $payzum_log->info('PAYZUM :: TOTAL PAID MISMATCH! ' . ($payload['price_amount'] ?? 'null'));
        }

        if (!$currency_match) {
            $payzum_log->info('PAYZUM :: CURRENCY MISMATCH! ' . ($payload['price_currency'] ?? 'null'));
        }

        if ($amount_match && $currency_match) {
            if ($invoice->status == 'paid') {
                // Deliveries are retried; a redelivered `finished` event must
                // be a no-op, not a second transaction.
                $payzum_log->info('PAYZUM :: ALREADY PAID, IGNORING REDELIVERY :: Invoice: ' . $invoice->id);

                return response('OK', 200);
            }

            $this->setReference($invoice, $notification->getTransactionReference());

            $this->dispatchPaidEvent($invoice, $request);

            $this->forgetReference($invoice);

            $payzum_log->info('PAYZUM :: Payment Received for Invoice: ' . $invoice->id . ' - Payment ID: ' . $notification->getTransactionReference());
        }

        return response('OK', 200);
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
