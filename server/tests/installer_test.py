"""Exercise real installer filesystem/PHP work with only root/chown mocked locally."""
import importlib.util
import os
from pathlib import Path
import pwd
import tempfile
import subprocess
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
print('PASS: isolated install, original files preserved, private permissions, inherited paths, overwrite refusal. Root ownership mocked; aaPanel PHP-FPM acceptance remains live-host verification.')
