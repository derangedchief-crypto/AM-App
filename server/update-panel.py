#!/usr/bin/env python3
"""Back up and update an existing AM module without replacing its settings or keys."""
import argparse
import importlib.util
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import tempfile
import time

spec = importlib.util.spec_from_file_location('install_panel', Path(__file__).with_name('install-panel.py'))
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


def update():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', default='/www/wwwroot/fecxbrfzku.net')
    parser.add_argument('--php', default='/www/server/php/84/bin/php')
    parser.add_argument('--public-url', default='https://fecxbrfzku.net/am-app')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise RuntimeError('Run as root in aaPanel.')
    site = Path(args.site).resolve()
    module = site / 'am-app'
    config = module / 'settings-config.php'
    # Read only the generated literal database path; do not execute portal config.
    match = re.search(r"'database'\s*=>\s*'((?:\\.|[^'\\])*)'", config.read_text())
    if not match:
        raise RuntimeError('Unrecognized AM config; stopping before any changes.')
    database = Path(match.group(1).replace("\\'", "'").replace('\\\\', '\\')).resolve()
    state = database.parent
    if not database.is_file() or site.parent in state.parents or state == site.parent:
        raise RuntimeError('Private AM database path is invalid.')
    owner = database.stat()
    php = installer.clean_php(args.php)
    source = Path(__file__).resolve().parent / 'panel'
    for name in installer.PANEL_FILES:
        if name.endswith('.php'):
            subprocess.run(php + ['-l', str(source / name)], check=True)
    backup = Path(tempfile.mkdtemp(prefix=time.strftime('backup-%Y%m%d-%H%M%S-'), dir=state))
    backup.chmod(0o700)
    for name in (*installer.PANEL_FILES, 'settings-config.php', '.user.ini'):
        if (module / name).is_file():
            shutil.copy2(module / name, backup / name)
    with sqlite3.connect(database) as db, sqlite3.connect(backup / 'settings.sqlite') as snapshot:
        db.backup(snapshot)
    key = state / 'gateway.key'
    if key.exists():
        if len(key.read_bytes()) != 32:
            raise RuntimeError('Existing encryption key is invalid; refusing to replace it.')
        shutil.copy2(key, backup / 'gateway.key')
    else:
        with sqlite3.connect(database) as db:
            if db.execute("SELECT 1 FROM sqlite_master WHERE name='am_square_connections'").fetchone():
                raise RuntimeError('Gateway schema exists but encryption key is missing; restore the original key.')
        with key.open('xb') as handle:
            handle.write(os.urandom(32))
    key.chmod(0o600)
    os.chown(key, owner.st_uid, owner.st_gid)
    subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\SquareStore::migrate($argv[2]);', str(source / 'Square.php'), str(database)], check=True)
    subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\PricingStore::migrate($argv[2]);', str(source / 'Pricing.php'), str(database)], check=True)
    subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\CustomerService::migrate($argv[2]);', str(source / 'Customer.php'), str(database)], check=True)
    subprocess.run(php + ['-r', 'require $argv[1]; \\AmAccount\\Announcements::migrate($argv[2]);', str(source / 'Announcements.php'), str(database)], check=True)
    stage = Path(tempfile.mkdtemp(prefix='.am-update-', dir=site))
    try:
        installer.write_config(stage / 'settings-config.php', state, args.public_url)
        for name in installer.PANEL_FILES:
            shutil.copyfile(source / name, stage / name)
        # Dependencies before controller; each replacement is atomic.
        for name in ('settings-config.php', *installer.PANEL_FILES):
            file = stage / name
            file.chmod(0o600)
            os.chown(file, owner.st_uid, owner.st_gid)
            os.replace(file, module / name)
    finally:
        shutil.rmtree(stage, ignore_errors=True)
    print('AM page updated. Existing preferences and gateway key preserved.')
    print('Private backup: ' + str(backup))
    print('Open ' + args.public_url + '/ and configure Square. No service was restarted.')


if __name__ == '__main__':
    try:
        update()
    except Exception as error:
        raise SystemExit('Update stopped: ' + str(error))
