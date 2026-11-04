# Square setup and Hub checkout reference

## Reviewed source

Reviewed `derangedchief-crypto/ARVIO` main at `1effc23071da1556cfa1fb11c44d1b3a9e12d305`:

- `control-center/src/CardGateway.php`: Square merchant/location validation, hosted payment link creation, exact invoice matching, captured settlement, webhook HMAC verification.
- `control-center/src/RenewalSettings.php`: reseller-scoped encrypted merchant connections, provider/location validation and merchant ownership.
- `control-center/src/RenewalPayments.php` and `control-center/public/renew.html`: server-owned orders, hosted checkout handoff, return-page status checks, separation of payment and service fulfilment.

AM owns its new implementation. No Hub source, configuration, secrets or deployment is modified.

All newly created AM payment links use the receipt item name **Payment**, with no AM-supplied payment note, item note, memo or description. Technical order references remain for verification. Existing Square links/receipts keep the text they were created with; Square's standard merchant, amount and transaction details are provider-controlled. Future customer checkout integrations must retain this receipt-label rule.

## What is available now

The hidden `/am-app/` page can connect Square sandbox or production, verify its merchant and card-enabled CAD/USD location, and run one **1.00 currency-unit setup checkout per connection**. These are reseller-operated gateway tests, not customer invoices or subscriptions. Sandbox uses test money. Production checkout requires explicit real-charge confirmation and uses real money; any refund is handled in Square Dashboard.

The flow follows Hub: `POST /v2/online-checkout/payment-links`, then Square-hosted card checkout, then authoritative order/payment lookup. Payment must match the immutable order ID, location, amount and currency, with one completed, captured, unrefunded card payment and no tip. An OPEN digital Square order can be fully paid. A browser return is not payment evidence. Cards/wallets are handled by Square, with tipping, Cash App Pay and Afterpay disabled in the checkout request.

Only an authenticated, active reseller can operate its own setup. A merchant/environment cannot be claimed by another reseller. Credentials are AES-256-GCM encrypted with reseller/connection/field-associated data; a randomly generated key lives outside the webroot. Saving the same merchant/location/environment again rotates its token without replacing the connection or test invoice. Older connections remain available to reconcile their orders. Test invoices are persisted before API calls; retries use the same Square idempotency key. Ambiguous replies never trigger another invoice automatically. There is no automatic refund, cancellation or second test checkout yet.

## Entering your Square information

1. Open Square Developer Dashboard and your application. Select **Sandbox** for sandbox testing or **Production** for real-money testing.
2. Copy that environment's **access token** and **location ID** into the hidden AM page. Do not paste them into chat or GitHub. An Application ID is not required for this server-created hosted-checkout flow.
3. Click **Verify & save Square connection**. AM verifies `/v2/merchants/me` and `/v2/locations/{id}` and displays the verified merchant/location/currency.
4. Copy the exact **webhook URL** shown for the connection into a Square webhook subscription in the same application/environment. Use API version **2026-09-16**, with **payment.created** and **payment.updated** events.
5. Copy that subscription's **signature key** into AM and save it. This is not the API access token. AM shows whether a key is saved but never returns its value.
6. Create the setup checkout and open the Square link. In Sandbox use Square's **Sandbox Testing Panel** to simulate completion; this is not the production card-entry page. Return to AM and click **Check payment with Square**. A successful signed webhook can also update the status.
7. Sandbox panel payments may be reported as `EXTERNAL` without card-capture details. AM verifies the same order, location, amount, currency, zero refund and zero tip, then records **Sandbox test verified · no real payment or service action** (`sandbox_verified`), never live settlement. Production still requires `CARD / CAPTURED` and rejects external payments. Sandbox captured-card tests are also labelled test-only. The [Square forum's hosted-checkout example](https://developer.squareup.com/forums/t/how-can-i-cancel-subscription-which-was-created-by-checkout-api/20957) demonstrates the sandbox EXTERNAL response. See also [Square's explanation of its testing-panel flow](https://developer.squareup.com/forums/t/unable-to-test-payment-for-catalog-item-in-new-checkout-api-sandbox/5996).
8. Production success reads **Payment verified · no service action**. This proves payment verification, not subscription renewal. A separately shown signed-notification timestamp confirms webhook delivery. A generic Square test event may use a different merchant and be rejected; an actual checkout payment in the selected environment carries the correct merchant.

If an older installation marked a sandbox panel payment for review, update and recheck the same invoice. No new payment or connection is needed. Other verification errors now identify the failing field or source/capture enums without returning credentials, raw payment data or card details.

For scoped access tokens, required permissions are `MERCHANT_PROFILE_READ`, `ORDERS_READ`, `ORDERS_WRITE`, `PAYMENTS_READ`, and `PAYMENTS_WRITE`. The [Square CreatePaymentLink reference](https://developer.squareup.com/reference/square/checkout-api/create-payment-link) requires Orders read/write and Payments write. A personal access token grants broader access; it remains server-only.

Webhooks authenticate the exact configured public URL plus raw request body using Square's base64 HMAC-SHA256 signature. The URL is installation configuration, never the request Host header. Valid payment notifications only trigger a fresh API lookup. Duplicate events are idempotent, and a signed event from another merchant is rejected. Failed provider verification returns a retryable response. Polling manually can recover a missed notification. A lost checkout-creation reply is recovered by retrying the original checkout in AM.

## Updating an existing installation

Use the new source archive's `server/update-panel.py`, not the first-install script. The updater backs up the module and a consistent SQLite snapshot in the private state directory, preserves existing preferences and encryption keys, adds the gateway tables, and atomically replaces AM files. Existing portal files and `.user.ini` are untouched. Default public URL: `https://fecxbrfzku.net/am-app`; use `--public-url` for another host/path. Do not change this URL while Square subscriptions use the old URL.

Keep the database and `gateway.key` together in private backups. If the key is missing from an installation with gateway tables, the updater refuses to generate a replacement. Restoring a backup must restore both the matching database and key. The printed backup directory is private and outside the webroot.

## Remaining customer integration

The customer API/ownership mapping, customer prices, automatic renewal, credit/proration, and Android checkout handoff are still pending. This release does not grant customers new service permissions, create subscription invoices, or call any service-changing worker endpoint. Square setup tests have their own ledger. The gateway preference checkboxes do not by themselves make customer checkout live.

## Verification

Run `php server/tests/square_test.php`, plus the panel HTTP suite and installer/updater suite in `server/tests`. Tests cover cross-reseller access/merchant claiming, contextual encryption, duplicate submissions, lost replies, mismatched payment fields, refunds/tips/split payments, invalid signatures/wrong merchant notifications, replay, and explicit live-charge confirmation. External provider calls are mocked. Live credential validation, Square checkout and webhook delivery require the reseller's host/account acceptance test.

Theme verification uses the deployed Pirate's Fortune CSS, saved `erp-theme` preference, light/dark modes, and 360/1280-pixel widths. Text outside the service cards now has its own theme-matched surface instead of inheriting light-card text over the dark sea background.
