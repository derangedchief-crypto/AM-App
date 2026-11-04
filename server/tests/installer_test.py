"""Exercise real installer filesystem/PHP work with only root/chown mocked locally."""
import importlib.util
import os
from pathlib import Path
import pwd
import tempfile
import subprocess
import sqlite3
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('installer', Path(__file__).resolve().parents[1] / 'install-panel.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
for loaded, extensions in [('[true,true]', []), ('[true,false]', ['-d', 'extension=pdo_sqlite.so']),
                            ('[false,false]', ['-d', 'extension=pdo.so', '-d', 'extension=pdo_sqlite.so'])]:
    with patch.object(installer.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, stdout=loaded)) as run:
        assert installer.clean_php('/host/php') == ['/host/php', '-n', *extensions]
        assert all(call.args[0][:2] == ['/host/php', '-n'] for call in run.call_args_list)
with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR', '/tmp/opencode')) as temporary:
    root = Path(temporary)
    site = root / 'wwwroot/example.test'
    (site / 'includes').mkdir(parents=True)
    (site / 'includes/bootstrap.php').write_text('<?php // unchanged fixture')
    (site / 'config.php').write_text('<?php return [];')
    (site / '.user.ini').write_text(f'open_basedir="{site}:/tmp:/existing/path"\n')
    before = {str(p): p.read_bytes() for p in site.rglob('*') if p.is_file()}
    state = root / 'private/example.test'
    argv = ['install-panel.py', '--site', str(site), '--state', str(state), '--php', os.environ.get('PHP', 'php'),
            '--web-user', pwd.getpwuid(os.getuid()).pw_name]
    with patch('sys.argv', argv), patch.object(installer.os, 'geteuid', return_value=0), patch.object(installer.os, 'chown'):
        installer.install()
        for filename, content in before.items():
            assert Path(filename).read_bytes() == content
        assert (state / 'settings.sqlite').stat().st_mode & 0o777 == 0o600
        ini = (site / 'am-app/.user.ini').read_text()
        assert '/existing/path' in ini and str(state) in ini and str(site) in ini
        try:
            installer.install()
        except RuntimeError as error:
            assert 'refusing to overwrite' in str(error)
        else:
            raise AssertionError('Reinstall must refuse existing state')
        assert (state / 'settings.sqlite').exists()
    # Upgrade a pre-gateway installation with saved reseller preferences.
    with sqlite3.connect(state / 'settings.sqlite') as db:
        db.execute("INSERT INTO am_reseller_settings VALUES ('7',1,'{}',1)")
        for table in ('am_square_connections', 'am_square_merchants', 'am_square_tests'):
            db.execute('DROP TABLE ' + table)
    (state / 'gateway.key').unlink()
    (site / 'am-app/settings-config.php').write_text("<?php return ['database' => '" + str(state / 'settings.sqlite') + "'];")
    update_spec = importlib.util.spec_from_file_location('updater', Path(__file__).resolve().parents[1] / 'update-panel.py')
    updater = importlib.util.module_from_spec(update_spec)
    update_spec.loader.exec_module(updater)
    with patch('sys.argv', ['update-panel.py', '--site', str(site), '--php', os.environ.get('PHP', 'php')]), patch.object(updater.os, 'geteuid', return_value=0), patch.object(updater.os, 'chown'):
        updater.update()
        key = (state / 'gateway.key').read_bytes()
        updater.update()
        assert len(key) == 32 and key == (state / 'gateway.key').read_bytes()
        with sqlite3.connect(state / 'settings.sqlite') as db:
            assert db.execute('SELECT reseller_id,revision FROM am_reseller_settings').fetchall() == [('7', 1)]
            assert db.execute('SELECT COUNT(*) FROM am_square_connections').fetchone()[0] == 0
        assert len(list(state.glob('backup-*'))) == 2
        for filename, content in before.items():
            assert Path(filename).read_bytes() == content
        print('PASS: legacy upgrade, SQLite backup, repeated update preserves settings/key, existing portal files unchanged.')
print('PASS: isolated install, original files preserved, private permissions, inherited paths, overwrite refusal. Root ownership mocked; aaPanel PHP-FPM acceptance remains live-host verification.')
