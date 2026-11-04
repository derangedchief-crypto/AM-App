# Bouquet editing and connected renewal deployment — 0.3.3

This release connects Android → AM PHP customer API → additive signed `/am/*` worker contracts. Customers can edit permitted bouquets for free and renew their current package. Package/connection changes require the reseller. Existing reseller endpoints and Hub remain unchanged.

## Upgrade from 0.3.0

Update both servers using this release's pinned source revision, then install the APK. The worker installer recognizes the exact previously released AM module, backs it up alongside main.py and replaces only that module; unexpected local modifications are rejected. No new bouquet database table is required.

On the hidden AM page, enable **Add or remove permitted bouquets (free)** under Customer permissions and save. In the app, refresh → Services → choose bouquets → Save bouquets → confirm. Removing a package bouquet does not remove it from the available list: it can be added again as often as wanted. Activated no-expiry (unlimited) services can select any bouquet in the full catalogue, even without a package. An unactivated service with no expiry is not classified as unlimited. Removing all selections is permitted after an explicit warning.

Set **Service display name (shown in the app)** on the AM page to brand the subscription heading, for example Extreme TV. The package remains a separate service detail. Deploy both server updates and the new APK for this release; all public instructions intentionally omit private host IP addresses.

Bouquet saves check server-side ownership, current package permissions, credential revision and selection revision while holding the same reseller/customer locks as renewals. They change only bouquet selection and modification time, never expiry, connections, package or credits. Repeating the same selection repairs a lost reply/native refresh without charges; stale conflicting edits require refresh. Sandbox mode applies to payments only: confirmed bouquet edits are real service changes and are free.

## An enabled package price is not appearing

Version 0.3.3 fixes the generic empty-offer message. Update the website (no worker change from 0.3.2), then open **Check a service's renewal availability** on the hidden AM page. Enter the numeric customer service ID from your reseller panel. The read-only check reports the assigned package ID, connections, checkout mode and exact blocker using the same rules as the customer API. It never creates an invoice, charges a payment or renews a service.

The general **Renew or extend current subscription** permission, billing mode and gateway preferences are saved by **Save AM settings**. Each **Save package price** button saves only that package's price/offer flag. Square must have a matching checkout mode, price currency and saved webhook key. The enabled price must match the service's actual package ID and connection count; a similarly named different duration/connection variant is not the same package. Changed package details require the saved price to be reviewed again. These conditions now have separate messages in both the check and the updated app.

If a check says offers are available, refresh the app to reload settings. If it reports a blocker, resolve the named setting rather than repeatedly changing the price. A successful availability check confirms the offer can be displayed; worker credit balance and a fresh service quote are still checked before checkout.

## Installation order

Download the **same pinned source revision** on both servers. The release notes provide copy/paste commands with that revision.

1. **Auth-worker server `[private worker host]`**, as root: run `python3 server/worker/deploy.py` from the extracted source. It identifies the uniquely matched running worker service and its Python environment, verifies the deployed function fingerprints, checks InnoDB transaction support, adds `am_renewal_results`, backs up `main.py`, adds the AM module, and restarts only that worker service. If discovery or validation fails, return the output; do not substitute a guessed interpreter or edit existing handlers.
2. **Website server `[private website host]`**, as root: run `python3 server/update-panel.py`. This preserves the private SQLite database, gateway encryption key and existing settings, creates a private backup, installs customer tables and API files, and does not restart PHP or change reseller website files.
3. On the website server, run `python3 server/check-customer-api.py`. It verifies installed customer tables, private key availability, and the signed worker's ownership/idempotency capability. No customer credentials or payment operations are used. The public customer endpoint is `https://fecxbrfzku.net/am-app/customer-api.php`; GET should return JSON with HTTP 405, not 404 or a login page.

Default private state remains `/www/am-app-state/fecxbrfzku.net/settings.sqlite` plus `gateway.key`. Keep these together. Worker orders, credit debit and expiry extension are committed atomically in the panel database; never delete the renewal journal to retry a payment.

## Reseller setup

At `https://fecxbrfzku.net/am-app/`, signed in as the actual reseller:

- Enable AM, choose all customers or approve individual numeric service IDs, and allow renewals and bouquet editing.
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
