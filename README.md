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

Requirements and integration research only. No working Android app or backend changes have been delivered yet.
