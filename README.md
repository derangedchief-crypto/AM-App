# AM App

Android customer account-management project for the owner's customers.

## Source of truth

[derangedchief-crypto/AM-App](https://github.com/derangedchief-crypto/AM-App), branch `main`, is the canonical source for this project and its confirmed requirements.

## Confirmed scope

- Sign in with the Hub-style activation code or existing service username and password.
- View services, expiry dates, and past bills.
- Build bill history from new app transactions in a database the owner will provide; no historical bill import is needed.
- Renew active or expired services using AM-owned billing functions based on the existing ARVIO flow.
- Let customers change their package, connections, subscription length, and channel selections, with immediate effect after confirmation and any required verified payment.
- Credit unused value from downgrades or reduced connections to the customer's account balance. Exact proration rules remain to be defined.
- One customer app, rather than a reseller-branded app builder.
- Signed APK releases and in-app updates following Extreme Hub's release process.
- Publish source code in the public AM-App GitHub repository, as requested by the owner.
- Keep AM's implementation independent. Hub is a reference only: do not modify its app, backend functions, enrollment, settings, or deployment for this project.

## Billing reference for future integration

Reference: `derangedchief-crypto/ARVIO`, branch `main`.

- Android: `app/src/main/kotlin/com/arflix/tv/management/RenewalLoginClient.kt` and `RenewalCheckoutRepository.kt`.
- Backend: `control-center/src/RenewalPayments.php`, `RenewalSettings.php`, `CardGateway.php`, and `RenewalWorker.php`.
- Hosted checkout: `control-center/public/renew.html`.
- Release process: `.github/workflows/publish-release.yml`.

The existing renewal login uses a signed device challenge and service credentials (or an activation code) to issue a short-lived checkout capability. Country selection controls eligible gateways: Stripe/Square in Canada and Stripe/Square/Rampex in the USA, subject to provider configuration.

Current checkout requires an approved branded package and an expired service. A separate account-management package needs explicit backend registration and customer-scoped sessions. Its credentials must not be treated as admin credentials, and a checkout token is not a general account session.

## Required integration work

1. Build AM-owned customer-session and service/invoice endpoints, referencing the existing Hub functions without changing Hub. Direct provider login is now implemented for read-only service details.
2. Record new invoices and verified payment/fulfilment status server-side in the owner's billing database. History starts empty; opening the app is not evidence of payment. Confirm the database connection and schema before integration.
3. Implement early renewal in AM's own billing/fulfilment functions, preserving remaining service time and exactly-once fulfilment. Do not change Hub's eligibility rules or worker deployment.
4. Build the Android account, services, bills, renewal, and settings screens against those endpoints.
5. Configure a separate signing key and publish signed APK updates through public GitHub Releases. Keep signing keys, database credentials, payment secrets, and customer data outside the repository; no GitHub access token belongs in the APK.
6. Implement immediate service modifications with server-calculated quotes and an account-credit ledger. Preserve remaining subscription time; credit unused downgrade/reduced-connection value. Define proration and credit-use rules before implementing financial calculations, and prevent duplicate credits on retries.

## Status

Version **0.2.0: live, read-only service access**. The Android app now calls the real service-provider endpoints using its own implementation of the Hub login protocol. It displays only returned account data; the live navigation has no demo-account entry, sample bills, invented package, or assumed credit balance.

Login and account refresh are connected in code. End-to-end verification with a real customer requires an authorized test account; no customer credentials have been used during development. Billing, payment, service modifications, and automatic APK updates are still pending.

### Screens

- Activation-code or service-credential sign-in with loading and error states
- Home dashboard with verified service status, expiry, and connection counts
- Service details and searchable provider-returned channel groups
- Explicit unavailable states for package/duration fields the provider doesn't supply
- Billing and credit setup-pending states (not fake zero balances or empty fetched history)
- Account refresh, stale-data notice, and independent sign-out

The complete service-change/billing designs remain in the visual companion for future backend integration. Real service-change and renewal controls are disabled until that integration exists.

### Build and run

Requires JDK 17 and Android SDK 36. Set `ANDROID_HOME` or create an ignored `local.properties` with `sdk.dir`.

```sh
./gradlew :app:testDebugUnitTest :app:assembleDebug :app:lintDebug
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Open **AM Account** and use an authorized test customer's Hub activation code or service username/password. The live app uses application ID `net.extremeiptv.account`; the original `.demo` APK can coexist. Credentials and account data remain in the ViewModel's memory only, survive rotation, and are cleared on sign-out or process death. Nothing is saved in preferences, files, backups, or logs. A background/foreground transition refreshes the account.

See [`docs/live-account-integration.md`](docs/live-account-integration.md) for endpoint contracts, ownership boundaries, and the device acceptance checklist.

Visual direction: deep navy surfaces, lime accents, large headings, rounded cards, and four bottom-navigation destinations. Source: `app/src/main/kotlin/net/extremeiptv/account/MainActivity.kt`.

### Visual preview

Open [`docs/screens.html`](docs/screens.html) in a browser for the original fictional design companion, including proposed billing/modification screens. It is not the live account app or an Android emulator, and it must not be used to sign in with real credentials.

| Home | Modify service | Bills |
| --- | --- | --- |
| ![Home screen design](docs/screenshots/home.png) | ![Service modification design](docs/screenshots/modify.png) | ![Bill history design](docs/screenshots/bills.png) |

### Verification

- Live debug APK builds successfully. All 18 protocol/session unit tests pass using synthetic responses; Android lint reports 0 errors (11 SDK/dependency/backup advisories). See `app/src/test`.
- Browser design companion checked at 360px width across 10 screens with no horizontal overflow or JavaScript errors. Verified selections reaching review, demo confirmation, bill filters, and invoice navigation.
- Native runtime interaction has not been verified: the available emulator cannot start because this environment lacks KVM permissions.
