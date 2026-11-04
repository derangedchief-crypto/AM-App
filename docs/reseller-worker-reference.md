# Reseller worker reference for AM services

## Source and scope

The owner supplied `derangedchief-crypto/Reseller-website` as the reference for working panel/worker functions. Reviewed commit: `3ea28692e5ca5ff9356d61b76a2c7b13e86e6b19`.

The owner simplified the required AM hierarchy to **customer's package → assigned/available bouquets**, without displaying categories. Bouquet selection changes channel access. Category mapping is no longer a prerequisite.

AM must own its implementation and deployment. Do not modify the reseller website, its existing workers, or Hub. Their code is a reference. This investigation made no live worker requests or panel writes.

## Confirmed repository contracts

| Operation | Existing worker call | Reference |
| --- | --- | --- |
| Reseller-scoped line list | `/lines` with reseller `username` | `manage-lines.php` |
| Available packages | `/packages` with reseller `username` | `manage-lines.php`, `add-subscription.php` |
| Bouquet ID/name catalogue | `/bouquets` | `manage-lines.php` |
| Change selected bouquets | `/edit-line` with reseller username, line ID, notes, bouquet IDs | `actions/edit-line.php` |
| Change package | `/change-package` with `new_package_id` | `actions/change-package.php` |
| Change connections | `/upgrade-connections` with `new_max_connections` | `actions/upgrade-connections.php` |
| Renew/extend | `/renew-line` with optional package ID and `sync_bouquets` | `actions/renew-line.php` |
| Customer verification for support | `/verify-line` with line credentials | `includes/customer_auth.php` |

`includes/functions.php::call_auth_worker` signs server-to-server requests using HMAC-SHA256 over `timestamp.raw_json`, with `X-Portal-Timestamp` and `X-Portal-Signature`. Customer support has a different secret and uses `X-CCB-*` headers. Neither secret belongs in Android. AM needs its own customer-scoped server endpoints and server-held worker/database credentials.

The existing `/lines` contract is reseller-wide and can include passwords/notes. Never expose or relay that full response to a customer app. Resolve the authenticated customer's own line server-side and return a dedicated, minimal customer DTO. The support login is a useful reference but explicitly targets a support-only audience; do not assume its authorization covers all AM customers.

## Bouquet editing behavior

`manage-lines.php::openEditChannelsModal` reads `packages[].bouquets` and `line.bouquets`. It shows bouquets allowed by the package **or currently selected**. This preserves visibility of existing assignments outside the current catalogue. The browser's filtering is not server-side authorization.

The existing edit endpoint writes notes and bouquets together. AM's bouquet-specific endpoint should preserve notes server-side rather than letting Android submit reseller notes. It must validate allowed additions on the server, distinguish removal of existing assignments from re-adding restricted ones, and handle stale edits explicitly.

## Earlier worker snapshot found locally

A previously uploaded worker snapshot was available in the workspace at `/tmp/opencode/uploaded-auth-worker-review/main.py`. Its version is not established as the current deployed version. The website repository itself contains worker patches and installers, not this complete worker source; its documentation warns against replacing a current worker with an old uploaded file.

The snapshot confirms these data relationships:

- `customers.member_id` links a service line to its reseller.
- `customers.package_id` identifies `users_packages.id`.
- `users_packages.package_name`, duration fields, and `max_connections` provide package details.
- `users_packages.bouquets` contains package bouquet IDs.
- `customers.bouquet` contains the line's selected bouquet IDs.
- `bouquets.id`, `bouquet_name`, and `bouquet_order` provide catalogue identity/display order.
- The reseller worker's package scope uses `users.member_group_id` and `override_packages` with package `groups`.

Its `/bouquets` model contains `id` and `name`, which is sufficient for the requested bouquet list when joined with the authenticated customer's package and assignments. The previously missing bouquet-to-category mapping is no longer needed. Do not relabel the flat provider category response as bouquets.

The snapshot's `/edit-line` updates the panel database and then calls `_overcast_refresh_customer`. A committed database change can be followed by a failed native refresh. AM must represent that as saved/pending activation and repair refresh without treating it as an uncommitted change or repeating financial operations.

## Existing reseller credits are not customer account credit

The current package-change UI describes charging the positive package credit-price difference while preserving expiry; a cheaper package has no additional charge. That is reseller credit accounting, not the customer monetary/prorated downgrade credit requested for AM. Customer pricing, proration, ledger entries, and payment settlement still need AM-owned implementation and confirmed rules. Do not relabel `price_credits` as a CAD/USD price.

## Next integration

1. Obtain the current deployed worker source/configuration context through private server access. Inspect schema/read paths without modifying the existing service.
2. Confirm package and assigned/available bouquet read paths against that deployed version. No category lookup is required.
3. Deploy a separate AM service with its own configuration, credentials, customer sessions, and dedicated port/hostname. It can share the host if the owner chooses, but must not overwrite or restart the existing worker.
4. Return the current package and bouquet IDs/names/selection state to AM Android. Keep unavailable data explicit; do not replace it with sample records.
5. Add customer-scoped bouquet edits with package validation, concurrency handling, audit, and native refresh, before adding paid package/connection changes.

Needed from the owner now: the server/current worker folder and private access, plus the intended AM service hostname or confirmation to host AM separately on the same machine. No database password or worker secret should be pasted into public source/issues.
