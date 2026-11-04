#!/usr/bin/env python3
"""Check installed private AM schema and signed worker capability without a customer login."""
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--site',default='/www/wwwroot/fecxbrfzku.net')
parser.add_argument('--php',default='/www/server/php/84/bin/php')
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('installer',Path(__file__).with_name('install-panel.py'))
installer = importlib.util.module_from_spec(spec); spec.loader.exec_module(installer)
php = installer.clean_php(args.php)
extensions = json.loads(subprocess.check_output(php+['-r','echo json_encode([extension_loaded("curl"),extension_loaded("openssl")]);'],text=True))
for name, loaded in zip(['curl','openssl'],extensions):
    if not loaded: php += ['-d','extension='+name+'.so']
program = r'''
ini_set('display_errors','0');
try {
    $root=$argv[1];
    require $root.'/includes/functions.php'; require $root.'/includes/auth.php';
    foreach (['Settings','Pricing','Square','Customer'] as $name) require $root.'/am-app/'.$name.'.php';
    $config=require $root.'/am-app/settings-config.php';
    $service=new \AmAccount\CustomerService($config,static fn($p,$b)=>call_auth_worker($p,$b),new \AmAccount\Square());
    $db=new PDO('sqlite:'.$config['database']); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    foreach (['am_customer_sessions','am_customer_access','am_customer_limits','am_customer_quotes','am_customer_orders','am_package_prices','am_square_connections'] as $table) $db->query('SELECT 1 FROM '.$table.' LIMIT 0');
    $r=call_auth_worker('/am/capabilities',[]);
    if (($r['ok']??false)!==true || ($r['version']??null)!==1 || empty($r['idempotent_renewal']) || empty($r['authoritative_ownership']) || empty($r['bouquet_editing']) || empty($r['unlimited_bouquets'])) throw new RuntimeException('Worker capabilities not confirmed');
    echo "PASS: AM storage, private key and signed worker ownership/idempotent renewal capabilities are ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"AM readiness failed. Check the worker add-on, website update and configured worker connection. No credentials were printed.\n"); exit(1);
}
'''
subprocess.run(php+['-r',program,str(Path(args.site).resolve())],check=True)
