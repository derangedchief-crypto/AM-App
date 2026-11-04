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

Activation code login first resolves credentials and then uses the same account read as direct username/password login. No playlists, streams, profiles, device enrollment, billing sessions, payment requests, or renewal jobs are created by this app.

Transport uses HTTPS-only production endpoints, no redirects, no logging interceptor, no cookies/cache, explicit request deadlines, bounded response bodies, and cancellation. Error messages never include raw response bodies, credential-bearing URLs, or exceptions from the HTTP layer. Endpoint injection exists only in the internal client constructor for local mock-server tests; there is no editable customer host field.

## Data interpretation

- `user_info.auth` must explicitly equal `1`; missing authentication data fails closed.
- If the provider echoes a username, it must match the requested account.
- Active and positively authenticated expired accounts can display their own read-only details. Disabled/banned/restricted accounts are rejected. This is an account viewer, not a playback grant.
- Expiry uses `exp_date`; zero or explicit null means no expiry, whereas a missing field is unknown. Known provider/server time can mark an otherwise active response expired. The phone clock is not used to authorize an account.
- `max_connections`, `active_cons`, `is_trial`, `created_at`, and explicit `package_name`/`package` are displayed only when valid. No fuzzy category-to-package inference, prices, periods, or billing identity are invented.
- Categories are no longer fetched or displayed (owner's simplification). The Services screen is package and bouquets only; actual bouquet assignments still require the AM worker connection.
- A refresh outage retains the last verified account with a stale-data warning and timestamp. A rejected refresh clears account and credentials.

The current provider endpoint may reject expired credentials/codes before positive authentication. AM must not bypass that rejection to expose account information. Supporting all expired customers later requires a separately authorized AM backend flow.

## Session lifecycle

The provider does not issue a durable session token. Version 0.2.1 remembers the original code or service credentials only after successful authentication. `EncryptedLoginStore` writes an atomic AES-256-GCM ciphertext file in `Context.noBackupFilesDir`; its random-IV encryption uses a non-exportable app-specific Android Keystore key and authenticated format binding. Code sign-in stores only the original code and resolves it again on restart. Credential sign-in stores username/password encrypted. Raw input fields are not saveable, and no credentials are logged or included in backups/device transfer.

On launch, the saved login is decrypted and freshly verified. A transient outage preserves the saved login and offers retry instead of forcing credential entry. Authorization rejection removes it; corrupt ciphertext or key loss returns to sign-in. Sign-out cancels requests, invalidates late callbacks, deletes the encryption key/file, and only reports success if removal succeeds. No account data is restored solely because encrypted login bytes exist. Live account snapshots remain memory-only.

An eventual AM-owned backend session token can replace saved service credentials. This UI authentication must not become the authority for financial operations: future AM backend endpoints must authenticate and scope every customer request themselves.

## Device acceptance checklist

Use an authorized test customer's credentials on a phone. Never commit or paste customer credentials into test fixtures or GitHub issues.

1. Confirm activation-code and direct-credential login show the same provider account.
2. Compare status, expiry, and connection limit with the panel. Fields absent from the API should say not provided.
3. Confirm Services has a bouquet section and no category list. Until the AM worker is connected it must show setup pending, not assumed assignments.
4. Rotate the phone while signing in and while viewing details; navigation should remain usable.
5. Disable networking and refresh: last verified data must be marked stale. Restore networking and retry.
6. Sign out during a refresh: the account must not reappear after a late response.
7. Force-stop and reopen after a successful login: account verification should run automatically without typing. Repeat with both code and password login. Offline restart should offer saved-login retry; it must not delete the stored login.
8. Confirm billing, credit, renewal, and modification screens never show sample monetary data or permit transactions.
9. Verify Hub remains signed in with its settings and service unchanged.
10. Sign out, force-stop, and reopen: the login form must appear. Updating from 0.2.0 requires one initial login because that build never saved credentials.

No authorized live customer credentials are available in this workspace. Unit tests cover the protocol and lifecycle with synthetic responses; actual provider compatibility and native interaction still need this device check. The local emulator cannot run because this environment lacks KVM permissions.
