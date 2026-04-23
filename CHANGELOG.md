# Changelog

All notable changes to the Korapay WHMCS gateway module.

## [1.0.0] - 2026-04-23

First public release. Production-tested.

### Security hardening (S-1 through S-9)

- S-1: HTTPS enforcement at .htaccess level
- S-2: Depth-counted brace matcher for HMAC data extraction (avoids PHP serialize_precision float corruption)
- S-3: Log sanitization. No raw body, PII, card data, or signature values in any log entry
- S-4: Currency check resolved through tblclients.currency > tblcurrencies join (not webhook payload alone)
- S-5: Exact-amount enforcement always on, no admin toggle
- S-6: CURLOPT_SSL_VERIFYPEER and CURLOPT_SSL_VERIFYHOST pinned on all cURL calls
- S-7: session_write_close() at top of webhook handler
- S-8: Idempotency guard (tblaccounts pre-check) on top of checkCbTransID
- S-9: Clear variable naming (invoiceTotal, not invoiceBalance)

### Features

- Checkout Redirect flow (PCI SAQ-A compliant)
- HMAC SHA256 webhook signature verification
- Server-side charge re-verification via Korapay API
- Dual-layer NGN-only currency gate (template + redirect)
- Ownership check on redirect (session uid vs invoice userid)
- Clean separation: redirect handler vs webhook receiver

### Test coverage

- TEST-MATRIX 7/7 phases passed:
  - Phase 1: Happy path (charge, webhook, payment applied)
  - Phase 2: Failure modes (declined card, abandon, unreachable gateway)
  - Phase 3: Security/tamper (no sig, wrong sig, replay, amount tamper, ref tamper)
  - Phase 5: Multi-currency defense (USD invoice blocked at both layers)
  - Phase 6: Edge cases (brace matcher, log sanitization, HMAC forge harness)
