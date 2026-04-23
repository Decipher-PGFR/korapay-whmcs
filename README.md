# Korapay Payment Gateway for WHMCS

A WHMCS Third Party Gateway module for [Korapay](https://korapay.com), built because Korapay does not ship an official WHMCS module.

Version: **v1.0.0**. Production-tested. TEST-MATRIX 7/7 PASS.

## What this does

Lets a WHMCS customer pay an invoice using Korapay's hosted Checkout Redirect flow.

1. Customer clicks **Pay with Korapay** on their invoice.
2. WHMCS calls Korapay server-to-server to initialize a charge.
3. Customer is redirected to Korapay's hosted checkout page (no card data on your side, PCI scope stays at SAQ-A).
4. Customer completes payment.
5. Korapay POSTs a webhook to your callback endpoint with the result.
6. The module verifies the webhook signature (HMAC SHA256), re-verifies the charge server-to-server, reconciles against the invoice, then calls `addInvoicePayment`.

Authoritative confirmation is always the webhook + server re-verify. The browser redirect back to WHMCS is UX only.

## Scope (MVP)

This module covers the core payment flow. It does **not** include:

- **Refunds from within WHMCS.** Refund manually from the Korapay dashboard, then mark the invoice refunded in WHMCS.
- **Card-on-file / tokenization / recurring billing.** Invoices are paid individually.
- **Multi-currency.** NGN only. The module refuses non-NGN invoices at both the template layer and the redirect handler.
- **Partial payments.** Exact-amount enforcement is always on.

## Files

```
modules/gateways/korapay.php                       # Module config + pay button
modules/gateways/callback/korapay_redirect.php     # Click handler (creates charge, redirects to checkout)
modules/gateways/callback/korapay.php              # Webhook receiver (verifies + applies payment)
.htaccess                                          # HTTPS enforcement (deploy to WHMCS doc root)
```

Drop the `modules/` directory into your WHMCS install at the matching paths.

**Why two callback files:** The redirect endpoint fires on customer click and creates a fresh charge server-to-server. The webhook endpoint fires asynchronously from Korapay and is the only authoritative source of payment confirmation. Keeping them separate keeps each file's responsibility clean.

## Installation

1. Copy the three PHP files into your WHMCS document root:
   ```
   modules/gateways/korapay.php
   modules/gateways/callback/korapay.php
   modules/gateways/callback/korapay_redirect.php
   ```

2. Optionally deploy the `.htaccess` to your WHMCS document root for HTTPS enforcement.

3. In WHMCS admin: **Setup > Payments > Payment Gateways > All Payment Gateways** > click **Korapay (Decipher integration)** > **Activate**.

4. Fill in config fields:
   - **Public Key** from your Korapay dashboard (`pk_live_...` or `pk_test_...`)
   - **Secret Key** from your Korapay dashboard (`sk_live_...` or `sk_test_...`)
   - **Test Mode** check this when using test keys

5. In your Korapay dashboard > Settings > API Configuration > Notification URLs, set the Webhook URL:
   ```
   https://your-whmcs-domain.com/modules/gateways/callback/korapay.php
   ```
   Korapay has a single webhook URL. The module filters to `charge.success` and 200-OKs everything else.

6. Run a test transaction to confirm the full flow.

## Security

- **Secret key** never leaves WHMCS. Never exposed to the browser. Doubles as both the API auth credential and the webhook HMAC signing key (Korapay does not issue a separate webhook secret).
- **Webhook signature** is HMAC SHA256 of the `data` field with the Secret Key, delivered in the `x-korapay-signature` header. Verified with `hash_equals` (no timing leaks). Data extraction uses a depth-counted brace matcher to avoid PHP float-precision corruption from `serialize_precision`.
- **Server-side re-verify** after signature check. We call `GET /charges/:reference` with the Secret Key and confirm `status=success` before crediting. SSL verification is pinned.
- **Exact amount** rule blocks partial-payment attacks. Always on, no toggle.
- **Duplicate protection** via WHMCS `checkCbTransID` + an idempotency guard. Same reference cannot be applied twice.
- **Session isolation** closes the WHMCS session immediately in the webhook handler to prevent session table bloat.
- **Currency gate** is dual-layer: template hides the Pay button for non-NGN invoices, and the redirect handler blocks checkout initialization. Both layers resolve the invoice currency through the `tblclients.currency > tblcurrencies` join.
- **Log sanitization** ensures no raw body, card data, customer PII, or signature values appear in any log entry.

## Known limits / future work

- Dispute/chargeback webhooks not handled (future Korapay feature).
- No idempotency key sent on `/charges/initialize` (Korapay's endpoint is naturally idempotent on `reference`).
- Fee amount not pulled from webhook. `addInvoicePayment` fee is `0`. Reconcile manually.
- No structured alerting when a webhook is rejected (entries land in WHMCS Gateway Log only).

## Requirements

- WHMCS 8.x
- PHP 7.4+
- A Korapay merchant account with API keys
- cURL extension enabled
- SSL certificate on your WHMCS domain

## Reference

- [Korapay API docs](https://docs.korapay.com/)
- [WHMCS gateway dev docs](https://developers.whmcs.com/payment-gateways/third-party-gateway/)
- [Korapay webhook reference](https://docs.korapay.com/docs/webhook)

## Author

Built and maintained by [Decipher Media Solutions LTD](https://decipher.ng).

Korapay is the payment processor. This module is an independent integration, not affiliated with or endorsed by Korapay.

## License

MIT. See [LICENSE](LICENSE).
