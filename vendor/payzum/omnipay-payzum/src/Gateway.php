<?php

declare(strict_types=1);

namespace Omnipay\Payzum;

use Omnipay\Common\AbstractGateway;
use Omnipay\Payzum\Message\Request\AcceptNotificationRequest;
use Omnipay\Payzum\Message\Request\CompletePurchaseRequest;
use Omnipay\Payzum\Message\Request\FetchTransactionRequest;
use Omnipay\Payzum\Message\Request\PurchaseRequest;

/**
 * Payzum gateway for Omnipay.
 *
 * Payzum is a non-custodial crypto/stablecoin payment gateway: buyers pay in
 * USDC/USDT or other supported assets on a hosted checkout page, and funds
 * settle to the merchant's own wallet.
 *
 * The flow is redirect-based, like other offsite Omnipay gateways:
 *
 *     $gateway = Omnipay::create('Payzum');
 *     $gateway->setApiKey($apiKey);
 *
 *     $response = $gateway->purchase([
 *         'amount'        => '49.99',
 *         'currency'      => 'USD',
 *         'transactionId' => 'ORDER-1001',
 *         'returnUrl'     => 'https://example.com/return',
 *         'cancelUrl'     => 'https://example.com/cancel',
 *         'notifyUrl'     => 'https://example.com/webhook/payzum',
 *     ])->send();
 *
 *     if ($response->isRedirect()) {
 *         $response->redirect(); // hosted Payzum checkout
 *     }
 *
 * Test mode (`setTestMode(true)`) points at the Payzum sandbox, which has its
 * own isolated data and its own API keys.
 *
 * @method \Omnipay\Common\Message\RequestInterface authorize(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface completeAuthorize(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface capture(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface refund(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface void(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface createCard(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface updateCard(array $options = [])
 * @method \Omnipay\Common\Message\RequestInterface deleteCard(array $options = [])
 */
class Gateway extends AbstractGateway
{
    public function getName(): string
    {
        return 'Payzum';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultParameters(): array
    {
        return [
            'apiKey' => '',
            'webhookSecret' => '',
            'payCurrency' => 'all',
            'testMode' => false,
        ];
    }

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

    /**
     * The IPN signing secret — separate from the API key, shown once at
     * merchant creation or rotation. Required only for acceptNotification().
     */
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

    /**
     * Asset the buyer pays with: a Payzum currency code, or "all" (default)
     * to let the buyer choose on the hosted checkout page.
     */
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

    /** Network hint when payCurrency is a bare symbol (e.g. "usdc" + "polygon"). */
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
     * Create a hosted-checkout invoice and redirect the buyer to it.
     *
     * @param array<string, mixed> $parameters
     */
    public function purchase(array $parameters = []): PurchaseRequest
    {
        /** @var PurchaseRequest */
        return $this->createRequest(PurchaseRequest::class, $parameters);
    }

    /**
     * Check the invoice after the buyer returns from the hosted checkout.
     *
     * Pass `transactionReference` (the Payzum payment id) or `transactionId`
     * (your order id) — the API resolves either.
     *
     * @param array<string, mixed> $parameters
     */
    public function completePurchase(array $parameters = []): CompletePurchaseRequest
    {
        /** @var CompletePurchaseRequest */
        return $this->createRequest(CompletePurchaseRequest::class, $parameters);
    }

    /**
     * Fetch an invoice by Payzum payment id or by your own order id.
     *
     * @param array<string, mixed> $parameters
     */
    public function fetchTransaction(array $parameters = []): FetchTransactionRequest
    {
        /** @var FetchTransactionRequest */
        return $this->createRequest(FetchTransactionRequest::class, $parameters);
    }

    /**
     * Handle an incoming Payzum IPN webhook.
     *
     * Verifies the HMAC signature against the raw request body before any
     * field is exposed; accessing the notification throws if the signature or
     * the replay-window check fails.
     *
     * @param array<string, mixed> $parameters
     */
    public function acceptNotification(array $parameters = []): AcceptNotificationRequest
    {
        /** @var AcceptNotificationRequest */
        return $this->createRequest(AcceptNotificationRequest::class, $parameters);
    }
}
