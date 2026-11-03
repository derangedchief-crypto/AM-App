# AM App

Android customer account-management project for the owner's customers.

## Source of truth

[derangedchief-crypto/AM-App](https://github.com/derangedchief-crypto/AM-App), branch `main`, is the canonical source for this project and its confirmed requirements.

## Confirmed scope

- Sign in with existing service username and password.
- View services, expiry dates, and past bills.
- Build bill history from new app transactions in a database the owner will provide; no historical bill import is needed.
- Renew active or expired services using the existing ARVIO billing backend.
- Let customers change their package, connections, subscription length, and channel selections, with immediate effect after confirmation and any required verified payment.
- Credit unused value from downgrades or reduced connections to the customer's account balance. Exact proration rules remain to be defined.
- One customer app, rather than a reseller-branded app builder.
- Signed APK releases and in-app updates following Extreme Hub's release process.
- Publish source code in the public AM-App GitHub repository, as requested by the owner.

## Existing billing integration

Reference: `derangedchief-crypto/ARVIO`, branch `main`.

- Android: `app/src/main/kotlin/com/arflix/tv/management/RenewalLoginClient.kt` and `RenewalCheckoutRepository.kt`.
- Backend: `control-center/src/RenewalPayments.php`, `RenewalSettings.php`, `CardGateway.php`, and `RenewalWorker.php`.
- Hosted checkout: `control-center/public/renew.html`.
- Release process: `.github/workflows/publish-release.yml`.

The existing renewal login uses a signed device challenge and service credentials (or an activation code) to issue a short-lived checkout capability. Country selection controls eligible gateways: Stripe/Square in Canada and Stripe/Square/Rampex in the USA, subject to provider configuration.

Current checkout requires an approved branded package and an expired service. A separate account-management package needs explicit backend registration and customer-scoped sessions. Its credentials must not be treated as admin credentials, and a checkout token is not a general account session.

## Required integration work

1. Add customer authentication and customer-scoped service/invoice endpoints to the existing backend.
2. Record new invoices and verified payment/fulfilment status server-side in the owner's billing database. History starts empty; opening the app is not evidence of payment. Confirm the database connection and schema before integration.
3. Extend renewal eligibility and the fulfilment worker for early renewal, preserving remaining service time and exactly-once fulfilment.
4. Build the Android account, services, bills, renewal, and settings screens against those endpoints.
5. Configure a separate signing key and publish signed APK updates through public GitHub Releases. Keep signing keys, database credentials, payment secrets, and customer data outside the repository; no GitHub access token belongs in the APK.
6. Implement immediate service modifications with server-calculated quotes and an account-credit ledger. Preserve remaining subscription time; credit unused downgrade/reduced-connection value. Define proration and credit-use rules before implementing financial calculations, and prevent duplicate credits on retries.

## Status

Interactive native Android screen preview built with Kotlin and Jetpack Compose. All accounts, invoices, balances, and service details are explicitly labeled demo data. No backend is connected, no credentials are sent or saved, and no payment or service modification is performed.

### Screens

- Sign-in and demo entry
- Home dashboard with service status, expiry, balance, and recent activity
- Service details
- Modify package, simultaneous connections, subscription length, and channel selections
- Renewal duration selection
- Review selections, country, and account-credit preference
- Demo confirmation explaining the connected payment flow
- Bill history with paid/credit filters and invoice detail
- Account credit balance and credit activity
- Account, update, support, and sign-out controls

Service modification quotes are deliberately shown as pending: demo selections do not invent live prices or proration rules. The credit-use switch is a UI proposal; final credit application policy still needs confirmation.

### Build and run

Requires JDK 17 and Android SDK 36. Set `ANDROID_HOME` or create an ignored `local.properties` with `sdk.dir`.

```sh
./gradlew :app:assembleDebug :app:lintDebug
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Open **AM Account · Demo** and choose **Explore demo account**. The demo uses a separate application ID, `net.extremeiptv.account.demo`. UI choices survive rotation; signing out returns to the sample defaults. Real sign-in, billing, persistent account sessions, downloads, and support integration remain future backend work.

Visual direction: deep navy surfaces, lime accents, large headings, rounded cards, and four bottom-navigation destinations. Source: `app/src/main/kotlin/net/extremeiptv/account/MainActivity.kt`.

### Visual preview

Open [`docs/screens.html`](docs/screens.html) in a browser for a clickable design companion, including all main screens. It uses the same colors and content as the native app but is not an Android emulator.

| Home | Modify service | Bills |
| --- | --- | --- |
| ![Home screen design](docs/screenshots/home.png) | ![Service modification design](docs/screenshots/modify.png) | ![Bill history design](docs/screenshots/bills.png) |

### Verification

- Debug APK assembled successfully; Android lint: 0 errors, 5 advisory warnings (SDK/dependency versions and backup configuration).
- Browser design companion checked at 360px width across 10 screens with no horizontal overflow or JavaScript errors. Verified selections reaching review, demo confirmation, bill filters, and invoice navigation.
- Native runtime interaction has not been verified: the available emulator cannot start because this environment lacks KVM permissions.
