# Global announcements — My TV 0.6.1

Announcements are hosted entirely on the Website host. No Firebase, external push provider, customer token or Auth-worker request is involved. Install the website update and My TV 0.6.1 (version code 18). It updates the existing `com.extreme.mytv` installation using the same signing identity.

## Deploy on the Website host

Run as root in the website terminal. The updater backs up the private SQLite database, preserves settings and keys, and adds the announcements table. No service restart is needed.

```bash
set -eu
work=$(mktemp -d /tmp/my-tv-announcements.XXXXXX)
curl -fL https://github.com/derangedchief-crypto/AM-App/archive/refs/tags/v0.6.1.tar.gz -o "$work/source.tar.gz"
tar -xzf "$work/source.tar.gz" -C "$work"
python3 "$work/AM-App-0.6.1/server/update-panel.py" \
  --site /www/wwwroot/fecxbrfzku.net \
  --state /www/am-app-state/fecxbrfzku.net \
  --php /www/server/php/84/bin/php
python3 "$work/AM-App-0.6.1/server/install-menus.py" \
  --site /www/wwwroot/fecxbrfzku.net \
  --state /www/am-app-state/fecxbrfzku.net \
  --php /www/server/php/84/bin/php
curl -fsS https://fecxbrfzku.net/am-app/announcements.php
```

The final command should return JSON with `schema: 1` and an `announcements` array. Before deployment the new app silently tolerates a missing feed and retries later.

## Publish

Sign in with the separate website administrator account, then open **My TV announcements**, or `/am-app/announcements-admin.php`. Resellers, staff and impersonation sessions cannot publish. Mutations require the existing CSRF token.

Enter a title, message, severity (info, warning, critical) and expiry in UTC within 90 days. Up to 20 notices may be active. Messages are plain text; the public feed exposes only notice content and revision/timing identifiers. Do not publish account details. The page shows the most recent 100 notices.

Editing an active notice publishes a new revision and alerts customers again. Stale edits are rejected. Withdrawn and expired notices cannot be revived; publish a new notice instead. Withdrawals disappear on the next successful app check, including posted notifications. Android notifications also have a local expiry timeout.

**Delete announcement** permanently removes an active, withdrawn or expired notice from storage and the admin list. Like withdrawal, deletion removes customer copies on the next successful check. It uses the same administrator/CSRF restrictions and rejects stale revisions. Withdrawal keeps the admin record; deletion removes it.

The app's top-right **notification bell** shows an unread count and opens an inbox of all active notices, including notices already read. Opening a notice or acknowledging its popup marks that revision read; it remains available in the inbox until expiry, withdrawal or deletion. The bell is available on sign-in, saved-login recovery and all dashboard tabs. Info notices use a blue information icon, warnings an amber triangle, and critical alerts a red exclamation-in-circle, with matching labels, accents and buttons. The detail view scrolls long messages and shows the expiry in the device's local timezone.

## Delivery and acceptance

- Foreground checks occur immediately on opening and approximately every minute while started, including before sign-in.
- Background WorkManager checks run approximately every 15 minutes with network access. Android battery restrictions, force-stop and offline devices can delay delivery. This is polling, not guaranteed instant push.
- On first launch, customers without notification permission see an opt-in. They can also enable app notifications from **App updates** or Android settings. In-app announcements work without permission.
- Dismissals persist on the device by notice/revision. Background notifications are sent once per revision; a new revision becomes unread again. Notification taps open the app's unread announcements.
- Failed requests retain cached, unexpired notices. Expired notices are filtered locally using the device clock. Withdrawals require a successful feed check; an offline device cannot discover them immediately.
- All severities use the ordinary service-announcements notification channel and respect customer notification settings.

Automated tests cover publication authorization, CSRF, validation, escaped output, stale revisions, withdrawals, expiry, parser bounds and duplicate/revision rules. Browser checks exercise publishing/withdrawal and mobile layout. Real-device acceptance remains necessary: allow notifications, publish a short-lived notice, verify foreground/background delivery, dismiss, edit, withdraw, and confirm an in-place update preserves sign-in. The Website host must remain reachable to publish and retrieve new notices.
