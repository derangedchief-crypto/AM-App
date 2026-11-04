"""Synthetic portal-contract integration tests; never calls a real worker."""
import http.cookiejar
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

PHP = os.environ.get('PHP', 'php')
SOURCE = Path(__file__).resolve().parents[1]
BOOTSTRAP = '''<?php
session_start();
function require_login() {
    if (empty($_SESSION['reseller'])) {header('Location: /login.php'); exit;}
    return $_SESSION['reseller'];
}
function call_auth_worker($route, $body) {
    if ($route !== '/panel-user-balance' || $body['panel_user_id'] !== $_SESSION['reseller']['id']) throw new Exception('Wrong scope');
    if (!empty($_SESSION['outage'])) throw new Exception('Worker unavailable');
    return ['ok' => true, 'is_enabled' => !empty($_SESSION['active'])];
}
function url($path) {return '/' . ltrim($path, '/');}
function e($value) {return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');}
function theme_html_attrs() {return '';}
function theme_head() {return '';}
function csrf_field() {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';
}
function csrf_check() {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {http_response_code(419); exit;}
}
'''
FIXTURE = '''<?php
session_start(); $_SESSION = [];
if (isset($_GET['user'])) $_SESSION['reseller'] = ['id' => $_GET['user'], 'username' => 'Fixture reseller'];
$_SESSION['active'] = ($_GET['active'] ?? '1') === '1';
$_SESSION['outage'] = isset($_GET['outage']);
if (isset($_GET['staff'])) $_SESSION['portal_staff'] = ['id' => 1];
if (isset($_GET['impersonation'])) $_SESSION['impersonation'] = ['id' => 1];
'''


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


def run():
    with tempfile.TemporaryDirectory(prefix='am-panel-test-', dir=os.environ.get('TMPDIR', '/tmp/opencode')) as temp:
        root = Path(temp)
        (root / 'includes').mkdir()
        (root / 'includes/bootstrap.php').write_text(BOOTSTRAP)
        (root / 'fixture.php').write_text(FIXTURE)
        shutil.copytree(SOURCE / 'panel', root / 'am-app')
        db = root / 'settings.sqlite'
        subprocess.run([PHP, '-r', 'require $argv[1]; \\AmAccount\\SettingsStore::initialize($argv[2]);',
                        str(root / 'am-app/Settings.php'), str(db)], check=True)
        (root / 'am-app/settings-config.php').write_text("<?php return ['database' => " + repr(str(db)) + "];")
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        process = subprocess.Popen([PHP, '-d', 'session.save_path=' + str(root), '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

        def request(path='/am-app/', data=None):
            encoded = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            try:
                response = opener.open(f'http://127.0.0.1:{port}' + path, encoded, timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            return response.code, response.read().decode(), response.headers

        def login(query):
            assert request('/fixture.php?' + query)[0] == 200

        def form():
            status, body, headers = request()
            assert status == 200, body
            assert 'no-store' in headers['Cache-Control']
            assert 'noindex' in headers['X-Robots-Tag']
            return {key: re.search('name="' + key + '" value="([^"]*)"', body).group(1) for key in ['csrf', 'revision']}

        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.03)
            initial = request()
            assert initial[0] == 302, initial
            for query in ['user=1&active=0', 'user=1&staff=1', 'user=1&impersonation=1', 'user=invalid']:
                login(query)
                assert request()[0] == 403
                assert request(data={'revision': '0'})[0] == 403
            login('user=1&outage=1')
            assert request()[0] == 503
            login('user=1')
            fields = form()
            assert request(data={'revision': '0'})[0] == 419
            assert request(data={**fields, 'csrf': 'wrong'})[0] == 419
            for url in ['http://example.com', 'https://user:password@example.com', 'https://127.0.0.1',
                        'https://billing.local', 'https://example.com/#secret', 'https://example.com/?password=secret']:
                assert request(data={**fields, 'billing_mode': 'external', 'billing_url': url})[0] == 422
            for invalid in [{'customer_policy': 'bad'}, {'gateways[]': ['bad']}, {'revision': '-1'},
                            {'display_name[]': ['bad']}, {'billing_mode': 'integrated'}, {'app_enabled': 'yes'}]:
                assert request(data={**fields, **invalid})[0] == 422
            assert request(data={**fields, 'display_name': '<script>alert(1)</script>', 'reseller_id': '2',
                                 'app_enabled': '1', 'billing_mode': 'external', 'billing_url': 'https://billing.example.com/pay'})[0] == 303
            assert request(data={**fields, 'display_name': 'stale'})[0] == 409
            body = request()[1]
            assert '&lt;script&gt;' in body and '<script>alert' not in body
            assert form()['revision'] == '1'
            login('user=2')
            second = form()
            assert second['revision'] == '0'
            assert 'billing.example.com/pay' not in request()[1]
            assert request(data={**second, 'display_name': 'Second reseller'})[0] == 303
            login('user=1')
            assert 'billing.example.com/pay' in request()[1]
            assert 'Second reseller' not in request()[1]
            connection = sqlite3.connect(db)
            assert connection.execute('SELECT reseller_id, revision FROM am_reseller_settings ORDER BY reseller_id').fetchall() == [('1', 1), ('2', 1)]
            assert connection.execute('SELECT COUNT(*) FROM am_settings_audit').fetchone()[0] == 2
            connection.close()
            db.unlink()
            assert request()[0] == 503
            assert 'fieldset disabled' in request()[1]
            print('PASS: login, CSRF, active state, worker outage, staff/impersonation denial, validation, escaping, tenant isolation, revisions, persistence, audit, storage failure.')
        finally:
            process.terminate()
            process.wait(timeout=5)


if __name__ == '__main__':
    run()
