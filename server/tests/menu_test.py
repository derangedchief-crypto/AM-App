"""Check additive navigation patches without deploying or modifying a live portal."""
import importlib.util
from pathlib import Path
import tempfile
import os
from unittest.mock import patch

source=Path(__file__).resolve().parents[1] / 'install-menus.py'
spec=importlib.util.spec_from_file_location('menus',source)
menus=importlib.util.module_from_spec(spec); spec.loader.exec_module(menus)
with tempfile.TemporaryDirectory(dir='/tmp/opencode') as directory:
    root=Path(directory)/'web/site'; (root/'includes').mkdir(parents=True)
    header=root/'includes/layout_header.php'; admin=root/'includes/admin_shell.php'
    header.write_text('KEEP BEFORE\n<nav class="nav-group"><a href="<?= e(url(\'dashboard.php\')) ?>">Dashboard</a><a href="<?= e(url(\'sub-resellers.php\')) ?>">Sub-resellers</a><a href="<?= e(url(\'credits.php\')) ?>">Credits</a></nav>\nKEEP AFTER')
    admin.write_text("<?php function admin_menu(): array {return [\n            ['credit-audit', 'Credit log audit', 'credit-audit.php', 'check'],\n];}")
    original=header.read_text()
    original_admin=admin.read_text()
    pending=menus.changes(root); assert len(pending)==2
    for path,old,new in pending: path.write_text(new)
    assert header.read_text().replace(menus.RESELLER_LINK,'')==original
    assert menus.changes(root)==[]
    updated=header.read_text()
    updated_admin=admin.read_text()
    assert 'My TV licenses' in updated_admin
    header.write_text(updated.replace(menus.RESELLER_LINK,menus.PREVIOUS_APPLICATIONS_LINK))
    admin.write_text(updated_admin.replace(menus.ADMIN_LINK,menus.OLD_ADMIN_LINK))
    pending=menus.changes(root); assert len(pending)==2
    for path,old,new in pending: path.write_text(new)
    assert header.read_text()==updated and admin.read_text()==updated_admin
    assert menus.changes(root)==[]
    assert updated.index('Sub-resellers</a>') < updated.index('>Applications</div>') < updated.index('My TV') < updated.index('>Credits</a>')
    header.write_text(original.replace('<nav class="nav-group">','<nav class="nav-group">'+menus.LEGACY_RESELLER_LINK))
    pending=menus.changes(root); assert len(pending)==1
    assert pending[0][2]==updated
    header.write_text(pending[0][2])
    assert menus.changes(root)==[]
    header.write_text('Unknown local layout')
    try: menus.changes(root)
    except RuntimeError: pass
    else: raise AssertionError('Unknown layout was not rejected')
    header.write_text(original); admin.write_text(original_admin)
    (root/'am-app').mkdir(); (root/'am-app/licenses-admin.php').write_text('<?php')
    state=Path(directory)/'private'; state.mkdir()
    argv=['install-menus.py','--site',str(root),'--state',str(state),'--php',os.environ.get('PHP','php')]
    with patch('sys.argv',argv),patch.object(menus.os,'geteuid',return_value=0),patch.object(menus.os,'chown'):
        menus.install()
        installed=(header.read_bytes(),admin.read_bytes())
        menus.install()
        assert installed==(header.read_bytes(),admin.read_bytes())
    backups=list(state.glob('menu-backup-*'))
    assert len(backups)==1
    assert (backups[0]/header.name).read_text()==original
    assert (backups[0]/admin.name).read_text()==original_admin
print('PASS: additive menu patches, preserved surrounding content, idempotence and unknown-layout refusal.')
