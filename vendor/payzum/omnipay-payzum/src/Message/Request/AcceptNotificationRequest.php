<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Request;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Message\NotificationInterface;
use Payzum\Errors\PayzumException;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

/**
 * An incoming Payzum payment IPN.
 *
 * The HMAC signature is verified against the raw request bytes before any
 * field is exposed; every accessor throws InvalidRequestException when the
 * signature, timestamp freshness, or payload shape is wrong. There is no way
 * to read an unverified payload through this class.
 *
 * Deliveries are retried by the gateway, so process them idempotently: use
 * getEventId() to deduplicate — a second delivery with the same id must be a
 * no-op, not a second fulfilment.
 *
 *     $notification = $gateway->acceptNotification();
 *     if ($notification->getTransactionStatus() === NotificationInterface::STATUS_COMPLETED) {
 *         // mark the order paid (idempotently)
 *     }
 */
class AcceptNotificationRequest extends AbstractPayzumRequest implements NotificationInterface
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    /**
     * Verify the signature and return the decoded IPN payload.
     *
     * @return array<string, mixed>
     * @throws InvalidRequestException
     */
    public function getData(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $this->validate('webhookSecret');

        try {
            $this->payload = $this->verifier()->verifyPaymentIpn(
                (string) $this->httpRequest->getContent(),
                $this->httpRequest->headers->all(),
            );
        } catch (PayzumException $e) {
            // SignatureException (forged/stale/malformed) and any other SDK
            // rejection: nothing about this delivery is trustworthy.
            throw new InvalidRequestException('Payzum IPN rejected: ' . $e->getMessage(), 0, $e);
        }

        return $this->payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return $this
     */
    public function sendData($data): self
    {
        return $this;
    }

    /** The Payzum payment id this IPN is about. */
    public function getTransactionReference(): ?string
    {
        $id = $this->getData()['payment_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The merchant's own order id, as sent at purchase time. */
    public function getTransactionId(): ?string
    {
        $id = $this->getData()['order_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Omnipay status for this delivery.
     *
     * Only `finished` completes. Anything unknown — including event families
     * this driver predates — maps to pending, because the failure mode that
     * must never exist is crediting an order on an unrecognised payload.
     */
    public function getTransactionStatus(): string
    {
        return match ($this->status()) {
            PaymentStatus::Finished => NotificationInterface::STATUS_COMPLETED,
            PaymentStatus::Expired, PaymentStatus::Failed => NotificationInterface::STATUS_FAILED,
            default => NotificationInterface::STATUS_PENDING,
        };
    }

    public function getMessage(): ?string
    {
        $raw = $this->getPaymentStatus();
        if ($raw !== null && $this->status() === null) {
            return sprintf('Unknown Payzum payment_status "%s" — not treating it as paid.', $raw);
        }

        return $raw;
    }

    /**
     * Whether this delivery reports a payment completed in full (`finished`).
     *
     * Not part of Omnipay's NotificationInterface, but real consumers (e.g.
     * CiviCRM's omnipaymultiprocessor) call isSuccessful() on the object that
     * acceptNotification()->send() returns, so the alias is load-bearing.
     */
    public function isSuccessful(): bool
    {
        return $this->getTransactionStatus() === NotificationInterface::STATUS_COMPLETED;
    }

    /** Canonical status, or null when the payload carries something unknown. */
    public function status(): ?PaymentStatus
    {
        $raw = $this->getPaymentStatus();

        return $raw === null ? null : PaymentStatus::tryFrom($raw);
    }

    /** The raw `payment_status` string from the verified payload. */
    public function getPaymentStatus(): ?string
    {
        $status = $this->getData()['payment_status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Delivery id for deduplication. Retries reuse it — treat a repeat as a
     * no-op.
     */
    public function getEventId(): ?string
    {
        $this->getData(); // signature first; never expose headers of a forged delivery

        return $this->verifier()->eventId($this->httpRequest->headers->all());
    }

    private function verifier(): Verifier
    {
        return new Verifier((string) $this->getWebhookSecret());
    }
}
