# Changelog

## 0.1.0 — 2026-09-01

Initial release.

- `purchase()` — create a hosted-checkout invoice and redirect the buyer.
- `completePurchase()` / `fetchTransaction()` — read an invoice back by
  Payzum payment id or by your own order id, with statuses mapped onto
  Omnipay semantics.
- `acceptNotification()` — signature-verified IPN handling with replay
  protection and an event id for deduplication.
- Test mode targets the Payzum sandbox environment.
- Built on the official `payzum/payzum-php` SDK.
