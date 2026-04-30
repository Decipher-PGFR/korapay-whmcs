# Contributing

Thanks for helping improve this gateway.

## Pull Requests

1. Keep changes focused on the WHMCS gateway module.
2. Do not commit credentials, `.env` files, WHMCS configuration, logs, screenshots with customer data, server IPs, admin paths, or deployment notes.
3. Include a short description of the payment flow or security behavior affected.
4. For webhook, reconciliation, or authentication changes, include test notes showing how forged callbacks, duplicate callbacks, and amount/currency mismatches behave.

## Security Changes

Security-sensitive changes should preserve these invariants:

- Webhooks must verify the gateway signature before any payment state change.
- Webhooks must re-verify the transaction server-to-server before crediting an invoice.
- Payment amount and currency must match the WHMCS invoice.
- Duplicate transaction references must not apply payment twice.
- Gateway logs must not contain raw webhook bodies, secrets, signatures, card data, or customer PII.

## Disclosure

Report vulnerabilities privately to security@decipher.ng instead of opening a public issue.
