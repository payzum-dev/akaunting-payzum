# Changelog

All notable changes to the Payzum app for Akaunting are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] — 2026-09-03

### Fixed
- An invoice that already had a partial payment was charged for the outstanding balance, but the
  payment event was recorded for the **full invoice total**. Akaunting rejected that as an
  over-payment and discarded it without a word: the money had arrived, the invoice stayed unpaid,
  and the `200` response told Payzum the delivery had succeeded so it never retried. The amount
  actually charged is now the amount recorded.
- Two notifications arriving at once could both post a payment. Crediting now runs under a lock on
  the document, so only one gets through.
- If the books did not actually move, the webhook now answers `500` so Payzum retries, instead of
  reporting success for a payment that was never booked.
- A failure sending Akaunting's own "payment received" email rolled back a payment that had already
  been posted. Email delivery no longer decides whether the payment stands.
