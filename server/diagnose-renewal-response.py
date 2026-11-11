#!/usr/bin/env python3
"""Temporarily record renewal response shape and timing, never credentials or response bodies."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

INCLUDE = "require __DIR__.'/renewal-response-probe.php'; // temporary renewal diagnostics\n"
ANCHOR = "ini_set('display_errors', '0');\n"


def probe(expires):
    return r'''<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) { http_response_code(404);exit; }
if (time()>EXPIRES) return;
$probeInput=json_decode(file_get_contents('php://input'),true);
$probeAction=is_array($probeInput)?($probeInput['action'] ?? null):null;
unset($probeInput);
if (!in_array($probeAction,['quote','orders','checkout','order'],true)) return;
$probeStart=microtime(true);
ob_start(static function(string $output,int $phase) use ($probeAction,$probeStart): string {
    if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) return $output;
    try {
        $json=json_decode($output,true);
        $report=['utc'=>gmdate('c'),'action'=>$probeAction,'http'=>http_response_code(),
            'seconds'=>round(microtime(true)-$probeStart,3),'bytes'=>strlen($output),
            'valid_json'=>json_last_error()===JSON_ERROR_NONE,'ok'=>is_array($json)?(($json['ok'] ?? false)===true):false];
        if ($probeAction==='quote' && is_array($json['quote'] ?? null)) {
            $q=$json['quote'];$report['quote_types']=[];
            foreach (['quote_id','package_name','amount_minor','currency','mode','gateway','duration','duration_period','max_connections','promo_title'] as $field)
                $report['quote_types'][$field]=array_key_exists($field,$q)?get_debug_type($q[$field]):'missing';
            $report['quote_id_valid']=is_string($q['quote_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$q['quote_id'])===1;
            $report['amount_valid']=is_int($q['amount_minor'] ?? null) && $q['amount_minor']>=0 && $q['amount_minor']<=99999999;
            $report['currency_valid']=in_array($q['currency'] ?? null,['CAD','USD'],true);
            $report['mode_valid']=in_array($q['mode'] ?? null,['live','sandbox'],true);
            $report['square_gateway']=!isset($q['gateway']) || $q['gateway']==='square';
        }
        $config=require __DIR__.'/settings-config.php';
        $file=dirname($config['database']).'/renewal-response-diagnostic.jsonl';
        $old=umask(0077);
        try { file_put_contents($file,json_encode($report,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX); }
        finally { umask($old); }
    } catch (Throwable $ignored) {}
    return $output;
});
'''.replace('EXPIRES', str(expires))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['install', 'show', 'remove'])
    parser.add_argument('--site', type=Path, default=Path('/www/wwwroot/fecxbrfzku.net/am-app'))
    parser.add_argument('--php', default='/www/server/php/84/bin/php')
    args = parser.parse_args()
    database = Path(json.loads(subprocess.check_output([
        args.php, '-n', '-r', '$c=require $argv[1]; echo json_encode($c["database"],JSON_THROW_ON_ERROR);',
        str(args.site / 'settings-config.php')], text=True)))
    state = database.resolve().parent
    if state == args.site.parent.resolve() or args.site.parent.resolve() in state.parents:
        raise RuntimeError('Diagnostic storage must be outside the website root.')
    log = state / 'renewal-response-diagnostic.jsonl'
    if args.action == 'show':
        if not log.exists():
            print('No diagnostic recorded. Tap Review renewal, wait until it stops, then run show again.')
        else:
            for line in log.read_text().splitlines()[-12:]:
                print(json.dumps(json.loads(line), indent=2))
        return
    api = args.site / 'customer-api.php'
    helper = args.site / 'renewal-response-probe.php'
    original = api.read_text()
    if args.action == 'remove':
        updated = original.replace(INCLUDE, '')
    else:
        if INCLUDE not in original and original.count(ANCHOR) != 1:
            raise RuntimeError('API layout differs; no changes made.')
        updated = original if INCLUDE in original else original.replace(ANCHOR, ANCHOR + INCLUDE, 1)
    with tempfile.TemporaryDirectory(prefix='renewal-probe-', dir=state) as tmp:
        staged = Path(tmp) / 'customer-api.php'
        staged.write_text(updated)
        subprocess.run([args.php, '-n', '-l', str(staged)], check=True)
        if args.action == 'install':
            php = Path(tmp) / helper.name
            php.write_text(probe(int(time.time()) + 1800))
            subprocess.run([args.php, '-n', '-l', str(php)], check=True)
        if api.read_text() != original:
            raise RuntimeError('API changed during preparation; stopped.')
        backup = state / ('customer-api-before-probe-' + str(time.time_ns()) + '.php')
        shutil.copy2(api, backup)
        backup.chmod(0o600)
        targets = {api: updated}
        if args.action == 'install':
            targets = {helper: php.read_text(), **targets}
        owner = api.stat()
        for target, content in targets.items():
            fd, name = tempfile.mkstemp(prefix='.renewal-probe-', dir=args.site)
            try:
                with os.fdopen(fd, 'w') as out:
                    out.write(content)
                os.chmod(name, owner.st_mode & 0o777)
                os.chown(name, owner.st_uid, owner.st_gid)
                os.replace(name, target)
            finally:
                Path(name).unlink(missing_ok=True)
        if args.action == 'remove':
            # Keep the inert helper for any request that already read the old controller.
            print('Diagnostic hook removed. Existing private report retained.')
        else:
            print('Diagnostic enabled for 30 minutes. No payment or renewal was initiated.')
            print('Tap Review renewal in My TV, wait for it to finish, then run this script with show.')


if __name__ == '__main__':
    main()
