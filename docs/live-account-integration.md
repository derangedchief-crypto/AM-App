# Live account integration — AM-owned implementation

## Ownership

The user requested the same login behavior as Hub, with a separate implementation owned by AM App. No code, deployments, secrets, schema, settings, device enrollments, or sessions in Hub have been changed. This implementation has no Hub library dependency or shared Android storage.

Protocol reference: `derangedchief-crypto/ARVIO` commit `1effc23071da1556cfa1fb11c44d1b3a9e12d305`:

- `IptvActivationRepository.resolve`
- `XtreamGateViewModel.submit`
- `IptvRepository.verifyXtreamLogin`
- `PrimaryLineVerifier` / `AccessPolicy` for server-time expiry semantics

AM's independently implemented functions live in `ServiceAccountClient.kt`, `ServiceAccount.kt`, and `AccountViewModel.kt`. Existing provider endpoints are reused; AM's future billing/customer-session backend will be its own deployment and functions. Do not add AM access by changing Hub's brand restrictions or playback grants.

## Requests

| Purpose | Endpoint | Request |
| --- | --- | --- |
| Resolve a Hub-style code | `https://activationworker.extremeiptv.net/activate` | POST JSON `activation_code` (six ASCII digits) |
| Verify service credentials and read account | `https://tv.extremeiptv.net/player_api.php` | GET with URL-encoded username/password |
| Read available live channel groups | Same provider endpoint | GET with credentials and `action=get_live_categories` |

Activation code login first resolves credentials and then uses the same account read as direct username/password login. No playlists, streams, profiles, device enrollment, billing sessions, payment requests, or renewal jobs are created by this app.

Transport uses HTTPS-only production endpoints, no redirects, no logging interceptor, no cookies/cache, explicit request deadlines, bounded response bodies, and cancellation. Error messages never include raw response bodies, credential-bearing URLs, or exceptions from the HTTP layer. Endpoint injection exists only in the internal client constructor for local mock-server tests; there is no editable customer host field.

## Data interpretation

- `user_info.auth` must explicitly equal `1`; missing authentication data fails closed.
- If the provider echoes a username, it must match the requested account.
- Active and positively authenticated expired accounts can display their own read-only details. Disabled/banned/restricted accounts are rejected. This is an account viewer, not a playback grant.
- Expiry uses `exp_date`; zero or explicit null means no expiry, whereas a missing field is unknown. Known provider/server time can mark an otherwise active response expired. The phone clock is not used to authorize an account.
- `max_connections`, `active_cons`, `is_trial`, `created_at`, and explicit `package_name`/`package` are displayed only when valid. No fuzzy category-to-package inference, prices, periods, or billing identity are invented.
- Channel categories are provider-reported viewing groups, not an authoritative editable subscription catalogue.
- Category failure shows a warning without inventing channels. Authorization rejection clears the login. Expired accounts do not request channel groups.
- A refresh outage retains the last verified account with a stale-data warning and timestamp. A rejected refresh clears account and credentials.

The current provider endpoint may reject expired credentials/codes before positive authentication. AM must not bypass that rejection to expose account information. Supporting all expired customers later requires a separately authorized AM backend flow.

## Session lifecycle

Credentials are retained only in AM's ViewModel memory while signed in, allowing refresh without storing raw passwords. There is no durable session token issued by this provider API. Rotation retains the ViewModel; process death requires sign-in again. Password/code input fields are not saveable. Sign-out cancels requests and invalidates late callbacks so an old response cannot restore the session.

Persistent sign-in should later use an AM-owned backend session and Keystore-backed storage for its token. This UI authentication must not become the authority for financial operations: future AM backend endpoints must authenticate and scope every customer request themselves.

## Device acceptance checklist

Use an authorized test customer's credentials on a phone. Never commit or paste customer credentials into test fixtures or GitHub issues.

1. Confirm activation-code and direct-credential login show the same provider account.
2. Compare status, expiry, and connection limit with the panel. Fields absent from the API should say not provided.
3. Open/search channel groups. No playback or channel edits should start.
4. Rotate the phone while signing in and while viewing details; navigation should remain usable.
5. Disable networking and refresh: last verified data must be marked stale. Restore networking and retry.
6. Sign out during a refresh: the account must not reappear after a late response.
7. Close the app process and reopen: sign-in is required.
8. Confirm billing, credit, renewal, and modification screens never show sample monetary data or permit transactions.
9. Verify Hub remains signed in with its settings and service unchanged.

No authorized live customer credentials are available in this workspace. Unit tests cover the protocol and lifecycle with synthetic responses; actual provider compatibility and native interaction still need this device check. The local emulator cannot run because this environment lacks KVM permissions.
