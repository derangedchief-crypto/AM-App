# PayPal, Stripe and Rampex — My TV 0.7.0

My TV supports reseller-owned Square, Stripe, PayPal and Rampex hosted checkout. Customers select a configured gateway for their current-package renewal. The server fixes price, currency, merchant, environment and ownership in the quote/order. A browser redirect never proves payment. Test/sandbox payments never renew service.

## Deploy

Install My TV 0.7.0 (code 19) and update the Website host. No Auth-worker change is required from the current renewal deployment. Older apps understand Square only; update customers before enabling the additional gateways for them.

Run as root in the Website host terminal:

```bash
set -eu
work=$(mktemp -d /tmp/my-tv-payments.XXXXXX)
curl -fL https://github.com/derangedchief-crypto/AM-App/archive/refs/tags/v0.7.0.tar.gz -o "$work/source.tar.gz"
tar -xzf "$work/source.tar.gz" -C "$work"
python3 "$work/AM-App-0.7.0/server/update-panel.py" \
  --site /www/wwwroot/fecxbrfzku.net \
  --state /www/am-app-state/fecxbrfzku.net \
  --php /www/server/php/84/bin/php
```

The updater backs up SQLite, adds gateway connection/ownership/quote/transaction tables, and preserves existing orders, Square configuration and private encryption keys. The existing Payment gateways menu uses the former Square setup section; no menu installer is needed for this label change.

## Reseller configuration

1. Open **Applications → My TV → Payment gateways** while signed in as the reseller. Staff and impersonation sessions cannot configure merchants.
2. Expand the provider, select its environment and the price currency (CAD or USD), then enter credentials privately. Connect each currency you sell. Secrets are encrypted with the existing private gateway key and are never shown back to the browser or app.
3. Copy the saved connection's exact webhook URL into that provider's dashboard. Save the webhook signing secret (Stripe/Rampex) or webhook ID (PayPal).
4. Enable that connection. This replaces the active connection for that provider/currency for **new** orders; existing orders retain the original connection and environment.
5. Under **App settings**, enable renewals, select Integrated billing or Both, select the provider checkboxes and save. Save an enabled current-package renewal price separately under **Packages & prices**.

Reconnecting the same verified merchant/environment/currency rotates its secret while preserving its connection ID. A different PayPal REST app creates a separate connection. Keep old accounts/app credentials valid until their outstanding orders reconcile. Disabling a connection stops new checkouts without preventing original-order reconciliation. The Square mode selector applies to Square and the legacy no-charge renewal mode; each added gateway uses its saved connection's environment. Rampex is always live.

### Stripe

- Use your account's full `sk_test_…` or `sk_live_…` secret key from Stripe Developers → API keys. The key's environment must match the connection; the account must have charges enabled.
- Create a webhook endpoint for `checkout.session.completed`, `checkout.session.async_payment_succeeded` and `checkout.session.expired`, API version **2026-10-28.endive**. Save its `whsec_…` signing secret.
- Checkout uses a one-time hosted card payment with receipt item **Payment**. The server checks the session reference, amount, currency and environment, successful PaymentIntent, captured charge, refunds and disputes before fulfilment.
- Signatures are checked against the raw request body with a five-minute timestamp tolerance. Webhooks trigger authenticated API reads rather than trusting event amounts.

### PayPal

- Use a business account's REST app client ID and secret from PayPal Developer → Apps & Credentials in the chosen sandbox/live environment, plus its merchant ID.
- Connection verification creates an **unapproved 1.00 USD order** to identify the actual payee. It does not expose an approval link or capture money. A mismatched merchant ID is rejected.
- Add the connection's webhook URL to the same REST app for `CHECKOUT.ORDER.APPROVED` and `PAYMENT.CAPTURE.COMPLETED`. Save the webhook **ID**, not an API secret, in the connection's webhook field.
- Customers approve on PayPal. The signed approval event or **Check payment / renewal** triggers a server-side capture using the original order's stable request ID. Only a completed final capture of the exact amount/currency under the expected payee/order renews service.
- Signature verification is performed through PayPal's authenticated `verify-webhook-signature` API. No callback-supplied certificate URL is fetched by this server.

### Rampex

- Use the Merchant API key from Rampex Settings → API Keys. Configure the merchant's payout wallet on Rampex first.
- Rampex has **no sandbox**. All links are live; customers must supply an email and meet the chosen hosted provider's minimum amount/region rules.
- Configure the connection's webhook URL under Rampex Settings → Webhooks and save the HMAC signing secret for `payment.completed`.
- Links are created through `/api-create-payment-link`; authenticated `/api-list-payment-links` reads reconcile the exact link and internal order description. A completed payment, matching price/currency, full received amount and paid timestamp are required. Ambiguous, partial or mismatched settlement stays in review rather than guessing at fees/conversion or extending service.
- The published Merchant API does not document a creation idempotency key. My TV records the attempt before making the request, serializes creation and recovers an uncertain result by its unique order description. It does **not** blindly POST another payment link after a timeout. If no matching link can be found, keep the original order and reconcile with the provider; do not create a second customer payment.

## Recovery and verification

Gateway IDs and request references originate on the server. One active order per line prevents a second gateway from creating a parallel charge during recovery. Settled payment IDs are unique and scoped to provider/environment/merchant. Payment verification is persisted before the existing idempotent worker renewal. Credential changes, licensing changes, replayed webhooks or lost acknowledgements do not authorize a second renewal.

Stripe and PayPal creation retries reuse the original idempotency key only within a conservative provider retention window (23 hours / 5 hours). Beyond that, uncertain orders remain for reconciliation. Provider declines can be retried on the original order where the response proves that no link was created. Expired/abandoned checkouts remain attached to their original order for review rather than silently charging through another gateway. Refunds and disputes after fulfilment are handled in the provider dashboard; this release does not automatically revoke IPTV service.

Automated coverage uses synthetic provider transports: tenant isolation, merchant ownership, encrypted credentials, provider/URL restrictions, lost creation replies, underpayment/mismatched settlement, PayPal capture, sandbox isolation, signed webhook replay, disabled-connection reconciliation and exactly-once renewal. Android tests cover provider selection, Rampex email handling, server-owned prices and gateway/environment URL allowlists. Existing Square, customer API, installers and browser tests remain applicable.

**Live acceptance is still required with each merchant account.** Enter keys in the website, not chat. Complete Stripe and PayPal sandbox renewals first (no service change), then an authorized live renewal with each enabled provider. Check that the signed webhook reaches the website, the original order shows payment verified, and expiry extends once. For Rampex, confirm the merchant's actual amount/currency/received-amount response matches the published contract before offering it broadly; no real payment has been run from this development environment.

## Provider references

- Stripe: https://docs.stripe.com/api/checkout/sessions/create and https://docs.stripe.com/webhooks/signature
- PayPal: https://developer.paypal.com/docs/api/orders/v2/ and https://developer.paypal.com/docs/api/webhooks/v1/
- Rampex: https://rampex.io/api-docs ; creation response verified against its published WordPress plugin at https://plugins.svn.wordpress.org/rampex-io-payment-gateway/trunk/includes/class-rampex-gateway-hosted.php

These are independent My TV adapters; no Hub code, settings or deployed payment routes are modified.
