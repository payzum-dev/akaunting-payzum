<?php

namespace Modules\Payzum\Tests\Feature;

use App\Jobs\Banking\CreateBankingDocumentTransaction;
use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Feature\PaymentTestCase;

class PaymentTest extends PaymentTestCase
{
    public const WEBHOOK_SECRET = 'test-webhook-secret';

    public $alias = 'payzum';

    public $setting_request = [
        'name'           => 'Payzum',
        'api_key'        => 'pz_test_key',
        'webhook_secret' => self::WEBHOOK_SECRET,
        'mode'           => 'sandbox',
        'customer'       => 1,
        'order'          => 1,
        'debug'          => 0,
    ];

    protected function prepareInvoice(): void
    {
        $this->updateSetting();

        $this->createInvoice();

        // IPN deliveries are unauthenticated server-to-server posts; drop the
        // admin session updateSetting() left behind, or `auth.redirect` on the
        // guest route group would bounce them.
        $this->app['auth']->forgetGuards();

        app('url')->defaults(['company_id' => company_id()]);
    }

    /**
     * A verified-shape IPN payload for the created invoice.
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'payment_id'     => 'pzi_test_0001',
            'order_id'       => (string) $this->invoice->id,
            'payment_status' => 'finished',
            'price_amount'   => $this->invoice->amount,
            'price_currency' => strtolower($this->invoice->currency_code),
            'event_at'       => time(),
        ], $overrides);
    }

    protected function postIpn(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);

        $signature = $signature ?? hash_hmac('sha512', $body, self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            URL::signedRoute('signed.payzum.invoices.notify', [$this->invoice->id]),
            [],
            [],
            [],
            [
                'HTTP_X_NOWPAYMENTS_SIG' => $signature,
                'HTTP_X_PAYZUM_EVENT_ID' => 'evt_test_0001',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );
    }

    public function testItShouldMarkInvoicePaidOnVerifiedFinishedIpn()
    {
        $this->prepareInvoice();

        $this->postIpn($this->payload())->assertOk();

        $invoice = Document::find($this->invoice->id);

        $this->assertEquals('paid', $invoice->status);

        $this->assertDatabaseHas('transactions', [
            'document_id' => $invoice->id,
            'type'        => 'income',
        ]);
    }

    public function testItShouldRejectForgedSignature()
    {
        $this->prepareInvoice();

        $this->postIpn($this->payload(), hash_hmac('sha512', 'forged-body', 'wrong-secret'))
            ->assertStatus(400);

        $this->assertNotEquals('paid', Document::find($this->invoice->id)->status);

        $this->assertDatabaseMissing('transactions', ['document_id' => $this->invoice->id]);
    }

    public function testItShouldAcknowledgePendingWithoutCrediting()
    {
        $this->prepareInvoice();

        $this->postIpn($this->payload(['payment_status' => 'waiting']))->assertOk();

        $this->assertNotEquals('paid', Document::find($this->invoice->id)->status);

        $this->assertDatabaseMissing('transactions', ['document_id' => $this->invoice->id]);
    }

    public function testItShouldRejectOrderIdMismatch()
    {
        $this->prepareInvoice();

        $this->postIpn($this->payload(['order_id' => '999999']))->assertStatus(400);

        $this->assertDatabaseMissing('transactions', ['document_id' => $this->invoice->id]);
    }

    public function testItShouldNotCreditOnAmountMismatch()
    {
        $this->prepareInvoice();

        $this->postIpn($this->payload(['price_amount' => 0.01]))->assertOk();

        $this->assertNotEquals('paid', Document::find($this->invoice->id)->status);

        $this->assertDatabaseMissing('transactions', ['document_id' => $this->invoice->id]);
    }

    public function testItShouldRecordThePaymentOfAPartiallyPaidInvoice()
    {
        $this->prepareInvoice();

        // Half of the invoice was settled by other means; the hosted checkout
        // charges the balance, and the books have to move by that same amount.
        $half = round($this->invoice->amount / 2, 2);
        $this->dispatch(new CreateBankingDocumentTransaction($this->invoice, [
            'company_id' => $this->invoice->company_id,
            'account_id' => setting('default.account'),
            'amount' => $half,
            'currency_code' => $this->invoice->currency_code,
            'currency_rate' => $this->invoice->currency_rate,
            'payment_method' => 'offline-payments.cash.1',
            'type' => 'income',
        ]));

        $invoice = Document::find($this->invoice->id);
        $due = $invoice->amount - $invoice->paid;

        $this->postIpn($this->payload(['price_amount' => $due]))->assertOk();

        $invoice = Document::find($this->invoice->id);

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(2, Transaction::where('document_id', $invoice->id)->count());
    }

    public function testItShouldIgnoreRedeliveredFinishedIpn()
    {
        $this->prepareInvoice();

        $this->postIpn($this->payload())->assertOk();
        $this->postIpn($this->payload())->assertOk();

        $this->assertEquals(1, Transaction::where('document_id', $this->invoice->id)->count());
    }
}
