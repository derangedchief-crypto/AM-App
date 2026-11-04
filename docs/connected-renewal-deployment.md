# Bouquet editing, renewals and promotions — 0.4.1

## Reseller licensing and menu links

### Searchable reseller selection

Update the Auth-worker host first with `python3 server/worker/deploy.py`, then the Website host with `python3 server/update-panel.py`. The license page now provides reseller-name dropdowns with live search for issuing keys, filtering licenses and reconciling orders. No numeric ID lookup or APK update is needed. The signed read-only `/am/admin-resellers` route returns only reseller IDs, usernames and enabled status; the website search requires the existing administrator login and denies staff/impersonation sessions. Searches return up to 200 names and ask for a narrower search when more match. Issuance revalidates the selected active account. If the directory is unavailable, update the worker and reload.

After the Auth-worker update above, on the Website host run `python3 server/update-panel.py` and then `python3 server/install-menus.py` from the same source checkout. The website update adds licensing and immediately requires it for **all resellers, including existing accounts**. No grandfathered grants are generated. The menu installer adds **AM App** in the reseller menu and **AM App licenses** in the administrator Tools menu. It validates the recognized navigation layout, lints staged PHP, keeps private backups and is safe to repeat. If a menu layout is unrecognized it stops without editing those files; the direct admin URL remains available. No APK update is required.

Sign into the existing website administrator account and open `/am-app/licenses-admin.php`. Ordinary reseller sessions, staff sessions and impersonation sessions cannot manage licenses. Search by reseller name, choose the account from the dropdown and leave expiry blank for a one-time/no-expiry license. The key is shown once; deliver it privately to that reseller. Keys are random, stored only as hashes in SQLite, tied to the intended reseller and redeemed once. The reseller opens **AM App**, enters the key, and can then access management. Customers must also meet the existing reseller approval and renewal permissions.

**Suspend** blocks access reversibly. **Restore** restores the previous activation state; it does not remove an expired date. **Revoke** permanently invalidates that key. To issue a new key after revocation or loss, generate a replacement for the same reseller and explicitly confirm replacement; the prior key is revoked and activation is required again. Changing expiry can convert an existing permanent license to time-limited access, extend access or remove expiry. Dates are UTC. This is license enforcement, not automatic collection of reseller license fees: monthly payment collection and extension remain manual until billing automation is added.

License state is checked on each management/customer request, including existing sessions. Missing storage fails closed. Blocked AM access does not disable IPTV subscriptions. A customer app may require sign-in again after access is restored. Public marketing banner images and the payment-return landing page are not entitlements. Signed payment webhooks still reconcile already-created orders under their original price and ownership checks, even after suspension/expiry. An admin-only original-order reconciliation tool is provided for webhook recovery; it never creates a new purchase. License activity (issue, activate, replace, suspend, restore, revoke and expiry edits) is audited without recording raw keys. Back up the private database and gateway key together.

## Reseller package names

Update the Website host with `python3 server/update-panel.py`. In **Packages & prices**, expand a package, enter **Customer-facing package name** (for example Premium), and select **Save package name**. This form saves independently of the price form. Blank restores the original name. Search matches both original and custom names.

Names apply to that reseller's customers on that package, across currencies. They appear in account package details, renewal offers, Deals, new renewal reviews and new order history. The reseller's service brand remains separate. Existing quotes and orders retain their recorded name; future ones use the latest name. Global package names, duration, connections, wholesale credits, retail price revisions and catalogue fingerprints are unchanged. The existing app reads these names after Refresh account; no APK or worker update is required. Names are stored in the private settings database and included in normal backups.

## Active connections

Version 0.4.1 needs an Auth-worker host update (`python3 server/worker/deploy.py`) and the new APK. The website passes the worker's service details through unchanged; no additional website update is needed from 0.4.0. The installer recognizes the previously released AM module and preserves existing reseller routes.

The worker counts `connections` rows with `closed=0`, joined to the authorized customer and reseller, matching the reseller panel's online-session definition. Only the count is returned, never IPs or device/session details. Usage does not affect auth, bouquet or renewal fingerprints. A failed telemetry read returns null; old workers also lack this field. Both cases display unavailable rather than zero. Home shows connections in use with the allowed limit below, and Services includes usage separately. Counts are snapshots refreshed on account refresh or app resume, with the last updated time in the app; they are not a continuous connection feed.

## Limited-time promotions

Update the Website host with `python3 server/update-panel.py` before installing the 0.4.0 APK. This adds promotion tables and banner endpoints while preserving settings, encrypted merchant credentials and existing orders. No worker change is required from 0.3.2 or later. Back up the private SQLite database and gateway key together; promotional banner bytes live in that database, not in Git or a public uploads directory.

In **Packages & prices**, save an enabled normal retail price, expand the desired package and select **Create deal for this package**. Enter a title, description, lower promotional price and matching currency. Upload a PNG, JPEG or WebP banner (at most 1 MB and 4000 × 4000 pixels; recommended 1200 × 600). Set start/end dates in UTC, enable publication and save. **Promotions** provides a management preview, editing, disabling and paginated history. The banner endpoint is a public marketing image addressed by a random ID; it exposes no reseller credentials or customer information. There is no public customer deals webpage.

Customers see only active, currently redeemable deals matching their assigned package and connections. Renewal permission, billing setup, normal-price enablement, catalogue fingerprint and matching Square setup still apply. Deals disappear at their end time using server time plus monotonic elapsed time in the app. Refresh retrieves newly published deals. Multiple deals may coexist; the customer explicitly selects one. A normal renewal does not automatically select a promotion.

The sale window limits new checkout, not future renewal rates. Discounted quotes expire at the earlier of 15 minutes or promotion end; changes to the promotion, normal price, package or merchant invalidate checkout. An order already created retains its price and can complete after the sale ends or is disabled. Retrying checkout or payment uses that same order. Future ordinary renewals use the normal price. Reseller wholesale credits remain the package's fixed cost; package/connection changes remain reseller-managed. Sandbox promotions never renew services. Square receipt item text remains **Payment**.

## Management layout update

The AM management page now has separate Packages & prices, App settings, Customer access, Renewal check, Orders and Square setup sections. Package pricing is the default view. Search by name or ID, filter by connection count, duration, price status and currency, and sort by name, connection count or wholesale credits. Results are paginated on the server (12 packages per page). Expand a package row to edit its price; saves preserve the current filters and page. Status counts apply to the selected currency and distinguish enabled, disabled, unpriced and changed packages needing review.

Settings and price saves remain separate. Switching sections retains unsaved fields on the current page; navigating to another result page or applying filters reloads the page. Without JavaScript, all sections remain accessible and package search, filtering, paging and editing still work. Orders and merchant connections use expandable details. Install with `python3 server/update-panel.py`; no worker or APK update is required for this website layout change.

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
