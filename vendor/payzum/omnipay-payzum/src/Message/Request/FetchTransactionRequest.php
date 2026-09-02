<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Request;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Payzum\Message\Response\FetchTransactionResponse;
use Payzum\Errors\ApiException;
use Payzum\Errors\PayzumException;
use Payzum\Http\TransportException;

/**
 * Fetch one invoice, by Payzum payment id (`transactionReference`) or by the
 * merchant's own order id (`transactionId`) — the API resolves either, so no
 * mapping table is needed on the integrator's side.
 *
 * @method FetchTransactionResponse send()
 */
class FetchTransactionRequest extends AbstractPayzumRequest
{
    /**
     * @return array<string, string>
     * @throws InvalidRequestException
     */
    public function getData(): array
    {
        $this->validate('apiKey');

        $reference = $this->getTransactionReference() ?: $this->getTransactionId();
        if ($reference === null || $reference === '') {
            throw new InvalidRequestException(
                'Either transactionReference (Payzum payment id) or transactionId (your order id) is required.',
            );
        }

        return ['reference' => (string) $reference];
    }

    /**
     * @param array<string, string> $data
     */
    public function sendData($data): FetchTransactionResponse
    {
        try {
            $payment = $this->payzum()->payments->get($data['reference']);
        } catch (ApiException $e) {
            return $this->response = $this->createResponse([
                'error' => $e->getMessage(),
                'code' => $e->rawCode,
            ]);
        } catch (TransportException $e) {
            // Network failure: state unknown — an exception, not a status.
            throw $e;
        } catch (PayzumException $e) {
            // Client-side misuse (e.g. malformed API key), caught locally.
            throw new InvalidRequestException($e->getMessage(), 0, $e);
        }

        return $this->response = $this->createResponse($payment);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function createResponse(array $data): FetchTransactionResponse
    {
        return new FetchTransactionResponse($this, $data);
    }
}
