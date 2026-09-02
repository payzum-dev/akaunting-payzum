<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Tests;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Payzum\Gateway;
use Omnipay\Payzum\Message\Response\CompletePurchaseResponse;
use Omnipay\Tests\TestCase;

class FetchTransactionTest extends TestCase
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

    private function queueInvoice(string $status): void
    {
        $this->transport->queue(200, json_encode([
            'payment_id' => 'pzi_abc123',
            'order_id' => 'ORDER-1001',
            'payment_status' => $status,
        ]));
    }

    private function fetch(array $options = []): \Omnipay\Payzum\Message\Response\FetchTransactionResponse
    {
        return $this->gateway->fetchTransaction($options + [
            'transactionReference' => 'pzi_abc123',
            'transport' => $this->transport,
        ])->send();
    }

    public function testFinishedIsTheOnlySuccessfulStatus(): void
    {
        $this->queueInvoice('finished');
        $response = $this->fetch();

        self::assertTrue($response->isSuccessful());
        self::assertFalse($response->isPending());
        self::assertFalse($response->isExpired());
        self::assertFalse($response->isCancelled());
        self::assertSame('pzi_abc123', $response->getTransactionReference());
        self::assertSame('ORDER-1001', $response->getTransactionId());
    }

    /** @return array<string, array{string}> */
    public static function pendingStatuses(): array
    {
        return ['waiting' => ['waiting'], 'partially_paid' => ['partially_paid']];
    }

    /** @dataProvider pendingStatuses */
    public function testNonTerminalStatusesArePending(string $status): void
    {
        $this->queueInvoice($status);
        $response = $this->fetch();

        self::assertFalse($response->isSuccessful());
        self::assertTrue($response->isPending());
    }

    public function testExpired(): void
    {
        $this->queueInvoice('expired');
        $response = $this->fetch();

        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isPending());
        self::assertTrue($response->isExpired());
    }

    public function testFailedSurfacesAsCancelled(): void
    {
        $this->queueInvoice('failed');
        $response = $this->fetch();

        self::assertFalse($response->isSuccessful());
        self::assertTrue($response->isCancelled());
    }

    public function testUnknownStatusIsNeverTreatedAsPaid(): void
    {
        $this->queueInvoice('hyperconfirmed');
        $response = $this->fetch();

        self::assertFalse($response->isSuccessful());
        self::assertFalse($response->isPending());
        self::assertNull($response->status());
        self::assertSame('hyperconfirmed', $response->getPaymentStatus());
        self::assertStringContainsString('hyperconfirmed', (string) $response->getMessage());
    }

    public function testFetchByMerchantOrderId(): void
    {
        $this->queueInvoice('waiting');

        $this->gateway->fetchTransaction([
            'transactionId' => 'ORDER 1001',
            'transport' => $this->transport,
        ])->send();

        // order ids are caller-controlled: they must be URL-encoded on the path
        self::assertSame(
            'https://merchant.payzum.com/v1/payment/ORDER%201001',
            $this->transport->lastRequest()['url'],
        );
    }

    public function testCompletePurchaseSharesTheMapping(): void
    {
        $this->queueInvoice('finished');

        $response = $this->gateway->completePurchase([
            'transactionReference' => 'pzi_abc123',
            'transport' => $this->transport,
        ])->send();

        self::assertInstanceOf(CompletePurchaseResponse::class, $response);
        self::assertTrue($response->isSuccessful());
    }

    public function testMissingReferenceIsRejectedLocally(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->gateway->fetchTransaction(['transport' => $this->transport])->send();
    }

    public function testApiErrorBecomesAFailedResponse(): void
    {
        $this->transport->queue(404, '{"code":"PAYMENT_NOT_FOUND","message":"No such payment"}');

        $response = $this->fetch();

        self::assertFalse($response->isSuccessful());
        self::assertSame('PAYMENT_NOT_FOUND', $response->getCode());
        self::assertStringContainsString('No such payment', (string) $response->getMessage());
    }
}
