#!/usr/bin/env python3
"""Validate/install additive AM worker routes, preserving existing handler ASTs."""
import argparse
import ast
import datetime
import hashlib
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

MARKER = '# AM_ACCOUNT_CONTRACT_V1'
UPGRADE_FROM = {'0142cf410c12082edcd0c5116f9966e969ef733ca79bd3126ffe47d6a7004d3a',
                '8492411b183b4177847e91eaced32c80be00b33743280bc228eb42db63c4f9cf',
                '5dae9848c8eedfef24eb6bcc877a15fed1e0cc27481674ca6243494f51c62343',
                '285c798f3f93387647a37aa863c6fafbd8f0bdd2671d7e635cb61ae02cad4527'}
EXPECTED = {
    'renew_line': '3b7baffb0c5c8a74d7bc36b0492122635c85ee960e9d160c347c0d23dac258ea',
    '_set_credits': '18fef9783e925c40a3cc1264fd2fc0ded7e8b0dfb1a94457fa1a2c21ecd7916e',
    '_lock_reseller_row': 'c616240f54a76c2f8c58ab2987ce0e14a434332fb41f6e3a96f80c0b73e6022d',
    '_lock_line_row': '5acd3a21e900ad94fa16c49bc34c5fa3a4bf78fdd1645e8e0d67d613d8dac590',
    '_reseller_package_scope': '77c8efd1eb68d1dfc04f8795cda0075a96ca8dc4b0d23cdb62a45db69f951bfa',
    '_package_allowed': '59613d963944039bc741ee0229cfb80c2fb8da8580c10a90a6673ee5368d008d',
    '_overcast_refresh_customer': '869dbf77718f227a7a91a8e65e4256f29679451618d69c872ec690a17f17f0eb',
    '_renewal_window': 'adc38b248be13552811f471397ab7465efb53e0de345e9c886a37b28898a2aa6',
}


def definitions(source):
    return {node.name: ast.dump(node, include_attributes=False) for node in ast.parse(source).body
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef))}


def validate(source):
    before = definitions(source)
    for name, expected in EXPECTED.items():
        actual = hashlib.sha256(before.get(name, '').encode()).hexdigest()
        if actual != expected:
            raise ValueError('Worker contract changed at ' + name + '; refusing to guess or overwrite it.')
    if MARKER in source:
        return source
    if 'am_account_worker' in source:
        raise ValueError('Unrecognized AM registration already exists.')
    patched = source.rstrip() + '\n\n' + MARKER + '\nfrom am_account_worker import register as _am_account_register\n_am_account_register(app, globals())\n'
    if definitions(patched) != before:
        raise ValueError('An existing handler changed; refusing install.')
    compile(patched, '<worker>', 'exec')
    return patched


def matching_service(directory):
    units = subprocess.run(['systemctl', 'list-units', '--type=service', '--state=running', '--no-legend', '--plain'], check=True, capture_output=True, text=True).stdout
    matches = []
    for line in units.splitlines():
        if not line.split():
            continue
        unit = line.split()[0]
        properties = subprocess.run(['systemctl', 'show', unit, '--property=WorkingDirectory,ExecStart'], check=True, capture_output=True, text=True).stdout
        data = dict(item.split('=', 1) for item in properties.splitlines() if '=' in item)
        command = data.get('ExecStart', '')
        if data.get('WorkingDirectory') == str(directory) and ('main:app' in command or str(directory / 'main.py') in command):
            matches.append(unit)
    if len(matches) != 1:
        raise ValueError('Could not uniquely identify the running worker service; use --apply without --restart and report the service name.')
    return matches[0]


def atomic_write(path, content, stat):
    descriptor, temp = tempfile.mkstemp(prefix='.am-worker-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, stat.st_mode & 0o777)
        os.chown(temp, stat.st_uid, stat.st_gid)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--worker', type=Path, default=Path('/opt/iptv-auth-worker/main.py'))
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--restart', action='store_true', help='Restart only the uniquely matched running systemd worker service')
    args = parser.parse_args()
    if args.restart and not args.apply:
        parser.error('--restart requires --apply')
    path = args.worker.resolve()
    source = path.read_text()
    patched = validate(source)
    module = Path(__file__).with_name('am_account_worker.py')
    module_text = module.read_text()
    compile(module_text, str(module), 'exec')
    destination = path.with_name(module.name)
    if destination.exists() and destination.read_text() != module_text:
        if hashlib.sha256(destination.read_bytes()).hexdigest() not in UPGRADE_FROM:
            raise ValueError('Unrecognized AM worker module already exists; refusing replacement.')
    if MARKER in source and not destination.exists():
        raise ValueError('AM registration exists but its module is missing; restore the matching module first.')
    print('Validated deployed renewal/credit helpers. Existing endpoint definitions remain unchanged.', flush=True)
    if not args.apply:
        print('Read-only validation complete. Use --apply to install and optionally --restart.')
        return
    service = matching_service(path.parent) if args.restart else None
    stat = path.stat()
    backup = path.with_name(path.name + '.before-am-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ'))
    shutil.copy2(path, backup)
    if destination.exists():
        shutil.copy2(destination, backup.with_name(backup.name + '.am-module.py'))
    os.chdir(path.parent)
    sys.path.insert(0, str(path.parent))
    spec = importlib.util.spec_from_file_location('_am_existing_worker', path)
    worker = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = worker
    spec.loader.exec_module(worker)
    try:
        if not callable(getattr(worker, 'enforce_trial_policy', None)):
            raise ValueError('Current trial policy is unavailable.')
        from am_account_worker import SCHEMA
        # The bundled module is not on the worker path yet on first install.
    except ModuleNotFoundError as error:
        if error.name != 'am_account_worker':
            raise
        spec_am = importlib.util.spec_from_file_location('_am_bundle', module)
        bundle = importlib.util.module_from_spec(spec_am)
        spec_am.loader.exec_module(bundle)
        SCHEMA = bundle.SCHEMA
    try:
        with worker.panel_db_cursor() as (cur, _):
            tables = ('users', 'customers', 'users_packages', 'users_credits_log', 'portal_trial_renewals')
            for table in tables:
                cur.execute('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', (table,))
                row = cur.fetchone()
                if not row or str(row['ENGINE']).lower() != 'innodb':
                    raise ValueError('Atomic renewal requires InnoDB table: ' + table)
            cur.execute(SCHEMA)
            cur.execute("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='am_renewal_results'")
            if str(cur.fetchone()['ENGINE']).lower() != 'innodb':
                raise ValueError('AM journal must use InnoDB.')
            cur.execute('SELECT order_id,reseller_id,line_id,request_hash,result_json,refreshed,created_at FROM am_renewal_results LIMIT 0')
            cur.fetchall()
            cur.execute("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='am_renewal_results' AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION")
            if [row['COLUMN_NAME'] for row in cur.fetchall()] != ['order_id']:
                raise ValueError('AM journal must have a unique primary key on order_id.')
    finally:
        worker._stop_tunnel()
    if path.read_text() != source:
        raise ValueError('Worker file changed during install; no source files replaced.')
    atomic_write(destination, module_text, stat)
    atomic_write(path, patched, stat)
    print('Installed AM routes and journal. Existing customer records and credits were not modified.')
    print('Worker backup: ' + str(backup))
    if service:
        subprocess.run(['systemctl', 'restart', service], check=True)
        subprocess.run(['systemctl', 'is-active', '--quiet', service], check=True)
        print('Restarted ' + service + '. Service is active.')
    else:
        print('Restart the worker service to load the new routes. No service was restarted.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('AM worker install stopped: ' + str(error))
