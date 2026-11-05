"""Synthetic portal-contract integration tests; never calls a real worker."""
import http.cookiejar
import json
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
    if ($route === '/am/admin-resellers') {
        if (!empty($_SESSION['outage'])) throw new Exception('Worker unavailable');
        $rows=[['id'=>'1','username'=>'Alpha reseller','enabled'=>true],['id'=>'2','username'=>'Beta reseller','enabled'=>true]];
        return ['ok'=>true,'more'=>false,'resellers'=>array_values(array_filter($rows,static fn($r)=>isset($body['reseller_ids'])?in_array($r['id'],$body['reseller_ids'],true):stripos($r['username'],$body['query'] ?? '')!==false))];
    }
    if ($route === '/am/customer') {
        if ($body !== ['reseller_id'=>(string)$_SESSION['reseller']['id'],'line_id'=>'10']) return ['ok'=>false];
        return ['ok'=>true,'reseller_id'=>(string)$_SESSION['reseller']['id'],'auth_revision'=>'fixture','line'=>['line_id'=>'10','package_id'=>(int)$_SESSION['reseller']['id'],'package_name'=>'Fixture package','max_connections'=>2,'expire_at'=>1900000000,'activated'=>true]];
    }
    if ($route === '/am/packages') {
        if ($body['reseller_id'] !== (string)$_SESSION['reseller']['id']) throw new Exception('Wrong scope');
        return ['ok'=>true,'packages'=>[['id'=>(int)$_SESSION['reseller']['id'],'name'=>'Fixture package','price_credits'=>'3.50','max_connections'=>2,'duration'=>1,'duration_period'=>'months']]];
    }
    if ($route === '/packages' && $body['username'] === $_SESSION['reseller']['username']) {
        if (!empty($_SESSION['many'])) {
            $packages = [];
            for ($i=1;$i<=37;$i++) $packages[] = ['id'=>$i,'name'=>($i===37 ? '<script>unsafe</script>' : 'Package '.$i),'price_credits'=>(string)$i,'max_connections'=>$i%3+1,'duration'=>$i%2+1,'duration_period'=>'months'];
            return ['ok'=>true,'packages'=>$packages];
        }
        return ['ok' => true, 'packages' => [['id' => (int)$_SESSION['reseller']['id'], 'name' => 'Fixture package', 'price_credits' => '3.50', 'max_connections' => 2, 'duration' => 1, 'duration_period' => 'months']]];
    }
    if ($route !== '/panel-user-balance' || $body['panel_user_id'] !== $_SESSION['reseller']['id']) throw new Exception('Wrong scope');
    if (!empty($_SESSION['outage'])) throw new Exception('Worker unavailable');
    return ['ok' => true, 'is_enabled' => !empty($_SESSION['active'])];
}
function url($path) {return '/' . ltrim($path, '/');}
function e($value) {return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');}
function theme_html_attrs() {return '';}
function theme_head() {return '';}
function is_local_admin() {return !empty($_SESSION['is_local_admin']);}
function require_local_admin() {if (!is_local_admin()) {http_response_code(403);exit;}}
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
$_SESSION['many'] = isset($_GET['many']);
if (isset($_GET['admin'])) $_SESSION['is_local_admin'] = true;
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
        (root / 'includes/functions.php').write_text('<?php')
        (root / 'includes/auth.php').write_text('<?php')
        (root / 'fixture.php').write_text(FIXTURE)
        shutil.copytree(SOURCE / 'panel', root / 'am-app')
        db = root / 'settings.sqlite'
        subprocess.run([PHP, '-r', 'require $argv[1]; \\AmAccount\\SettingsStore::initialize($argv[2]);',
                        str(root / 'am-app/Settings.php'), str(db)], check=True)
        subprocess.run([PHP, '-r', 'require $argv[1]; \\AmAccount\\Announcements::migrate($argv[2]);',
                        str(root / 'am-app/Announcements.php'), str(db)], check=True)
        subprocess.run([PHP, '-r', 'require $argv[1]; \\AmAccount\\SquareStore::migrate($argv[2]);',
                        str(root / 'am-app/Square.php'), str(db)], check=True)
        subprocess.run([PHP, '-r', 'require $argv[1]; \\AmAccount\\PricingStore::migrate($argv[2]);',
                        str(root / 'am-app/Pricing.php'), str(db)], check=True)
        (root / 'gateway.key').write_bytes(os.urandom(32))
        subprocess.run([PHP, '-r', 'require $argv[1]; \\AmAccount\\CustomerService::migrate($argv[2]);',
                        str(root / 'am-app/Customer.php'), str(db)], check=True)
        subprocess.run([PHP, '-r', 'require $argv[1]; $s=new \\AmAccount\\Licenses($argv[2]); foreach (["1","2"] as $id) {$k=$s->issue($id,null);$s->redeem($id,$k["key"]);}',
                        str(root / 'am-app/Licenses.php'), str(db)], check=True)
        (root / 'am-app/settings-config.php').write_text("<?php return ['database' => " + repr(str(db)) + ", 'secret_key' => " + repr(str(root / 'gateway.key')) + ", 'public_url' => 'https://panel.example.com/am-app'];")
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
            admin='/am-app/announcements-admin.php'
            feed='/am-app/announcements.php'
            assert json.loads(request(feed)[1])['announcements']==[]
            assert request(feed, {})[0]==405
            for query in ['', 'user=1', 'admin=1&staff=1', 'admin=1&impersonation=1']:
                login(query)
                assert request(admin)[0]==403
                assert request(admin, {'action':'save'})[0]==403
            login('admin=1')
            page=request(admin)
            assert page[0]==200
            csrf=re.search('name="csrf" value="([^"]*)"',page[1]).group(1)
            notice={'csrf':csrf,'action':'save','id':'','revision':'0','title':'<script>alert(1)</script>',
                    'body':'Synthetic outage notice','severity':'warning','expires':time.strftime('%Y-%m-%dT%H:%M',time.gmtime(time.time()+3600))}
            assert request(admin, {**notice,'csrf':'wrong'})[0]==419
            assert request(admin, {**notice,'severity':'invalid'})[0]==422
            assert request(admin, notice)[0]==303
            rows=json.loads(request(feed)[1])['announcements']
            assert len(rows)==1 and rows[0]['revision']==1
            assert '<script>alert(1)</script>' not in request(admin)[1]
            notice.update(id=rows[0]['id'],revision='1',body='Updated outage')
            assert request(admin, notice)[0]==303
            assert request(admin, notice)[0]==422
            assert json.loads(request(feed)[1])['announcements'][0]['revision']==2
            assert request(admin, {**notice,'revision':'2','action':'withdraw'})[0]==303
            assert json.loads(request(feed)[1])['announcements']==[]
            assert request(admin, {**notice,'revision':'3'})[0]==422
            assert request(admin, {**notice,'action':'delete','revision':'2'})[0]==422
            assert request(admin, {**notice,'action':'delete','revision':'3','csrf':'wrong'})[0]==419
            for query in ['user=1','admin=1&staff=1','admin=1&impersonation=1']:
                login(query)
                assert request(admin, {**notice,'action':'delete','revision':'3'})[0]==403
            login('admin=1')
            csrf=re.search('name="csrf" value="([^"]*)"',request(admin)[1]).group(1)
            notice['csrf']=csrf
            assert request(admin, {**notice,'action':'delete','revision':'3'})[0]==303
            assert notice['id'] not in request(admin)[1]
            assert request(admin, {**notice,'action':'delete','revision':'3'})[0]==422
            assert request(admin, {**notice,'id':'','revision':'0'})[0]==303
            active=json.loads(request(feed)[1])['announcements'][0]
            assert request(admin, {**notice,'id':active['id'],'revision':'1','action':'delete'})[0]==303
            assert json.loads(request(feed)[1])['announcements']==[]
            assert request(admin, {**notice,'id':'','revision':'0'})[0]==303
            with sqlite3.connect(db) as connection:
                connection.execute('UPDATE am_announcements SET expires=1')
            assert json.loads(request(feed)[1])['announcements']==[]
            with sqlite3.connect(db) as connection:
                expired=connection.execute('SELECT id,revision FROM am_announcements LIMIT 1').fetchone()
            assert request(admin, {**notice,'id':expired[0],'revision':str(expired[1]),'action':'delete'})[0]==303
            login('')
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
            with sqlite3.connect(db) as connection:
                own_license=connection.execute('SELECT id FROM am_licenses WHERE reseller_id="1"').fetchone()[0]
                other_license=connection.execute('SELECT id FROM am_licenses WHERE reseller_id="2"').fetchone()[0]
                connection.execute('UPDATE am_licenses SET expires=? WHERE reseller_id="1"',(2000000000,))
            body=request()[1]
            assert own_license in body and other_license not in body
            assert 'Time-limited' in body and '18 May 2033' in body
            with sqlite3.connect(db) as connection:
                connection.execute('UPDATE am_licenses SET expires=NULL WHERE reseller_id="1"')
            assert 'No scheduled expiry' in request()[1]
            assert request(data={'action': 'package_price'})[0] == 419
            price_fields = {key: re.search('name="' + key + '" value="([^"]*)"', request()[1]).group(1) for key in ['package_id', 'fingerprint']}
            price_input = {**fields, **price_fields, 'action': 'package_price', 'currency': 'CAD', 'amount': '20.00', 'enabled': '1', 'reseller_id': '2', 'price_credits': '0'}
            assert request(data=price_input)[0] == 303
            assert request(data=price_input)[0] == 409
            assert request(data={'action': 'square_connect'})[0] == 419
            assert request(data={**fields, 'action': 'square_connect', 'mode': 'sandbox', 'token': 'invalid', 'location': 'loc1'})[0] == 422
            assert request(data={**fields, 'action': 'square_webhook', 'connection': 'other-reseller', 'webhook_key': 'fake-secret-key123456'})[0] == 422
            assert request(data={**fields, 'action': 'square_checkout', 'connection': 'other-reseller'})[0] == 422
            assert request('/am-app/square-webhook.php')[0] == 405
            assert request('/am-app/square-webhook.php?connection=' + 'a' * 32, data={})[0] == 400
            assert request(data={'revision': '0'})[0] == 419
            assert request(data={**fields, 'csrf': 'wrong'})[0] == 419
            for url in ['http://example.com', 'https://user:password@example.com', 'https://127.0.0.1',
                        'https://billing.local', 'https://example.com/#secret', 'https://example.com/?password=secret']:
                assert request(data={**fields, 'billing_mode': 'external', 'billing_url': url})[0] == 422
            for invalid in [{'customer_policy': 'bad'}, {'gateways[]': ['bad']}, {'revision': '-1'},
                            {'display_name[]': ['bad']}, {'billing_mode': 'integrated'}, {'app_enabled': 'yes'}]:
                assert request(data={**fields, **invalid})[0] == 422
            assert request(data={**fields, 'display_name': '<script>alert(1)</script>', 'reseller_id': '2',
                                 'app_enabled': '1', 'customer_policy': 'all', 'billing_mode': 'external', 'billing_url': 'https://billing.example.com/pay'})[0] == 303
            assert request(data={**fields, 'display_name': 'stale'})[0] == 409
            body = request()[1]
            assert '&lt;script&gt;' in body and '<script>alert' not in body
            assert form()['revision'] == '1'
            assert request(data={'action':'renewal_diagnostic','line_id':'10'})[0] == 419
            assert request(data={**form(),'action':'renewal_diagnostic','line_id':'10','reseller_id':'2'})[0] == 303
            diagnostic_body = request()[1]
            assert 'general customer renewal permission is off' in diagnostic_body
            assert 'Service #10' in diagnostic_body and 'Assigned package #1' in diagnostic_body
            assert request(data={**form(),'action':'renewal_diagnostic','line_id':'11'})[0] == 409
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
            login('user=1&many=1')
            body = request('/am-app/?sort=name')[1]
            assert body.count('class="am-package"') == 12
            assert 'Page 1 of 4' in body
            assert request('/am-app/?page=999')[1].count('class="am-package"') == 1
            assert 'Page 1 of 4' in request('/am-app/?page=-1')[1]
            filtered = request('/am-app/?connections=2&term=2+months')[1]
            assert filtered.count('class="am-package"') == 7
            assert 'No packages match' in request('/am-app/?q=missing-package')[1]
            assert '&lt;script&gt;unsafe&lt;/script&gt;' in request('/am-app/?q=unsafe')[1]
            assert '<script>unsafe</script>' not in request('/am-app/?q=unsafe')[1]
            assert request('/am-app/?price_status=unset')[1].count('class="am-package"') == 12
            path = '/am-app/?q=Package+20&connections=3&prices_currency=USD&sort=credits'
            body = request(path)[1]
            price_fields = {key: re.search('name="'+key+'" value="([^"]*)"',body).group(1) for key in ['csrf','package_id','fingerprint']}
            saved_price = request(path, {**price_fields,'action':'package_price','revision':'0','currency':'USD','amount':'12.50','enabled':'1'})
            assert saved_price[0] == 303, saved_price
            assert 'q=Package+20' in saved_price[2]['Location'] and 'connections=3' in saved_price[2]['Location']
            assert '12.50 USD' in request('/am-app/?price_status=enabled&prices_currency=USD')[1]
            assert request(data={'action':'promotion_save'})[0] == 419
            assert request(data={'action':'promotion_disable'})[0] == 419
            assert request(data={'action':'package_name'})[0] == 419
            assert request('/am-app/?price_status=enabled&prices_currency=USD')[1].count('class="am-package"') == 1
            if os.environ.get('AM_BROWSER_TEST'):
                subprocess.run(['node',str(SOURCE / 'tests/panel_browser_test.cjs'),f'http://127.0.0.1:{port}'],check=True)
            login('user=1')
            assert request('/am-app/licenses-admin.php')[0] == 403
            assert request('/am-app/licenses-admin.php?reseller_search=Alpha')[0] == 403
            for query in ['user=1&admin=1&staff=1','user=1&admin=1&impersonation=1']:
                login(query)
                assert request('/am-app/licenses-admin.php')[0] == 403
                assert request('/am-app/licenses-admin.php?reseller_search=Alpha')[0] == 403
            login('user=1&admin=1')
            admin_path='/am-app/licenses-admin.php'
            admin_body=request(admin_path)[1]
            assert 'Alpha reseller' in admin_body and 'name="reseller_id" inputmode' not in admin_body
            assert 'Beta reseller' not in request(admin_path+'?reseller_search=Alpha')[1]
            assert '"resellers":[]' in request(admin_path+'?reseller_search=missing')[1]
            csrf=re.search('name="csrf" value="([^"]*)"',admin_body).group(1)
            assert request(admin_path,{'csrf':csrf,'action':'issue','reseller_id':'999','expires':''})[0] == 422
            login('user=1&admin=1&outage=1')
            assert request(admin_path+'?reseller_search=Alpha')[0] == 503
            assert 'Reseller list unavailable' in request(admin_path)[1]
            login('user=1&admin=1')
            csrf=re.search('name="csrf" value="([^"]*)"',request(admin_path)[1]).group(1)
            assert request(admin_path,{'action':'issue','reseller_id':'1','expires':''})[0] == 419
            assert request(admin_path,{'csrf':csrf,'action':'issue','reseller_id':'1','expires':''})[0] == 422
            assert request(admin_path,{'csrf':csrf,'action':'issue','reseller_id':'1','expires':'','replace':'1'})[0] == 303
            body=request(admin_path)[1]
            key=re.search(r'AM-[A-F0-9]{40}',body).group()
            assert key not in request(admin_path)[1]
            login('user=1')
            status,body,_=request()
            assert status == 403 and 'Activate license' in body and 'Save app settings' not in body
            csrf=re.search('name="csrf" value="([^"]*)"',body).group(1)
            assert request(data={'csrf':csrf,'action':'settings','revision':'1'})[0] == 403
            assert request(data={'action':'license_activate','license_key':key})[0] == 419
            assert request(data={'csrf':csrf,'action':'license_activate','license_key':key})[0] == 303
            assert request()[0] == 200
            db.unlink()
            assert request()[0] == 503
            assert 'License verification is unavailable' in request()[1]
            print('PASS: panel access/security, persistence, catalogue pagination, combined filters, price save context, currency isolation and storage failure.')
        finally:
            process.terminate()
            process.wait(timeout=5)


if __name__ == '__main__':
    run()
