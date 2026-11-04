# Universal AM App and reseller controls

## Confirmed scope change

The owner now wants one universal AM app for **any participating reseller**, superseding the original owner-only scope. A new reseller-panel section will let each reseller enable customer management and configure their own billing/renewal flow. This is not automatic access for every line: the owning reseller must enable participation.

The existing reseller worker is shared. AM owns its customer API, customer sessions, and module-specific settings/storage. The requested page is hidden at `/am-app/` with no navigation entry for now, using the existing reseller login. Existing behavior, sessions, and functions must remain intact. Hub remains unchanged. Existing worker behavior must not be silently modified.

## Hosting facts supplied by the owner

- Website host: `[private website host]`, Ubuntu 24.04, aaPanel.
- Site files: `/www/wwwroot/fecxbrfzku.net`; staging: `/www/wwwroot/reseller-staging`.
- PHP CLI: `/www/server/php/84/bin/php`.
- Owner's terminal check confirms `curl`, `pdo_sqlite`, and `openssl` are loaded and the site's `auth_worker.base_url` and `shared_secret` are populated.
- PHP emitted duplicate-extension and JIT advisories, but the checks completed successfully. No PHP configuration changes were made or are required for those passed checks.
- Direct deployment access is not established in this workspace. Supply self-contained aaPanel terminal setup commands, generating private configuration automatically and never printing secrets.

## Proposed reseller section

**AM App → Access / Customer permissions / Billing & renewals**

- Reseller participation switch (proposed default: disabled until that reseller opts in).
- Controls for which of their customers may use AM, including revocation.
- Capability settings for bouquet editing, package/connection changes, and renewal. Displayed actions must reflect both the reseller's configuration and supported backend capabilities.
- Reseller-specific billing destination/integration configuration and configuration status.
- Customer-visible support information and provider display name.

Settings are scoped to the authenticated reseller's immutable panel ID, not a posted username. Revalidate reseller ownership and enabled state when accessing/saving settings. Use existing reseller authentication and CSRF checks for the new pages; respect staff/impersonation permission boundaries rather than implicitly granting billing configuration access.

## Customer access and routing

1. Authenticate the customer's activation code or service credentials.
2. Resolve their authoritative panel line ID and owning reseller ID server-side.
3. Check reseller participation, customer access, and permitted actions.
4. Issue an AM customer session scoped to that line and reseller.
5. Return that line's package, bouquet names/IDs/selection state, and permitted actions. Categories are out of scope.
6. Route renewal/payment to the owning reseller's configured flow. Reseller routing and merchant identity must come from server configuration, not customer request fields.

The reviewed `/lines` endpoint requires a reseller username and can return many customers' secrets. It is not a public customer lookup. Verify whether the deployed worker has a suitable authenticated customer-to-owner lookup. The existing support `/verify-line` flow has a narrower audience and cannot be assumed universal. Do not enumerate all resellers or load everyone's line credentials to discover an owner. If no suitable contract exists, document the missing lookup and agree on the smallest compatible extension before changing a shared worker.

Invoices, credits, checkout references, webhooks, and idempotency keys must carry the reseller/line scope. Disabling access or changing ownership must revoke/re-evaluate prior sessions and pending actions. Do not transfer credit between resellers implicitly. Cross-reseller access tests are required even where numeric IDs overlap across external integrations.

## Billing model and integration

Confirmed November 4: customer billing is for **renewals**, not bouquets. Every reseller maps offered worker package IDs to its own customer money prices. The worker's `price_credits` remains the fixed wholesale renewal cost charged to that reseller; no retail-money-to-credit conversion is implied. Square is the first requested integrated gateway. Customer prices must be sourced from the owning reseller's server-side mapping, never Android input. Payment receipts use **Payment** only, without AM notes or memos.

`server/panel/Pricing.php` implements tenant/currency-scoped prices against the signed `/packages` catalogue. The hidden page shows the fixed credit cost beside an editable customer renewal price. It stores a package fingerprint, rejects stale saves, and refuses quotes if cost/term/connection details changed until the reseller reviews them. Bouquet lists do not affect price. A 0.00 customer price does not waive reseller credits. Pricing is implemented; customer ownership/session API and automatically fulfilled customer checkout are still blocked on the current worker contracts.

The older worker source has no idempotency/order reference on `/renew-line`. It can commit the credit debit/expiry and then fail native refresh. A payment webhook retry must never blindly call it twice. Before enabling customer charges, inspect the deployed worker for a usable customer-to-owner lookup and transaction-level renewal idempotency/result-recovery contract. `server/inspect-worker-contract.py` prints AST-only route/model/helper fingerprints without importing config, calling APIs, or exposing credentials. It is run on the worker host, not merely the website host. If it matches the old source, the smallest additive worker extension must bind the AM order ID, reseller debit and service mutation in the same database transaction; preserve existing routes and policies.

The first integrated checkout is each reseller's own Square account, following Hub's hosted-checkout approach. The existing external-link preference remains available for future customer routing.

- External link routing can be implemented without assuming payment completion. An arbitrary checkout URL alone does not supply verified invoice history, credit accounting, or automatic renewal. Those need a documented callback/API or deliberate reconciliation workflow.
- Integrated checkout must preserve server-owned prices, merchant routing, verified payment state, idempotent renewal, and the distinction between paid and service applied.
- Existing reseller `price_credits` is wholesale reseller credit, not automatically a customer CAD/USD price. AM's customer ledger/proration rules remain distinct.
- Any external handoff must not put passwords, activation codes, worker secrets, or bearer tokens in the URL. Restrict merchant destinations according to the selected integration and use a scoped, expiring handoff where supported.

## Delivery order

1. Confirm billing routing model and worker owner-lookup contract.
2. Add isolated AM settings storage and the additive reseller-panel section, initially disabled.
3. Implement customer-scoped API and package/bouquet reads against the shared worker.
4. Connect Android to reseller-aware permissions and billing routing; disable direct-provider fallback for managed functions so it cannot bypass opt-in.
5. Add bouquet writes and selected billing integration with customer credit/history.
6. Verify isolation with at least two participating resellers, disabled/nonparticipating customers, expired accounts, and ownership changes; smoke-test existing panel routes before and after deployment.

Existing v0.2.1 remains a direct-provider read-only test app. It does not yet enforce reseller participation. The hidden reseller preferences and Square setup test are deployed; the user confirmed sandbox verification. Package price mappings are implemented/tested but await deployment. Customer access enforcement and automatic renewal are not live yet.
