<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Response;

use Omnipay\Common\Message\AbstractResponse;
use Payzum\PaymentStatus;

/**
 * An invoice read back from the API, with its status mapped onto Omnipay
 * semantics via the SDK's canonical PaymentStatus enum.
 *
 * An unknown status (a contract change newer than this driver) maps to
 * "not successful, not pending": the one thing that must never happen is
 * crediting an order on a status this code does not understand.
 */
class FetchTransactionResponse extends AbstractResponse
{
    /** Paid in full — safe to fulfil. */
    public function isSuccessful(): bool
    {
        return $this->status()?->isPaid() ?? false;
    }

    /** Still waiting for (full) payment; poll again or wait for the IPN. */
    public function isPending(): bool
    {
        $status = $this->status();

        return $status !== null && !$status->isTerminal();
    }

    /** The invoice expired before full payment arrived. */
    public function isExpired(): bool
    {
        return $this->status() === PaymentStatus::Expired;
    }

    /** Cancelled invoices surface as `failed` on the merchant API. */
    public function isCancelled(): bool
    {
        return $this->status() === PaymentStatus::Failed;
    }

    /** Canonical status, or null when the API sent something unknown. */
    public function status(): ?PaymentStatus
    {
        $raw = $this->getPaymentStatus();

        return $raw === null ? null : PaymentStatus::tryFrom($raw);
    }

    /** The raw `payment_status` string as the API sent it. */
    public function getPaymentStatus(): ?string
    {
        $status = $this->data['payment_status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /** The Payzum payment id. */
    public function getTransactionReference(): ?string
    {
        $id = $this->data['payment_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The merchant's own order id, as sent at purchase time. */
    public function getTransactionId(): ?string
    {
        $id = $this->data['order_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function getMessage(): ?string
    {
        if (isset($this->data['error'])) {
            return $this->data['error'];
        }

        $raw = $this->getPaymentStatus();
        if ($raw !== null && $this->status() === null) {
            return sprintf('Unknown Payzum payment_status "%s" — not treating it as paid.', $raw);
        }

        return null;
    }

    public function getCode(): ?string
    {
        return $this->data['code'] ?? null;
    }
}
