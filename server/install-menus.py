#!/usr/bin/env python3
"""Add AM links to recognized reseller/admin menus, with private backups."""
import argparse
import importlib.util
import os
from pathlib import Path
import shutil
import tempfile

RESELLER_MARKER = '<!-- AM_APP_LICENSED_MENU -->'
LEGACY_RESELLER_LINK = '''
      <!-- AM_APP_LICENSED_MENU -->
      <?php if (empty($_SESSION['portal_staff']) && empty($_SESSION['impersonation'])): ?>
      <a href="<?= e(url('am-app/')) ?>" class="nav-item <?= ($activeNav ?? '') === 'am-app' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 18h4"/></svg>
        AM App
      </a>
      <?php endif; ?>
'''
ADMIN_MARKER = '// AM_APP_LICENSE_ADMIN_MENU'
PREVIOUS_APPLICATIONS_LINK = '''
      <!-- AM_APP_LICENSED_MENU -->
      <?php if (empty($_SESSION['portal_staff']) && empty($_SESSION['impersonation'])): ?>
      <div role="group" aria-labelledby="am-applications-heading" style="margin:10px 0;padding:10px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
        <div id="am-applications-heading" style="padding:0 12px 8px;font-size:.75rem;font-weight:600;letter-spacing:.06em;color:var(--text-muted)">Applications</div>
        <a href="<?= e(url('am-app/')) ?>" class="nav-item <?= ($activeNav ?? '') === 'am-app' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
          My TV
        </a>
      </div>
      <?php endif; ?>
'''

RESELLER_LINK = PREVIOUS_APPLICATIONS_LINK.replace(
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
    '<svg viewBox="0 0 128 128" fill="none" aria-hidden="true"><rect x="24" y="28" width="80" height="60" rx="16" stroke="currentColor" stroke-width="8"/><path d="M55 44L77 58L55 72V44Z" fill="currentColor" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/><path d="M64 90V102M49 102H79" stroke="currentColor" stroke-width="8" stroke-linecap="round"/></svg>')
OLD_ADMIN_LINK = "['am-licenses', 'AM App licenses', 'am-app/licenses-admin.php', 'check']"
PREVIOUS_ADMIN_LINK = "['am-licenses', 'My TV licenses', 'am-app/licenses-admin.php', 'check']"
ADMIN_LINK = PREVIOUS_ADMIN_LINK + ",\n            ['am-announcements', 'My TV announcements', 'am-app/announcements-admin.php', 'check']"


def changes(site):
    result = []
    for name in ['layout_header.php', 'admin_shell.php']:
        path = site / 'includes' / name
        if path.is_symlink() or not path.is_file():
            raise RuntimeError('Expected regular portal menu file: ' + name)
        old = path.read_text()
        if name == 'layout_header.php':
            base = old
            if RESELLER_MARKER in old:
                if old.count(RESELLER_MARKER) != 1:
                    raise RuntimeError('AM reseller menu marker changed; review the file.')
                if RESELLER_LINK in old:
                    base = old.replace(RESELLER_LINK, '', 1)
                elif PREVIOUS_APPLICATIONS_LINK in old:
                    base = old.replace(PREVIOUS_APPLICATIONS_LINK, '', 1)
                elif LEGACY_RESELLER_LINK in old:
                    base = old.replace(LEGACY_RESELLER_LINK, '', 1)
                else:
                    raise RuntimeError('AM reseller menu content changed; review the file.')
            anchor = '<a href="<?= e(url(\'sub-resellers.php\')) ?>"'
            if base.count(anchor) != 1 or "url('dashboard.php')" not in base or "url('am-app/')" in base:
                raise RuntimeError('Unrecognized reseller menu layout; no files changed.')
            end = base.find('</a>', base.index(anchor))
            if end == -1:
                raise RuntimeError('Sub-resellers menu link is incomplete; no files changed.')
            end += len('</a>')
            new = base[:end] + RESELLER_LINK + base[end:]
            if new == old:
                continue
        else:
            if ADMIN_MARKER in old:
                if old.count(ADMIN_MARKER) != 1 or 'am-app/licenses-admin.php' not in old:
                    raise RuntimeError('AM admin menu marker changed; review the file.')
                if ADMIN_LINK in old:
                    continue
                previous = PREVIOUS_ADMIN_LINK if PREVIOUS_ADMIN_LINK in old else OLD_ADMIN_LINK
                if old.count(previous) != 1:
                    raise RuntimeError('AM admin menu content changed; review the file.')
                result.append((path, old, old.replace(previous, ADMIN_LINK, 1)))
                continue
            anchor = "            ['credit-audit', 'Credit log audit', 'credit-audit.php', 'check'],"
            if old.count(anchor) != 1 or 'function admin_menu(): array' not in old:
                raise RuntimeError('Unrecognized admin menu layout; no files changed.')
            new = old.replace(anchor, "            // AM_APP_LICENSE_ADMIN_MENU\n            " + ADMIN_LINK + ",\n" + anchor, 1)
        result.append((path, old, new))
    return result


def install():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', default='/www/wwwroot/fecxbrfzku.net')
    parser.add_argument('--state', default='/www/am-app-state/fecxbrfzku.net')
    parser.add_argument('--php', default='/www/server/php/84/bin/php')
    args = parser.parse_args()
    site, state = Path(args.site).resolve(), Path(args.state).resolve()
    if os.geteuid() != 0:
        raise RuntimeError('Run as root on the Website host.')
    if site.parent == state or site.parent in state.parents or not state.is_dir():
        raise RuntimeError('Use the existing private AM state directory outside all website roots.')
    if not (site / 'am-app/licenses-admin.php').is_file():
        raise RuntimeError('Install the AM licensing website update first.')
    pending = changes(site)
    if not pending:
        print('AM reseller and administrator menu links are already installed.'); return
    spec = importlib.util.spec_from_file_location('am_installer', Path(__file__).with_name('install-panel.py'))
    installer = importlib.util.module_from_spec(spec); spec.loader.exec_module(installer)
    php = installer.clean_php(args.php)
    import subprocess
    backup = Path(tempfile.mkdtemp(prefix='menu-backup-', dir=state))
    staged = []
    try:
        for path, old, new in pending:
            shutil.copy2(path, backup / path.name)
            fd, name = tempfile.mkstemp(prefix='.am-menu-', suffix='.php', dir=path.parent)
            with os.fdopen(fd, 'w') as out: out.write(new)
            tmp = Path(name); staged.append((path, old, new, tmp))
            stat = path.stat(); os.chown(tmp, stat.st_uid, stat.st_gid); os.chmod(tmp, stat.st_mode & 0o777)
            subprocess.run(php + ['-l', str(tmp)], check=True)
        if any(path.read_text() != old for path, old, _, _ in staged):
            raise RuntimeError('A menu changed during installation; no replacement made.')
        applied = []
        try:
            for path, old, new, tmp in staged:
                os.replace(tmp, path); applied.append((path, new))
        except BaseException:
            for path, new in applied:
                if path.read_text() == new: shutil.copy2(backup / path.name, path)
            raise
    finally:
        for _, _, _, tmp in staged:
            tmp.unlink(missing_ok=True)
    print('Installed Applications / My TV and My TV licenses menu links. Private backup: ' + str(backup))


if __name__ == '__main__':
    try: install()
    except Exception as error: raise SystemExit('Menu installation stopped: ' + str(error))
