<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Tests;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Message\NotificationInterface;
use Omnipay\Payzum\Gateway;
use Omnipay\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

class AcceptNotificationTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function gatewayFor(array $payload, ?string $signature = null, array $extraServer = []): Gateway
    {
        $body = json_encode($payload);
        $signature ??= hash_hmac('sha512', $body, TestKeys::WEBHOOK_SECRET);

        $httpRequest = new HttpRequest(
            server: [
                'HTTP_X_NOWPAYMENTS_SIG' => $signature,
                'HTTP_X_PAYZUM_EVENT_ID' => 'evt_0001',
                'CONTENT_TYPE' => 'application/json',
            ] + $extraServer,
            content: $body,
        );

        $gateway = new Gateway($this->getHttpClient(), $httpRequest);
        $gateway->setApiKey(TestKeys::API_KEY);
        $gateway->setWebhookSecret(TestKeys::WEBHOOK_SECRET);

        return $gateway;
    }

    /** @return array<string, mixed> */
    private function paidPayload(): array
    {
        return [
            'payment_id' => 'pzi_abc123',
            'order_id' => 'ORDER-1001',
            'payment_status' => 'finished',
            'event_at' => time(),
        ];
    }

    public function testVerifiedPaidIpnCompletes(): void
    {
        $notification = $this->gatewayFor($this->paidPayload())->acceptNotification();

        self::assertSame(NotificationInterface::STATUS_COMPLETED, $notification->getTransactionStatus());
        self::assertSame('pzi_abc123', $notification->getTransactionReference());
        self::assertSame('ORDER-1001', $notification->getTransactionId());
        self::assertSame('evt_0001', $notification->getEventId());
        self::assertSame('finished', $notification->getPaymentStatus());
    }

    public function testSendReturnsTheNotificationItself(): void
    {
        $notification = $this->gatewayFor($this->paidPayload())->acceptNotification();

        self::assertSame(NotificationInterface::STATUS_COMPLETED, $notification->send()->getTransactionStatus());
    }

    public function testIsSuccessfulOnlyForFinished(): void
    {
        self::assertTrue($this->gatewayFor($this->paidPayload())->acceptNotification()->isSuccessful());

        foreach (['waiting', 'partially_paid', 'expired', 'failed'] as $status) {
            $payload = $this->paidPayload();
            $payload['payment_status'] = $status;

            self::assertFalse(
                $this->gatewayFor($payload)->acceptNotification()->isSuccessful(),
                "isSuccessful() must be false for {$status}"
            );
        }
    }

    public function testTamperedBodyIsRejected(): void
    {
        $payload = $this->paidPayload();
        $signature = hash_hmac('sha512', json_encode($payload), TestKeys::WEBHOOK_SECRET);
        $payload['order_id'] = 'ORDER-EVIL';

        $notification = $this->gatewayFor($payload, $signature)->acceptNotification();

        $this->expectException(InvalidRequestException::class);
        $notification->getTransactionStatus();
    }

    public function testStaleEventIsRejected(): void
    {
        $payload = $this->paidPayload();
        $payload['event_at'] = time() - 3600; // outside the 600s replay window

        $notification = $this->gatewayFor($payload)->acceptNotification();

        $this->expectException(InvalidRequestException::class);
        $notification->getTransactionStatus();
    }

    public function testWaitingIsPending(): void
    {
        $payload = $this->paidPayload();
        $payload['payment_status'] = 'waiting';

        $notification = $this->gatewayFor($payload)->acceptNotification();

        self::assertSame(NotificationInterface::STATUS_PENDING, $notification->getTransactionStatus());
    }

    public function testExpiredFails(): void
    {
        $payload = $this->paidPayload();
        $payload['payment_status'] = 'expired';

        $notification = $this->gatewayFor($payload)->acceptNotification();

        self::assertSame(NotificationInterface::STATUS_FAILED, $notification->getTransactionStatus());
    }

    public function testUnknownStatusStaysPendingAndExplainsItself(): void
    {
        $payload = $this->paidPayload();
        $payload['payment_status'] = 'hyperconfirmed';

        $notification = $this->gatewayFor($payload)->acceptNotification();

        self::assertSame(NotificationInterface::STATUS_PENDING, $notification->getTransactionStatus());
        self::assertStringContainsString('hyperconfirmed', (string) $notification->getMessage());
    }

    public function testSecurityEventWithoutStatusStaysPending(): void
    {
        // wrong_token_received / late_deposit_received style deliveries must
        // never credit an order, whatever their body carries.
        $notification = $this->gatewayFor([
            'payment_id' => 'pzi_abc123',
            'event_at' => time(),
        ])->acceptNotification();

        self::assertSame(NotificationInterface::STATUS_PENDING, $notification->getTransactionStatus());
    }

    public function testMissingWebhookSecretIsRejectedBeforeAnyParsing(): void
    {
        $gateway = $this->gatewayFor($this->paidPayload());
        $gateway->setWebhookSecret(null);

        $notification = $gateway->acceptNotification();

        $this->expectException(InvalidRequestException::class);
        $notification->getTransactionStatus();
    }
}
