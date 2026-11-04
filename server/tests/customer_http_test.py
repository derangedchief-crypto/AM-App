"""Exercise the public JSON controller with a synthetic signed-worker boundary."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

PHP = os.environ.get('PHP', 'php')
SOURCE = Path(__file__).resolve().parents[1]
WORKER = '''<?php
function call_auth_worker($route,$body) {
    if ($route==='/am/capabilities') return ['ok'=>true,'version'=>1,'authoritative_ownership'=>true,'idempotent_renewal'=>true];
    if ($route==='/am/customer-auth' && ($body['username']??'')==='customer' && ($body['password']??'')==='secret') {
        return ['ok'=>true,'reseller_id'=>'1','auth_revision'=>'revision','line'=>['line_id'=>'10']];
    }
    if ($route==='/am/customer' && $body===['reseller_id'=>'1','line_id'=>'10']) return ['ok'=>true,'reseller_id'=>'1','auth_revision'=>'revision','line'=>['line_id'=>'10','package_id'=>1,'username'=>'customer','max_connections'=>2,'expire_at'=>1900000000,'activated'=>true,'is_trial'=>false,'bouquets'=>[]]];
    if ($route==='/am/packages') return ['ok'=>true,'packages'=>[package_fixture()]];
    if ($route==='/am/renew-quote') return ['ok'=>true,'line_fingerprint'=>str_repeat('a',64),'package'=>package_fixture()];
    return ['ok'=>false];
}
function package_fixture() { return ['id'=>1,'name'=>'Monthly','price_credits'=>'3.5','duration'=>30,'duration_period'=>'days','duration_hours'=>720,'max_connections'=>2,'package_type'=>'0','worker_fingerprint'=>str_repeat('b',64)]; }
'''


def run():
    with tempfile.TemporaryDirectory(prefix='am-customer-http-',dir='/tmp/opencode') as tmp:
        root = Path(tmp)
        (root/'includes').mkdir()
        (root/'includes/functions.php').write_text(WORKER)
        (root/'includes/auth.php').write_text('<?php')
        shutil.copytree(SOURCE/'panel',root/'am-app')
        (root/'key').write_bytes(os.urandom(32))
        (root/'am-app/settings-config.php').write_text("<?php return ['database'=>"+repr(str(root/'state.sqlite'))+",'secret_key'=>"+repr(str(root/'key'))+",'public_url'=>'https://example.com/am-app'];")
        seed = r'''
        $root=$argv[1]; require $root.'/includes/functions.php';
        foreach (['Settings','Pricing','Square','Customer'] as $name) require $root.'/am-app/'.$name.'.php';
        $c=require $root.'/am-app/settings-config.php';
        \AmAccount\SettingsStore::initialize($c['database']); \AmAccount\PricingStore::migrate($c['database']);
        \AmAccount\SquareStore::migrate($c['database']); \AmAccount\CustomerService::migrate($c['database']);
        $s=array_replace(\AmAccount\Settings::defaults(),['app_enabled'=>true,'customer_policy'=>'all','allow_renewals'=>true,'billing_mode'=>'integrated','gateways'=>['square']]);
        (new \AmAccount\SettingsStore($c['database']))->save('1',0,$s);
        $cat=\AmAccount\PricingStore::catalogue(['ok'=>true,'packages'=>[package_fixture()]]);
        (new \AmAccount\PricingStore($c['database']))->save('1',['package_id'=>'1','currency'=>'CAD','amount'=>'0','revision'=>'0','fingerprint'=>$cat[1]['fingerprint'],'enabled'=>'1'],$cat);
        '''
        subprocess.run([PHP,'-r',seed,str(root)],check=True)
        subprocess.run(['python3',str(SOURCE/'check-customer-api.py'),'--site',str(root),'--php',PHP],check=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        token=''

        def request(body=None,raw=None):
            headers={'Content-Type':'application/json'}
            if token: headers['Authorization']='Bearer '+token
            data=raw if raw is not None else json.dumps(body).encode() if body is not None else None
            req=urllib.request.Request(f'http://127.0.0.1:{port}/am-app/customer-api.php',data=data,headers=headers)
            try: response=urllib.request.urlopen(req,timeout=5)
            except urllib.error.HTTPError as error: response=error
            text=response.read().decode()
            assert 'no-store' in response.headers['Cache-Control']
            return response.code,json.loads(text)

        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                except OSError: time.sleep(.03)
            assert request()[0]==405
            assert request(raw=b'{bad')[0]==422
            assert request(raw=b'x'*16385)[0]==413
            assert request({'action':'account'})[0]==401
            assert request({'action':'login','username':'customer','password':'wrong'})[0]==401
            status,login=request({'action':'login','username':'customer','password':'secret'})
            assert status==200,login
            token=login['token']
            assert request({'action':'account','reseller_id':'2'})[0]==422
            assert request({'action':'quote','package_id':'1','currency':'CAD'})[0]==422
            assert request({'action':'quote','package_id':2,'currency':'CAD'})[0]==403
            status,quote=request({'action':'quote','package_id':1,'currency':'CAD'})
            assert status==200,quote
            qid=quote['quote']['quote_id']
            assert request({'action':'checkout','quote_id':qid,'amount_minor':0})[0]==422
            status,order=request({'action':'checkout','quote_id':qid})
            assert status==200 and order['order']['state']=='sandbox_verified',order
            assert request({'action':'checkout','quote_id':qid})[1]['order']['id']==order['order']['id']
            assert len(request({'action':'orders'})[1]['orders'])==1
            assert request({'action':'order','order_id':'f'*32})[0]==404
            assert request({'action':'logout'})[0]==200
            assert request({'action':'orders'})[0]==401
            print('PASS: customer HTTP login/bearer, validation, forged scope/amount rejection, renewal-only, checkout replay, history, logout.')
        finally:
            server.terminate(); server.wait(timeout=5)


if __name__=='__main__': run()
