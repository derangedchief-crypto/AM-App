# My TV updates

## Package transition

From 0.5.0, the installed Android package is **`com.extreme.mytv`**. This is a new installation alongside the older `net.extremeiptv.account` app. Existing users install 0.5.0 once and sign in again; saved credentials cannot be copied across Android app sandboxes. The service account and billing history remain on the server. Users may remove the old app after confirming their login in the new one.

Future releases retain `com.extreme.mytv` and the same signing certificate. Their version code must increase. The internal Kotlin namespace is not the installed application ID.

## Customer experience

- Checks automatically when the app starts or returns, throttled to six hours within the running app. A WorkManager job also checks approximately every 12 hours while connected; Android may defer background work.
- **App updates** is available on sign-in, saved-login recovery and Account. Manual checks bypass the foreground throttle.
- New versions show an update prompt. Customers download the APK in the app, then select **Install update**. Android requires installation approval and may ask them to allow My TV to install unknown apps.
- Customers can enable update notifications from the update dialog. Denied notifications do not prevent foreground checks. An update is notified once per version.
- Downloads use public GitHub release assets. No customer credentials or GitHub token are sent. Metadata is size-limited and restricted to this repository's HTTPS APK release URLs.
- APK length and SHA-256 are checked. Before installation, Android reads the archive package/version/certificate; these must match the release, be newer than the installed app, and match the installed signing identity. The file is rechecked before sharing it with Android through a non-exported FileProvider limited to the update cache.
- Failed downloads are removed. Customers can retry or redownload. A process restart may require downloading again. There is no silent installation on ordinary Android devices.

## Publishing future versions

The feed is the `my-tv-update.json` asset on the repository's **latest non-prerelease GitHub release**. A complete versioned release makes the update discoverable; app checks do not depend on a customer signing into GitHub.

1. Increase `versionCode` and `versionName` in `app/build.gradle.kts`. Keep the package and signing key.
2. Run `./gradlew :app:testDebugUnitTest :app:assembleDebug :app:lintDebug` using the existing signing environment. This currently produces the same signed debug-build distribution used by this project's APK releases.
3. Write release notes outside the checkout, review them for private infrastructure information, commit the changes and push main.
4. Publish with the helper, selecting an output folder outside the repository:

```bash
python3 tools/publish-android.py \
  --apk app/build/outputs/apk/debug/app-debug.apk \
  --output /tmp/opencode/my-tv-release \
  --notes /tmp/opencode/my-tv-release-notes.md \
  --publish
```

Without `--publish`, the helper only prepares and validates local artifacts. It reads version/package information from the APK, verifies the signing certificate against the existing public fingerprint, scans notes and decompressed APK contents for public IP addresses, and calculates the metadata/hash. It refuses non-increasing versions when an existing update feed is available. The helper uploads the APK, checksum and update metadata to a draft, then publishes the complete release as latest. Do not mark unrelated releases latest or omit this metadata from future Android releases.

Do not replace an APK already published under an existing version. Publish a higher version code for corrections. Keep the signing key backed up privately; changing the key breaks ordinary in-place updates.

## Verification

Automated checks cover metadata validation, repository URL restrictions, file integrity, truncation/oversize/cancellation cleanup, package/version/signer comparisons and public feed failures. APK metadata/signature are also checked by the publishing helper. On-device acceptance should cover background notifications, the unknown-source permission screen, cancel/retry and installation of a newer version without losing a saved login.
