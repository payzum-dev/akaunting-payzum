<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Message\Response;

/**
 * Same shape as a fetched invoice; a separate class so type hints distinguish
 * "buyer just returned" from a background poll.
 */
class CompletePurchaseResponse extends FetchTransactionResponse
{
}
