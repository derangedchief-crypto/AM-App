# AM App

Private Android customer account-management project for the owner's customers.

## Confirmed scope

- Sign in with existing service username and password.
- View services, expiry dates, and past bills.
- Renew active or expired services using the existing ARVIO billing backend.
- One customer app, rather than a reseller-branded app builder.
- Signed APK releases and in-app updates following Extreme Hub's release process.
- Keep source code in a private GitHub repository.

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
2. Define which historical billing system supplies past invoices; existing renewal orders alone may not contain older bills.
3. Extend renewal eligibility and the fulfilment worker for early renewal, preserving remaining service time and exactly-once fulfilment.
4. Build the Android account, services, bills, renewal, and settings screens against those endpoints.
5. Configure a separate signing key and customer-download endpoint. Private GitHub release assets cannot be fetched anonymously; no GitHub access token belongs in the APK.

## Status

Requirements and integration research only. No working Android app or backend changes have been delivered yet.
