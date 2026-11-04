# AM reseller controls and customer API

## Hidden management page

The additive `panel/` module installs at **https://fecxbrfzku.net/am-app/**. There is no navigation entry. It uses the existing reseller login and CSRF implementation, rechecks enabled status with `/panel-user-balance`, and denies staff/impersonated sessions. Settings and audit revisions use a separate SQLite database scoped to the authenticated reseller ID.

**Current release saves preferences only.** Customer API access, individual customer approvals, live package/bouquet data, merchant credentials, payments, invoices, credits, renewals, and service writes are not connected. Gateway selections are setup preferences. Existing Android v0.2.1 does not yet enforce these preferences.

### aaPanel install

Download a pinned AM-App source archive, extract it, and run `python3 server/install-panel.py` as root. Defaults:

- Existing website: `/www/wwwroot/fecxbrfzku.net`
- New hidden directory: `/www/wwwroot/fecxbrfzku.net/am-app`
- New private state: `/www/am-app-state/fecxbrfzku.net/settings.sqlite`
- PHP: `/www/server/php/84/bin/php`; PHP worker user: `www`

Override with `--site`, `--state`, `--php`, or `--web-user` if needed. The installer validates PHP syntax/SQLite, refuses existing destinations/state, generates configuration automatically, and applies private permissions. It does not read or copy worker secrets, alter existing portal files, or restart services. The generated module-local `.user.ini` preserves inherited `open_basedir` paths and adds the private database directory. Pool/admin-level restrictions cannot be expanded this way; if the page reports storage unavailable, inspect the PHP-FPM error log/configuration before changing any host-wide setting. Newly inherited INI settings may be cached by PHP-FPM for up to five minutes.

After installation, sign into the existing reseller website, open `/am-app/`, save a preference, and reload to verify persistence. Check dashboard and manage-lines still load. Test a second reseller to confirm separate defaults/settings. Signed-out access should redirect to the existing login. The local suite uses synthetic portal contracts; the real site's PHP-FPM ownership, session cookie scope, worker enabled-state response, and inherited restrictions still require this live smoke test.

To disable the page, move only the new `am-app` directory outside the webroot. Retain the private database for future migration. The installer deliberately does not overwrite an installation; updates need a separate migration/update procedure.

### Local checks

```sh
PHP=/path/to/php python3 server/tests/panel_test.py
PHP=/path/to/php python3 server/tests/installer_test.py
```

Use PHP 8.1+ with PDO SQLite and sessions. Installer tests mock root/chown only; all file/database work is real. Browser checks use the existing portal stylesheet at desktop/mobile sizes in light/dark mode. No tests contact live customers, payments, or the shared worker.

## Customer API architecture

The owner approved hosting AM's API alongside the reseller website, provided the existing site remains intact. The existing reseller worker will be reused; a separate worker is no longer required.

Updated scope: one universal app for participating resellers, with a new AM App section in the reseller panel for opt-in/customer access and reseller-specific billing/renewals. See `docs/multi-reseller-integration.md`. The latest owner-supplied terminal check confirms PHP 8.4 with cURL, PDO SQLite, OpenSSL, and populated worker connection settings. The original read-only inventory is no longer a prerequisite; current work must establish the customer-to-reseller lookup contract and billing routing model.

Target architecture:

`AM Android → AM customer API → existing reseller worker`

AM owns its customer API, session storage, and customer-scoping logic. Worker shared secrets remain on the server. The Android app must not receive a reseller-wide worker secret or the full reseller line listing. Hub and shared worker contracts remain unchanged unless a missing contract is separately agreed. The reseller panel receives the additive hidden AM settings page while preserving existing behavior.

## Current step: identify the hosting layout

`inspect-host.py` is a read-only inventory intended for the aaPanel terminal on the reseller website host. It identifies the application by `manage-lines.php` and `includes/functions.php`, then reports document roots, public server names, PHP capability flags, configuration-section presence (not values), and whether an `am-api` directory already exists.

It does not load/execute the site's PHP configuration, access databases or workers, write files, deploy anything, change ownership/permissions, modify `.user.ini`/vhosts, or restart services. Its output can be returned to the coding session; it excludes worker secrets, credentials, and customer data.

After this inventory, prepare an isolated AM deployment using the verified hosting paths. Confirm its authenticated package/bouquet reads with an authorized test customer before connecting Android. Existing reseller routes/login should receive before/after read-only smoke checks. Do not overwrite an existing `am-api` directory or reuse reseller browser sessions for customer authorization.

This directory currently contains the inventory tool and integration notes, not a deployed/runnable customer API.
