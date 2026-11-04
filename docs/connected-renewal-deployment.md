# Connected renewal deployment — 0.3.0

This release connects Android → AM PHP customer API → additive signed `/am/*` worker contracts. Existing reseller endpoints and Hub remain unchanged. Only renewal of the customer's current package and connection count is offered. Package/bouquet changes are handled by the reseller.

## Installation order

Download the **same pinned source revision** on both servers. The release notes provide copy/paste commands with that revision.

1. **Auth-worker server `[private worker host]`**, as root: run `python3 server/worker/deploy.py` from the extracted source. It identifies the uniquely matched running worker service and its Python environment, verifies the deployed function fingerprints, checks InnoDB transaction support, adds `am_renewal_results`, backs up `main.py`, adds the AM module, and restarts only that worker service. If discovery or validation fails, return the output; do not substitute a guessed interpreter or edit existing handlers.
2. **Website server `[private website host]`**, as root: run `python3 server/update-panel.py`. This preserves the private SQLite database, gateway encryption key and existing settings, creates a private backup, installs customer tables and API files, and does not restart PHP or change reseller website files.
3. On the website server, run `python3 server/check-customer-api.py`. It verifies installed customer tables, private key availability, and the signed worker's ownership/idempotency capability. No customer credentials or payment operations are used. The public customer endpoint is `https://fecxbrfzku.net/am-app/customer-api.php`; GET should return JSON with HTTP 405, not 404 or a login page.

Default private state remains `/www/am-app-state/fecxbrfzku.net/settings.sqlite` plus `gateway.key`. Keep these together. Worker orders, credit debit and expiry extension are committed atomically in the panel database; never delete the renewal journal to retry a payment.

## Reseller setup

At `https://fecxbrfzku.net/am-app/`, signed in as the actual reseller:

- Enable AM, choose all customers or approve individual numeric service IDs, and allow renewals.
- Choose integrated billing (or both), select Square, and initially select **Sandbox** customer checkout mode.
- Connect the matching Square merchant/location and save its webhook key. Existing sandbox setup connections are preserved.
- Set and enable the customer price for the customer's **current package**, in the merchant currency. Wholesale credits are separate and bouquets carry no extra charge. A price of 0.00 allows a free customer renewal but still deducts wholesale credits in production.
- Install the connected APK as an update. Sign in with an approved customer. Package and selected bouquet names should be real, and the renewal price should match the reseller setting.

## Sandbox acceptance

Review renewal, confirm, open Square checkout, complete the sandbox payment and return to the app. Select **Check payment / renewal** (returning to the app also refreshes outstanding orders). The result must say **sandbox test verified**, with no real credit debit or expiry change. Restart the app and confirm the original order remains in Bills. Selected-customer access and revocation should be checked with an authorized test service.

## Real renewal activation

Configure the production Square connection and matching signed webhook. Set customer checkout mode to **Production**, save, and refresh the app. New quotes display the production amount and renewal term. A real test requires the owner's authorized customer and real payment; it has not been performed by local tests.

The server verifies merchant connection, Square order reference, location, amount, currency, payment ID, completed status, captured CARD source, and absence of tips/refunds. Only then does it fulfil the immutable renewal ID. Sandbox orders remain sandbox even after a mode switch. New receipt items are exactly **Payment**, with no AM notes, descriptions or memos.

Early renewal preserves remaining time. Duplicate checkout requests, webhooks, or lost replies reuse the same order ID and cannot debit wholesale credits twice. Native refresh failures remain recoverable without repeating the debit.

## Recovery

One outstanding customer order is retained per service. A new attempt returns that original order rather than making a second payment. Use **Check payment / renewal** in the app or **Retry original order** in the reseller page. For a paid order needing review, check wholesale credit balance, customer access and package availability, then retry. Do not renew manually while an uncertain AM order is being reconciled. If the service changed after payment, provider review is required; no replacement invoice is created automatically.

Payment cancellation/refunds are managed with the reseller/Square; AM does not offer automated refunds or silently discard unpaid orders. The customer can continue an existing unpaid order. API unavailable after deployment: verify the worker install/readiness output and PHP-FPM access to the private state before changing server configuration.

## Tested locally

- Android authentication, encrypted login, denial handling, checkout URL allowlist, quote/order requests, lost checkout response retry and sign-out races.
- PHP HTTP controller, bearer sessions, input validation, ownership, reseller billing permissions, sandbox separation, exact payment verification, replay/recovery and tenant-scoped order management.
- Worker rollback, concurrent/repeated order ID, lost commit acknowledgement, native refresh repair, fixed wholesale debit, and preserved trial policy using reference helpers.
- Installer/updater syntax, backups, private permissions and preservation of existing portal files/settings/keys.

SQLite-based worker tests simulate the row-lock boundary; live InnoDB/startup, device Keystore/launcher behavior and real payment settlement are host acceptance checks. The app has a GitHub downloads/updates link, not unattended APK installation.
