#!/usr/bin/env python3
"""Read-only AST inventory: no imports of worker/config and no secret values."""
import argparse
import ast
import hashlib
import json
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--worker', default='/opt/iptv-auth-worker/main.py')
args = parser.parse_args()
path = Path(args.worker)
if not path.is_file():
    raise SystemExit('Worker source not found. Run on the auth-worker host, or pass --worker /actual/path/main.py.')
source = path.read_text()
tree = ast.parse(source)
models = {}
model_refs = {}
for node in tree.body:
    if isinstance(node, ast.ClassDef):
        models[node.name] = [item.target.id for item in node.body if isinstance(item, ast.AnnAssign) and isinstance(item.target, ast.Name)]
        model_refs[node.name] = {item.id for item in ast.walk(node) if isinstance(item, ast.Name)}
imports = []
for node in tree.body:
    if isinstance(node, ast.Import):
        imports.extend(item.name for item in node.names)
    elif isinstance(node, ast.ImportFrom):
        imports.append(node.module or '')
report = {'file_sha256': hashlib.sha256(source.encode()).hexdigest(), 'imports': sorted(set(imports)), 'routes': [], 'helpers': {}}
helpers = {'renew_line', '_renewal_window', '_lock_reseller_row', '_lock_line_row', '_set_credits', '_reseller_package_scope', '_package_allowed', '_overcast_refresh_customer'}
for node in tree.body:
    if not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
        continue
    digest = hashlib.sha256(ast.dump(node, include_attributes=False).encode()).hexdigest()
    if node.name in helpers:
        report['helpers'][node.name] = digest
    for decorator in node.decorator_list:
        if not isinstance(decorator, ast.Call) or not isinstance(decorator.func, ast.Attribute) or not decorator.args:
            continue
        endpoint = decorator.args[0]
        if not isinstance(endpoint, ast.Constant) or not isinstance(endpoint.value, str):
            continue
        route = endpoint.value
        if not any(word in route.lower() for word in ('renew', 'package', 'line', 'customer', 'balance', 'am-', 'am/', 'idempot', 'verify')):
            continue
        names = {item.id for item in ast.walk(node) if isinstance(item, ast.Name)}
        for kw in decorator.keywords:
            if isinstance(kw.value, ast.Name):
                names.add(kw.value.id)
        referenced = names & models.keys()
        while True:
            expanded = referenced | set().union(*(model_refs[name] & models.keys() for name in referenced))
            if expanded == referenced:
                break
            referenced = expanded
        report['routes'].append({'route': route, 'method': decorator.func.attr, 'handler': node.name,
                                 'sha256': digest, 'model_fields': {name: models[name] for name in sorted(referenced)}})
print(json.dumps(report, indent=2))
print('Read only. No worker/config imports, credentials, customer records, network calls or changes.')
print('Routes are from this file only; separately imported route modules require their own review.')
