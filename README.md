# Payzum for Akaunting

Accept crypto and stablecoin payments (USDC, USDT and more, multi-chain) in
[Akaunting](https://akaunting.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody.

The app is built on Akaunting's own Omnipay layer (`app/Traits/Omnipay.php`;
the core already ships `league/omnipay`) plus the published
[`payzum/omnipay-payzum`](https://packagist.org/packages/payzum/omnipay-payzum)
driver, and follows the structure of the official
[PayPal Standard](https://github.com/akaunting/module-paypal-standard) app.

## How it works

- The customer opens an invoice in the client portal (or via the signed
  invoice link), picks **Payzum** and clicks Confirm.
- The app creates a Payzum hosted-checkout invoice and redirects the buyer to
  it. No card or wallet data ever touches your server.
- Crypto confirmation is asynchronous, so the invoice is marked as paid
  **only** from Payzum's server-to-server payment notification (IPN), whose
  HMAC-SHA-512 signature is verified over the raw request bytes (with a replay
  window) before any field is read. The browser return just shows a
  "payment is being confirmed" notice.
- The IPN endpoint itself lives on a Laravel **signed URL**, and the order id,
  amount, and currency of every notification are checked against the invoice.
  Redelivered notifications are idempotent — an invoice is never credited
  twice.

## Install

Copy this directory to `modules/Payzum` of an Akaunting ≥ 3.0 install (the
`vendor/` directory is included, no composer step needed), then enable
**Payzum** from the admin panel and open its settings:

| Setting | Meaning |
|---|---|
| API Key | From your Payzum merchant dashboard |
| Webhook Secret | Used to verify payment notifications |
| Mode | Live, or Sandbox for the Payzum staging environment |
| Pay Currency | Optional: pin the crypto asset (e.g. `usdc`); empty lets the buyer choose |
| Account | The Akaunting account payments are recorded into |
| Show to Customer | Whether portal customers see the method |

## Tests

The suite runs inside the Akaunting test harness (it covers the signed-IPN
flow end to end — forged signatures, order/amount mismatches, pending states,
and redelivery idempotency):

```bash
git clone https://github.com/akaunting/akaunting && cd akaunting
cp .env.testing .env
git clone https://github.com/payzum-dev/akaunting-payzum modules/Payzum
(cd modules/Payzum && composer test) && composer test
php artisan test modules/Payzum/Tests
```

## License

GPL-3.0+ (same as Akaunting). The Omnipay driver and the Payzum PHP SDK it
wraps are MIT.
