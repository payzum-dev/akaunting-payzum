<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Response;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RedirectResponseInterface;

/**
 * Response to invoice creation: a redirect to the hosted checkout.
 *
 * isSuccessful() is false by design — offsite gateways only succeed after the
 * buyer pays, which is reported by the IPN or completePurchase(), never by
 * the create call. Check isRedirect() and send the buyer on their way.
 */
class PurchaseResponse extends AbstractResponse implements RedirectResponseInterface
{
    public function isSuccessful(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return $this->getRedirectUrl() !== null;
    }

    public function getRedirectUrl(): ?string
    {
        $url = $this->data['invoice_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    /**
     * @return array<string, mixed>
     */
    public function getRedirectData(): array
    {
        return [];
    }

    /** The Payzum payment id — persist it with the order. */
    public function getTransactionReference(): ?string
    {
        $id = $this->data['payment_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }

    public function getCode(): ?string
    {
        return $this->data['code'] ?? null;
    }
}
