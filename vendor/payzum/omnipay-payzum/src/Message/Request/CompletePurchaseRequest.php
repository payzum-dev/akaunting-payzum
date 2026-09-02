<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Request;

use Omnipay\Payzum\Message\Response\CompletePurchaseResponse;

/**
 * Check the invoice when the buyer lands back on the returnUrl.
 *
 * The redirect back is not proof of payment — crypto confirmation is
 * asynchronous, so a just-returned buyer is often still `waiting`. Treat the
 * IPN (acceptNotification) as the source of truth for fulfilment and this
 * call as the way to render the right "thanks / pending" page.
 *
 * @method CompletePurchaseResponse send()
 */
class CompletePurchaseRequest extends FetchTransactionRequest
{
    /**
     * @param array<string, mixed> $data
     */
    protected function createResponse(array $data): CompletePurchaseResponse
    {
        return new CompletePurchaseResponse($this, $data);
    }
}
