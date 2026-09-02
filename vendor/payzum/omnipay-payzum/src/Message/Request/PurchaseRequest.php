<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Request;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Payzum\Message\Response\PurchaseResponse;
use Payzum\Errors\ApiException;
use Payzum\Errors\PayzumException;
use Payzum\Http\TransportException;

/**
 * Create a Payzum hosted-checkout invoice.
 *
 * The amount is the fiat price of the order (`amount` + `currency`, the
 * standard Omnipay parameters); the buyer picks the crypto asset on the
 * hosted page unless `payCurrency` pins one.
 *
 * @method PurchaseResponse send()
 */
class PurchaseRequest extends AbstractPayzumRequest
{
    /**
     * @return array<string, mixed>
     * @throws \Omnipay\Common\Exception\InvalidRequestException
     */
    public function getData(): array
    {
        $this->validate('apiKey', 'amount', 'currency');

        return array_filter([
            'priceAmount' => $this->getAmount(),
            'priceCurrency' => strtolower($this->getCurrency()),
            'payCurrency' => $this->getPayCurrency() ?: 'all',
            'network' => $this->getNetwork(),
            'orderId' => $this->getTransactionId(),
            'orderDescription' => $this->getDescription(),
            'ipnCallbackUrl' => $this->getNotifyUrl(),
            'successUrl' => $this->getReturnUrl(),
            'cancelUrl' => $this->getCancelUrl(),
            'idempotencyKey' => $this->getIdempotencyKey(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendData($data): PurchaseResponse
    {
        try {
            $invoice = $this->payzum()->payments->create(
                priceAmount: $data['priceAmount'],
                priceCurrency: $data['priceCurrency'],
                payCurrency: $data['payCurrency'],
                orderId: $data['orderId'] ?? null,
                orderDescription: $data['orderDescription'] ?? null,
                network: $data['network'] ?? null,
                ipnCallbackUrl: $data['ipnCallbackUrl'] ?? null,
                successUrl: $data['successUrl'] ?? null,
                cancelUrl: $data['cancelUrl'] ?? null,
                idempotencyKey: $data['idempotencyKey'] ?? null,
            );
        } catch (ApiException $e) {
            // API-level rejection (auth, validation, limits): a failed
            // response the caller can inspect, per Omnipay convention.
            return $this->response = new PurchaseResponse($this, [
                'error' => $e->getMessage(),
                'code' => $e->rawCode,
            ]);
        } catch (TransportException $e) {
            // No response came back — the server's state is unknown, so this
            // must surface as an exception, not as a "failed" payment.
            throw $e;
        } catch (PayzumException $e) {
            // Client-side misuse caught before any request went out
            // (malformed API key, invalid amount): Omnipay's exception type.
            throw new InvalidRequestException($e->getMessage(), 0, $e);
        }

        return $this->response = new PurchaseResponse($this, $invoice);
    }
}
