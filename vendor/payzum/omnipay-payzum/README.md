# Omnipay: Payzum

**[Payzum](https://payzum.com) driver for the [Omnipay](https://github.com/thephpleague/omnipay) PHP payment processing library.**

Accept crypto and stablecoin payments (USDC, USDT, BTC, ETH and more, multi-chain) on any
PHP application that speaks Omnipay. Payzum is **non-custodial**: funds settle directly to
your own wallet.

The driver is built on the official [`payzum/payzum-php`](https://github.com/payzum-dev/payzum-php)
SDK, so signature verification, retries, and amount encoding behave exactly like every other
official Payzum integration.

## Installation

```bash
composer require league/omnipay payzum/omnipay-payzum
```

Requires PHP 8.1+.

## Setup

Get your API key from **Dashboard → Settings → API Keys** at
[merchant.payzum.com](https://merchant.payzum.com). The webhook signing secret is shown once,
at merchant creation or rotation — store both outside your code.

```php
use Omnipay\Omnipay;

$gateway = Omnipay::create('Payzum');
$gateway->setApiKey(getenv('PAYZUM_API_KEY'));
$gateway->setWebhookSecret(getenv('PAYZUM_WEBHOOK_SECRET'));
// $gateway->setTestMode(true);  // sandbox — separate environment, separate API keys
```

## Take a payment

Charge in fiat terms; the buyer picks the crypto asset on the hosted checkout page.

```php
$response = $gateway->purchase([
    'amount'        => '49.99',
    'currency'      => 'USD',
    'transactionId' => $orderId,                       // your order id
    'returnUrl'     => 'https://shop.example/return',
    'cancelUrl'     => 'https://shop.example/cancel',
    'notifyUrl'     => 'https://shop.example/webhook/payzum',
])->send();

if ($response->isRedirect()) {
    // store it with the order before redirecting
    $paymentId = $response->getTransactionReference();

    $response->redirect(); // off to the hosted Payzum checkout
} else {
    echo $response->getMessage();
}
```

To pin the payment asset instead of letting the buyer choose, add
`'payCurrency' => 'usdc', 'network' => 'polygon'` (any supported asset/network pair works).
Pass `'idempotencyKey' => '...'` if you retry creation yourself, so a retry can never open a
duplicate invoice.

## Fulfil on the webhook

Crypto confirmation is asynchronous — the buyer often lands on your `returnUrl` before the
payment is final. **Fulfil from the webhook (IPN), not from the redirect.**

```php
use Omnipay\Common\Message\NotificationInterface;

$notification = $gateway->acceptNotification();

// Signature and replay-window are verified before any field is readable;
// a forged or stale delivery throws.
if (alreadyProcessed($notification->getEventId())) {
    return respond(200); // retries reuse the event id — treat repeats as no-ops
}

switch ($notification->getTransactionStatus()) {
    case NotificationInterface::STATUS_COMPLETED:
        markOrderPaid($notification->getTransactionId()); // your order id
        break;
    case NotificationInterface::STATUS_FAILED:
        markOrderExpired($notification->getTransactionId());
        break;
    default:
        // pending, partial, or an event this driver doesn't recognise —
        // record it, never credit on it
        break;
}

return respond(200);
```

## Check a payment yourself

For the "thanks" page after the redirect, or a background poll:

```php
$response = $gateway->completePurchase([
    'transactionReference' => $paymentId,   // or 'transactionId' => your order id
])->send();

if ($response->isSuccessful()) {
    // paid in full
} elseif ($response->isPending()) {
    // waiting or partially paid — show "payment in progress"
} elseif ($response->isExpired()) {
    // invoice expired before full payment
}
```

`fetchTransaction()` takes the same parameters and returns the same status mapping.

## Errors

Local problems (missing parameters) throw `\Omnipay\Common\Exception\InvalidRequestException`.
API rejections come back as an unsuccessful response — check `getMessage()` and `getCode()`.
A webhook that fails verification throws before any payload field is exposed.

## Support

- Payzum docs & dashboard: [merchant.payzum.com](https://merchant.payzum.com/docs)
- Issues with this driver: [github.com/payzum-dev/omnipay-payzum/issues](https://github.com/payzum-dev/omnipay-payzum/issues)
- General Omnipay usage: see the [Omnipay documentation](https://omnipay.thephpleague.com/)
