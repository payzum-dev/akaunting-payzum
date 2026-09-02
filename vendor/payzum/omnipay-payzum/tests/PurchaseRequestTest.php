<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Tests;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Payzum\Gateway;
use Omnipay\Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    private Gateway $gateway;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport();
        $this->gateway = new Gateway($this->getHttpClient(), $this->getHttpRequest());
        $this->gateway->setApiKey(TestKeys::API_KEY);
    }

    /** @return array<string, mixed> */
    private function purchaseOptions(array $overrides = []): array
    {
        return $overrides + [
            'amount' => '49.99',
            'currency' => 'USD',
            'transactionId' => 'ORDER-1001',
            'description' => 'Test order',
            'returnUrl' => 'https://shop.example/return',
            'cancelUrl' => 'https://shop.example/cancel',
            'notifyUrl' => 'https://shop.example/webhook',
            'transport' => $this->transport,
        ];
    }

    public function testSuccessfulPurchaseRedirectsToHostedCheckout(): void
    {
        $this->transport->queue(201, json_encode([
            'payment_id' => 'pzi_abc123',
            'invoice_url' => 'https://merchant.payzum.com/i/pzi_abc123',
            'payment_status' => 'waiting',
        ]));

        $response = $this->gateway->purchase($this->purchaseOptions())->send();

        self::assertFalse($response->isSuccessful());
        self::assertTrue($response->isRedirect());
        self::assertSame('GET', $response->getRedirectMethod());
        self::assertSame('https://merchant.payzum.com/i/pzi_abc123', $response->getRedirectUrl());
        self::assertSame('pzi_abc123', $response->getTransactionReference());
        self::assertNull($response->getMessage());
    }

    public function testRequestCarriesTheOrderOnTheWire(): void
    {
        $this->transport->queue(201, '{"payment_id":"pzi_x","invoice_url":"https://x"}');

        $this->gateway->purchase($this->purchaseOptions())->send();

        $sent = $this->transport->lastRequest();
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://merchant.payzum.com/v1/payment', $sent['url']);

        // The API rejects string amounts: price_amount must be a JSON number,
        // and it must not pick up float noise on the way out.
        self::assertStringContainsString('"price_amount":49.99', $sent['body']);

        $body = json_decode($sent['body'], true);
        self::assertSame('usd', $body['price_currency']);
        self::assertSame('all', $body['pay_currency']);
        self::assertSame('ORDER-1001', $body['order_id']);
        self::assertSame('Test order', $body['order_description']);
        self::assertSame('https://shop.example/return', $body['success_url']);
        self::assertSame('https://shop.example/cancel', $body['cancel_url']);
        self::assertSame('https://shop.example/webhook', $body['ipn_callback_url']);
    }

    public function testTestModeTargetsTheSandbox(): void
    {
        $this->transport->queue(201, '{"payment_id":"pzi_x","invoice_url":"https://x"}');
        $this->gateway->setTestMode(true);

        $this->gateway->purchase($this->purchaseOptions())->send();

        self::assertStringStartsWith(
            'https://staging.payzum.com/',
            $this->transport->lastRequest()['url'],
        );
    }

    public function testPinnedPayCurrencyAndNetworkAreForwarded(): void
    {
        $this->transport->queue(201, '{"payment_id":"pzi_x","invoice_url":"https://x"}');

        $this->gateway->purchase($this->purchaseOptions([
            'payCurrency' => 'usdc',
            'network' => 'polygon',
        ]))->send();

        $body = json_decode($this->transport->lastRequest()['body'], true);
        self::assertSame('usdc', $body['pay_currency']);
        self::assertSame('polygon', $body['network']);
    }

    public function testIdempotencyKeyBecomesTheHeader(): void
    {
        $this->transport->queue(201, '{"payment_id":"pzi_x","invoice_url":"https://x"}');

        $this->gateway->purchase($this->purchaseOptions([
            'idempotencyKey' => 'order-1001-attempt-1',
        ]))->send();

        $headers = $this->transport->lastRequest()['headers'];
        $headers = array_change_key_case($headers, CASE_LOWER);
        self::assertSame('order-1001-attempt-1', $headers['idempotency-key'] ?? null);
    }

    public function testApiRejectionBecomesAFailedResponse(): void
    {
        $this->transport->queue(401, '{"code":"UNAUTHORIZED","message":"API key is not valid"}');

        $response = $this->gateway->purchase($this->purchaseOptions())->send();

        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isRedirect());
        self::assertNull($response->getRedirectUrl());
        self::assertSame('UNAUTHORIZED', $response->getCode());
        self::assertStringContainsString('API key is not valid', (string) $response->getMessage());
    }

    public function testMalformedApiKeyBecomesAnOmnipayException(): void
    {
        // The SDK rejects keys shorter than 32 chars before any request goes
        // out; the driver must surface that as Omnipay's exception type.
        $this->gateway->setApiKey('too-short');

        $this->expectException(InvalidRequestException::class);

        $this->gateway->purchase($this->purchaseOptions())->send();
    }

    public function testAmountIsRequired(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->gateway->purchase([
            'currency' => 'USD',
            'transport' => $this->transport,
        ])->send();
    }
}
