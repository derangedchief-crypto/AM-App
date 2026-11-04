#!/usr/bin/env python3
"""Add the hidden AM module without editing existing portal files."""
import argparse
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import tempfile

PANEL_FILES = ('Settings.php', 'Square.php', 'Pricing.php', 'Customer.php', 'customer-api.php', 'payment-return.php', 'square-webhook.php', 'index.php')


def write_config(path, state, public_url):
    from urllib.parse import urlsplit
    parsed = urlsplit(public_url)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise RuntimeError('Public URL must be the fixed HTTPS address of the AM module.')
    values = {'database': str(state / 'settings.sqlite'), 'secret_key': str(state / 'gateway.key'), 'public_url': public_url.rstrip('/')}
    quote = lambda value: "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    path.write_text('<?php\nreturn [' + ', '.join(quote(k) + ' => ' + quote(v) for k, v in values.items()) + '];\n')


def clean_php(binary):
    """Ignore host INI/extensions for installer CLI only; leave PHP-FPM alone."""
    command = [binary, '-n']
    probe = subprocess.run(command + ['-r',
        'echo json_encode([extension_loaded("pdo"), extension_loaded("pdo_sqlite")]);'],
        check=True, capture_output=True, text=True)
    pdo, sqlite = json.loads(probe.stdout)
    if not pdo:
        command += ['-d', 'extension=pdo.so']
    if not sqlite:
        command += ['-d', 'extension=pdo_sqlite.so']
    subprocess.run(command + ['-r',
        'if (!extension_loaded("pdo_sqlite")) {fwrite(STDERR,"PDO SQLite required in clean CLI runtime\\n");exit(1);}'], check=True)
    return command


def install():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', default='/www/wwwroot/fecxbrfzku.net')
    parser.add_argument('--state', default='/www/am-app-state/fecxbrfzku.net')
    parser.add_argument('--php', default='/www/server/php/84/bin/php')
    parser.add_argument('--web-user', default='www')
    parser.add_argument('--public-url', default='https://fecxbrfzku.net/am-app')
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
    print('Checking PHP with isolated CLI configuration...', flush=True)
    php = clean_php(args.php)
    for filename in PANEL_FILES:
        subprocess.run(php + ['-l', str(source / filename)], check=True)

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
        for filename in PANEL_FILES:
            shutil.copyfile(source / filename, stage / filename)
        subprocess.run(php + ['-r',
                        'require $argv[1]; \\AmAccount\\SettingsStore::initialize($argv[2]);',
                        str(stage / 'Settings.php'), str(state / 'settings.sqlite')], check=True)
        subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\SquareStore::migrate($argv[2]);',
                              str(stage / 'Square.php'), str(state / 'settings.sqlite')], check=True)
        subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\PricingStore::migrate($argv[2]);',
                              str(stage / 'Pricing.php'), str(state / 'settings.sqlite')], check=True)
        (state / 'gateway.key').write_bytes(os.urandom(32))
        subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\CustomerService::migrate($argv[2]);',
                              str(stage / 'Customer.php'), str(state / 'settings.sqlite')], check=True)
        write_config(stage / 'settings-config.php', state, args.public_url)
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
    print('Customer API installed. Customer access requires the AM worker routes and reseller opt-in. Checkout defaults to sandbox.')
    print('If storage is unavailable, check PHP-FPM open_basedir: a pool-level restriction cannot be expanded by .user.ini.')


if __name__ == '__main__':
    try:
        install()
    except Exception as error:
        raise SystemExit('Installation stopped: ' + str(error))
