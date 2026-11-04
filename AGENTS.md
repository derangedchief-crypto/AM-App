# Public repository privacy

- Never publish the user's auth-worker or website-host IP addresses in source, docs, tests, commit messages, release notes, issues, or uploaded assets.
- Use **Auth-worker host** and **Website host** labels in public deployment instructions. Keep actual infrastructure addresses in private configuration only.
- Run `python3 server/tests/public_content_test.py` before pushing changes. Check release notes separately before publishing.
- Customers may toggle any bouquet in their package repeatedly. Activated, no-expiry (unlimited) services may toggle any bouquet in the catalogue. Package/connection changes remain reseller-managed.
