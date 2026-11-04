"""Isolated transactional tests. No production config imports or network calls."""
import ast
import calendar
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
import datetime
from decimal import Decimal
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import threading
import time
from types import SimpleNamespace
import unittest

from fastapi import FastAPI
from fastapi.testclient import TestClient
from am_account_worker import Service, Login, Customer, Owner, Quote, Renew, Result, ContractError, register
import install


class Cursor:
    def __init__(self, db): self.cursor = db.cursor()
    def execute(self, sql, args=()):
        sql = sql.replace('%s', '?').replace(' FOR UPDATE', '').replace('NOW()', 'CURRENT_TIMESTAMP')
        self.cursor.execute(sql, args)
    def fetchone(self):
        row = self.cursor.fetchone()
        return dict(row) if row else None
    def fetchall(self): return [dict(row) for row in self.cursor.fetchall()]


class Connection:
    def __init__(self, db, fixture): self.db, self.fixture = db, fixture
    def commit(self):
        if self.fixture.failure == 'before_commit':
            self.fixture.failure = None
            raise RuntimeError('Injected failure before commit')
        self.db.commit()
        if self.fixture.failure == 'after_commit':
            self.fixture.failure = None
            raise RuntimeError('Injected lost commit acknowledgement')
    def rollback(self): self.db.rollback()


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir='/tmp/opencode' if Path('/tmp/opencode').is_dir() else None)
        self.path = str(Path(self.temp.name) / 'worker.sqlite')
        self.lock = threading.RLock()
        self.failure = None
        self.refresh_ok = True
        self.refresh_calls = 0
        self.base_expiry = int(time.time()) + 10 * 86400
        with sqlite3.connect(self.path) as db:
            db.executescript('''
                CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,credits TEXT,status INTEGER,member_group_id INTEGER,override_packages TEXT);
                CREATE TABLE users_packages (id INTEGER PRIMARY KEY,package_name TEXT,credits TEXT,duration INTEGER,duration_period TEXT,duration_value INTEGER,max_connections INTEGER,bouquets TEXT,groups TEXT,type INTEGER,enabled INTEGER,activated INTEGER);
                CREATE TABLE customers (id INTEGER PRIMARY KEY,member_id INTEGER,username TEXT,password TEXT,activation_code TEXT,package_id INTEGER,max_connections INTEGER,exp_date INTEGER,activated INTEGER,is_trial INTEGER,enabled INTEGER,bouquet TEXT,duration INTEGER,duration_period TEXT,duration_value INTEGER,updated TEXT);
                CREATE TABLE bouquets (id INTEGER PRIMARY KEY,bouquet_name TEXT);
                CREATE TABLE users_credits_log (user_id TEXT,amount TEXT,balance_before TEXT,balance_after TEXT,reason TEXT,actor_id TEXT,ref_type TEXT,ref_id TEXT,note TEXT,created_at INTEGER);
                CREATE TABLE portal_trial_renewals (customer_id INTEGER PRIMARY KEY,renewals_used INTEGER,converted_to_paid INTEGER);
                CREATE TABLE am_renewal_results (order_id TEXT PRIMARY KEY,reseller_id INTEGER,line_id INTEGER,request_hash TEXT,result_json TEXT,refreshed INTEGER,created_at INTEGER);
                INSERT INTO users VALUES (1,'seller1','20',1,1,'[]'),(2,'seller2','20',1,2,'[]');
                INSERT INTO users_packages VALUES (1,'Thirty days','3.5',30,'days',30,2,'[1,2]','[1]',0,1,1),(2,'Other seller plan','6',30,'days',30,4,'[2]','[2]',0,1,1),(3,'Trial','0',1,'days',1,1,'[1]','[1]',1,1,1);
                INSERT INTO bouquets VALUES (1,'News'),(2,'Sports');
            ''')
            db.execute("INSERT INTO customers VALUES (10,1,'customer','secret-password','CODE-ONE',1,2,?,1,0,1,'[\"1\"]',30,'days',30,NULL)", (self.base_expiry,))
        self.w = {'panel_db_cursor': self.cursor, '_reseller_package_scope': lambda cur, name: (1 if name == 'seller1' else 2, None),
                  '_duration_to_days': lambda duration, period: duration,
                  '_package_allowed': lambda pkg, group, overrides: group in json.loads(pkg['groups']),
                  '_renewal_window': self.window, '_set_credits': self.credits, 'enforce_trial_policy': self.policy,
                  'TrialPolicyError': ContractError, '_overcast_refresh_customer': self.refresh,
                  'settings': SimpleNamespace(shared_secret='FAKE_TEST_SECRET'), 'verify_signature': self.signature}
        # Optional exact deployed-helper tests, without importing the worker/config.
        reference = os.environ.get('AM_WORKER_REFERENCE')
        if reference:
            names = {'_utcnow','_from_ts','_add_months','_add_duration','_duration_to_days','_renewal_window','_parse_id_list','_reseller_package_scope','_package_allowed','_set_credits','_log_credit_change'}
            nodes = [node for node in ast.parse(Path(reference).read_text()).body if isinstance(node, ast.FunctionDef) and node.name in names]
            namespace = {'datetime': datetime, 'calendar': calendar, 'Decimal': Decimal, 'time': time, 'json': json, '_credit_trigger_active': lambda cur: False}
            exec(compile(ast.Module(body=nodes, type_ignores=[]), '<reference-helpers>', 'exec'), namespace)
            self.w.update({name: namespace[name] for name in names})
        policy = os.environ.get('AM_TRIAL_POLICY')
        if policy:
            spec = importlib.util.spec_from_file_location('fixture_trial_policy', policy)
            module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
            self.w.update(enforce_trial_policy=module.enforce, TrialPolicyError=module.TrialPolicyError)
        self.service = Service(self.w)

    def tearDown(self): self.temp.cleanup()

    @contextmanager
    def cursor(self, autocommit=True):
        # Serializes transactions like the worker's reseller row lock. Tests use
        # real commit/rollback persistence; staging must also verify InnoDB locks.
        with self.lock:
            db = sqlite3.connect(self.path, isolation_level=None if autocommit else 'DEFERRED')
            db.row_factory = sqlite3.Row
            if not autocommit: db.execute('BEGIN')
            try: yield Cursor(db), Connection(db, self)
            finally: db.close()

    def change(self, sql, args=()):
        with sqlite3.connect(self.path) as db: db.execute(sql, args)

    def row(self, sql):
        with sqlite3.connect(self.path) as db: return db.execute(sql).fetchone()

    def window(self, line, package, old):
        now = int(time.time())
        base = max(int(line.get('exp_date') or 0), now)
        if not line.get('activated'): base = now + int(old['duration']) * 86400
        return None, datetime.datetime.fromtimestamp(base + int(package['duration']) * 86400, datetime.timezone.utc)

    def credits(self, cur, uid, balance, amount, before, reason, actor, ref_type, ref):
        cur.execute('UPDATE users SET credits=%s WHERE id=%s', (str(balance),uid))
        cur.execute('INSERT INTO users_credits_log VALUES (%s,%s,%s,%s,%s,%s,%s,%s,NULL,%s)', (str(uid),str(amount),str(before),str(balance),reason,str(actor),ref_type,str(ref),int(time.time())))

    def policy(self, cur, line, target, operation):
        if line['is_trial']:
            cur.execute('INSERT INTO portal_trial_renewals VALUES (%s,0,1)', (line['id'],))
        return False

    def refresh(self, line_id):
        self.refresh_calls += 1
        return self.refresh_ok

    def signature(self, raw, signature, timestamp):
        if not signature or not timestamp: return False
        return hmac.compare_digest(signature, hmac.new(b'FAKE_TEST_SECRET', timestamp.encode() + b'.' + raw, hashlib.sha256).hexdigest())

    def renewal(self):
        quote = self.service.quote(Quote(reseller_id='1', line_id='10', package_id=1))
        return Renew(reseller_id='1', line_id='10', package_id=1, order_id='a'*32,
                     package_fingerprint=quote['package']['worker_fingerprint'], line_fingerprint=quote['line_fingerprint'])

    def test_login_scoped_and_no_secrets(self):
        reply = self.service.login(Login(username='customer', password='secret-password'))
        self.assertEqual(reply['reseller_id'], '1')
        self.assertNotIn('secret-password', json.dumps(reply))
        self.assertNotIn('CODE-ONE', json.dumps(reply))
        self.assertEqual(self.service.login(Login(activation_code='CODE-ONE'))['line']['line_id'], '10')
        with self.assertRaises(ContractError): self.service.customer(Customer(reseller_id='2', line_id='10'))
        with self.assertRaises(ContractError): self.service.login(Login(username='customer', password='wrong'))
        self.change("UPDATE customers SET password='rotated'")
        newer = self.service.customer(Customer(reseller_id='1',line_id='10'))
        self.assertNotEqual(reply['auth_revision'], newer['auth_revision'])
        self.change('UPDATE customers SET member_id=2')
        with self.assertRaises(ContractError): self.service.customer(Customer(reseller_id='1',line_id='10'))

    def test_disabled_and_catalogue(self):
        self.assertEqual([p['id'] for p in self.service.catalogue(Owner(reseller_id='1'))['packages']], [1,3])
        with self.assertRaises(ContractError): self.service.quote(Quote(reseller_id='1',line_id='10',package_id=2))
        with self.assertRaises(ContractError): self.service.quote(Quote(reseller_id='1',line_id='10',package_id=3))
        self.change('UPDATE users SET status=0 WHERE id=1')
        with self.assertRaises(ContractError): self.service.login(Login(activation_code='CODE-ONE'))

    def test_atomic_replay_concurrency_and_remaining_time(self):
        request = self.renewal()
        with ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(lambda _: self.service.renew(request), range(4)))
        self.assertTrue(all(result['state'] == 'applied' for result in results))
        self.assertEqual(self.row('SELECT credits FROM users WHERE id=1')[0], '16.5')
        self.assertEqual(self.row('SELECT COUNT(*) FROM users_credits_log')[0], 1)
        self.assertEqual(self.row('SELECT exp_date FROM customers WHERE id=10')[0], self.base_expiry + 30*86400)
        self.assertEqual(json.loads(self.row('SELECT bouquet FROM customers')[0]), [1])
        with self.assertRaises(ContractError): self.service.renew(request.model_copy(update={'package_id': 2}))
        self.assertEqual(self.service.result(Result(reseller_id='2',order_id='a'*32))['state'], 'not_applied')

    def test_failures_and_native_repair(self):
        request = self.renewal()
        self.failure = 'before_commit'
        with self.assertRaises(RuntimeError): self.service.renew(request)
        self.assertEqual(self.row('SELECT credits FROM users WHERE id=1')[0], '20')
        self.assertEqual(self.row('SELECT exp_date FROM customers')[0], self.base_expiry)
        self.assertEqual(self.row('SELECT COUNT(*) FROM users_credits_log')[0], 0)
        self.failure = 'after_commit'
        with self.assertRaises(RuntimeError): self.service.renew(request)
        self.refresh_ok = False
        self.assertEqual(self.service.renew(request)['state'], 'applied_refresh_pending')
        self.refresh_ok = True
        self.assertEqual(self.service.result(Result(reseller_id='1',order_id='a'*32))['state'], 'applied')
        self.assertEqual(self.row('SELECT COUNT(*) FROM users_credits_log')[0], 1)

    def test_stale_quotes_balance_and_free_bouquets(self):
        request = self.renewal()
        self.change("UPDATE users_packages SET credits='5' WHERE id=1")
        with self.assertRaises(ContractError): self.service.renew(request)
        self.change("UPDATE users_packages SET credits='3.5' WHERE id=1")
        self.change("UPDATE users SET credits='1' WHERE id=1")
        with self.assertRaises(ContractError): self.service.renew(request)
        self.change("UPDATE users SET credits='20' WHERE id=1")
        self.change("UPDATE customers SET bouquet='[1,2]'")
        self.assertEqual(self.service.renew(request)['credits_charged'], '3.5')
        self.assertEqual(json.loads(self.row('SELECT bouquet FROM customers')[0]), [1,2])

    def test_trial_conversion_policy_commits_with_debit(self):
        self.change('UPDATE customers SET package_id=3,is_trial=1')
        request = self.renewal()
        self.failure = 'before_commit'
        with self.assertRaises(RuntimeError): self.service.renew(request)
        self.assertEqual(self.row('SELECT COUNT(*) FROM portal_trial_renewals')[0], 0)
        self.service.renew(request)
        self.assertEqual(self.row('SELECT converted_to_paid FROM portal_trial_renewals')[0], 1)
        self.assertEqual(self.row('SELECT is_trial FROM customers')[0], 0)

    def test_route_signature_and_validation_do_not_echo_secrets(self):
        app = FastAPI(); register(app, self.w)
        with TestClient(app) as client:
            raw = b'{"password":"PRIVATE_SENTINEL","unknown":"PRIVATE_SENTINEL"}'
            self.assertEqual(client.post('/am/customer-auth', content=raw).status_code,401)
            ts = str(int(time.time())); signature = hmac.new(b'FAKE_TEST_SECRET',ts.encode()+b'.'+raw,hashlib.sha256).hexdigest()
            response = client.post('/am/customer-auth',content=raw,headers={'X-Portal-Timestamp':ts,'X-Portal-Signature':signature})
            self.assertEqual(response.status_code,422)
            self.assertNotIn('PRIVATE_SENTINEL', response.text)
            self.assertEqual(client.post('/am/customer-auth',content=b'x'*16385).status_code,413)
            raw = b'[]'
            signature = hmac.new(b'FAKE_TEST_SECRET',ts.encode()+b'.'+raw,hashlib.sha256).hexdigest()
            response = client.post('/am/capabilities',content=raw,headers={'X-Portal-Timestamp':ts,'X-Portal-Signature':signature})
            self.assertTrue(response.json()['idempotent_renewal'])

    def test_installer_refuses_drift_preserves_handlers(self):
        source = 'def existing():\n    return 1\n'
        old = install.EXPECTED
        install.EXPECTED = {'existing': hashlib.sha256(install.definitions(source)['existing'].encode()).hexdigest()}
        try:
            patched = install.validate(source)
            self.assertEqual(install.definitions(source),install.definitions(patched))
            self.assertEqual(install.validate(patched),patched)
            with self.assertRaises(ValueError): install.validate(source.replace('return 1','return 2'))
        finally: install.EXPECTED = old


if __name__ == '__main__': unittest.main()
