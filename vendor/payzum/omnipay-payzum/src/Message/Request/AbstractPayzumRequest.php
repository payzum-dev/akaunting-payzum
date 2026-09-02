<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Request;

use Omnipay\Common\Message\AbstractRequest;
use Payzum\Http\Transport;
use Payzum\Payzum as PayzumSdk;

/**
 * Common plumbing for all Payzum requests.
 *
 * The driver delegates every API call to the official payzum/payzum-php SDK
 * instead of re-implementing HTTP: the SDK owns the exact JSON encoding the
 * API requires (amounts as JSON numbers, not strings), retry/idempotency
 * semantics, and webhook signature verification. This driver only adapts the
 * Omnipay interface onto it.
 */
abstract class AbstractPayzumRequest extends AbstractRequest
{
    public function getApiKey(): ?string
    {
        return $this->getParameter('apiKey');
    }

    /**
     * @return $this
     */
    public function setApiKey(?string $value): self
    {
        return $this->setParameter('apiKey', $value);
    }

    public function getWebhookSecret(): ?string
    {
        return $this->getParameter('webhookSecret');
    }

    /**
     * @return $this
     */
    public function setWebhookSecret(?string $value): self
    {
        return $this->setParameter('webhookSecret', $value);
    }

    public function getPayCurrency(): ?string
    {
        return $this->getParameter('payCurrency');
    }

    /**
     * @return $this
     */
    public function setPayCurrency(?string $value): self
    {
        return $this->setParameter('payCurrency', $value);
    }

    public function getNetwork(): ?string
    {
        return $this->getParameter('network');
    }

    /**
     * @return $this
     */
    public function setNetwork(?string $value): self
    {
        return $this->setParameter('network', $value);
    }

    /**
     * Idempotency key for invoice creation, so a network retry cannot create
     * a duplicate invoice. Optional; pass one when you retry yourself.
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->getParameter('idempotencyKey');
    }

    /**
     * @return $this
     */
    public function setIdempotencyKey(?string $value): self
    {
        return $this->setParameter('idempotencyKey', $value);
    }

    /**
     * Test seam: inject a \Payzum\Http\Transport to run without the network.
     */
    public function getTransport(): ?Transport
    {
        return $this->getParameter('transport');
    }

    /**
     * @return $this
     */
    public function setTransport(?Transport $value): self
    {
        return $this->setParameter('transport', $value);
    }

    /**
     * SDK entry point for this request, honouring Omnipay's testMode flag.
     * The sandbox is a separate environment with separate API keys.
     */
    protected function payzum(): PayzumSdk
    {
        return new PayzumSdk(
            (string) $this->getApiKey(),
            $this->getTestMode() ? PayzumSdk::SANDBOX_URL : PayzumSdk::BASE_URL,
            $this->getTransport(),
        );
    }
}
