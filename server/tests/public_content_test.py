"""Keep globally routable IP addresses out of tracked public text, without storing private addresses."""
import ipaddress
from pathlib import Path
import re
import subprocess

root = Path(__file__).resolve().parents[2]
files = subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().split('\0')
violations = []
for filename in filter(None,files):
    path = root / filename
    if not path.is_file():
        continue
    raw = path.read_bytes()
    if b'\0' in raw:
        continue
    for match in re.finditer(rb'(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])',raw):
        try:
            address = ipaddress.ip_address(match.group().decode())
        except ValueError:
            continue
        if address.is_global:
            violations.append(filename)
assert not violations, 'Public routable IP address in: '+', '.join(sorted(set(violations)))
print('PASS: no globally routable IP addresses in tracked public text.')
