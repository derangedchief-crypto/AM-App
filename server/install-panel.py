#!/usr/bin/env python3
"""Add the hidden AM module without editing existing portal files."""
import argparse
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import tempfile


def install():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', default='/www/wwwroot/fecxbrfzku.net')
    parser.add_argument('--state', default='/www/am-app-state/fecxbrfzku.net')
    parser.add_argument('--php', default='/www/server/php/84/bin/php')
    parser.add_argument('--web-user', default='www')
    args = parser.parse_args()
    site, state = Path(args.site).resolve(), Path(args.state).resolve()
    destination = site / 'am-app'
    source = Path(__file__).resolve().parent / 'panel'
    if os.geteuid() != 0:
        raise RuntimeError('Run this installer as root in the aaPanel terminal.')
    owner = pwd.getpwnam(args.web_user)
    if owner.pw_uid == 0:
        raise RuntimeError('The PHP web user must not be root.')
    if not (site / 'includes/bootstrap.php').is_file() or not (site / 'config.php').is_file():
        raise RuntimeError('Site must be the existing reseller portal root.')
    if destination.exists() or destination.is_symlink() or state.exists():
        raise RuntimeError('AM destination or state already exists; refusing to overwrite it.')
    webroot = site.parent
    if state == webroot or webroot in state.parents:
        raise RuntimeError('State must be outside /www/wwwroot (all website roots).')
    for filename in ('Settings.php', 'index.php'):
        subprocess.run([args.php, '-l', str(source / filename)], check=True)
    subprocess.run([args.php, '-r',
                    'if (!extension_loaded("pdo_sqlite")) {fwrite(STDERR,"PDO SQLite required\n");exit(1);}'], check=True)

    # Keep every inherited user-INI path; only the new module gets the extra path.
    basedir = ''
    for directory in [*reversed(site.parents), site]:
        ini = directory / '.user.ini'
        if ini.is_file():
            for line in ini.read_text().splitlines():
                match = re.match(r'^\s*open_basedir\s*=\s*(.*?)\s*$', line, re.I)
                if match:
                    value = match.group(1)
                    if value.startswith(('"', "'")):
                        quote = value[0]
                        end = value.find(quote, 1)
                        if end < 0:
                            raise RuntimeError('Cannot parse inherited open_basedir.')
                        value = value[1:end]
                    else:
                        value = value.split(';', 1)[0].strip()
                    basedir = value
    if any(char in str(state) + basedir for char in '\n\r"'):
        raise RuntimeError('Unsupported character in open_basedir paths.')
    paths = list(dict.fromkeys(filter(None, basedir.split(':') + [str(site), '/tmp', str(state)])))
    stage = Path(tempfile.mkdtemp(prefix='.am-install-', dir=site))
    created_state = False
    try:
        print('Creating isolated AM files and private SQLite database...', flush=True)
        state.mkdir(mode=0o700, parents=True, exist_ok=False)
        created_state = True
        for filename in ('Settings.php', 'index.php'):
            shutil.copyfile(source / filename, stage / filename)
        subprocess.run([args.php, '-r',
                        'require $argv[1]; \\AmAccount\\SettingsStore::initialize($argv[2]);',
                        str(stage / 'Settings.php'), str(state / 'settings.sqlite')], check=True)
        database = str(state / 'settings.sqlite').replace('\\', '\\\\').replace("'", "\\'")
        (stage / 'settings-config.php').write_text("<?php\nreturn ['database' => '" + database + "'];\n")
        (stage / '.user.ini').write_text('open_basedir="' + ':'.join(paths) + '"\ndisplay_errors=Off\n')
        for path in [state, *state.iterdir(), stage, *stage.iterdir()]:
            os.chown(path, owner.pw_uid, owner.pw_gid)
            path.chmod(0o700 if path.is_dir() else 0o600)
        if destination.exists():
            raise RuntimeError('Destination appeared during install; refusing to overwrite.')
        stage.rename(destination)
    except BaseException:
        shutil.rmtree(stage, ignore_errors=True)
        if created_state:
            shutil.rmtree(state)
        raise
    print('Installed. Open /am-app/ while signed into the reseller website.', flush=True)
    print('No existing portal files were edited. No services were restarted.')
    print('Preferences only: customer API, individual approvals, payments and service writes are pending.')
    print('If storage is unavailable, check PHP-FPM open_basedir: a pool-level restriction cannot be expanded by .user.ini.')


if __name__ == '__main__':
    try:
        install()
    except Exception as error:
        raise SystemExit('Installation stopped: ' + str(error))
