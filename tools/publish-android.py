#!/usr/bin/env python3
"""Prepare a verified My TV APK/update feed; optionally publish a complete GitHub release."""
import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import urllib.error
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
REPO = 'derangedchief-crypto/AM-App'
PACKAGE = 'com.extreme.mytv'
SIGNER = 'f3a34034e02836e3d92ba2440e11ea3f2d0580de51c91f034f192a7b40a52c3f'
FEED = f'https://github.com/{REPO}/releases/latest/download/my-tv-update.json'


def command(*args):
    return subprocess.check_output(args, cwd=ROOT, text=True).strip()


def android_tool(name):
    sdk = Path(os.environ.get('ANDROID_HOME') or os.environ.get('ANDROID_SDK_ROOT') or Path.home() / '.local/android-sdk')
    candidates = sorted((sdk / 'build-tools').glob('*/' + name), reverse=True)
    found = str(candidates[0]) if candidates else shutil.which(name)
    if not found:
        raise RuntimeError('Android build tool missing: ' + name)
    return found


def privacy(name, data):
    for match in re.finditer(rb'(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])', data):
        try:
            address = ipaddress.ip_address(match.group().decode())
        except ValueError:
            continue
        if address.is_global:
            raise RuntimeError('Public IP address found in ' + name)


def prepare(apk, output):
    badging = command(android_tool('aapt'), 'dump', 'badging', str(apk))
    package = re.search(r"package: name='([^']+)' versionCode='(\d+)' versionName='([^']+)'", badging)
    sdk = re.search(r"sdkVersion:'(\d+)'", badging)
    if not package or package[1] != PACKAGE or not sdk or not re.fullmatch(r'[A-Za-z0-9._-]{1,80}', package[3]):
        raise RuntimeError('Not a supported My TV APK.')
    certificates = command(android_tool('apksigner'), 'verify', '--print-certs', str(apk))
    if re.findall(r'certificate SHA-256 digest: ([a-f0-9]+)', certificates) != [SIGNER]:
        raise RuntimeError('APK signing certificate does not match the My TV update identity.')
    with zipfile.ZipFile(apk) as archive:
        for member in archive.namelist():
            privacy(member, archive.read(member))
    data = apk.read_bytes()
    if not 0 < len(data) <= 100 * 1024 * 1024:
        raise RuntimeError('APK size is outside the supported update limit.')
    version, code, min_sdk = package[3], int(package[2]), int(sdk[1])
    if code <= 0 or not 26 <= min_sdk <= 100:
        raise RuntimeError('Unsupported version or Android requirement.')
    name, tag = f'My-TV-{version}.apk', f'v{version}'
    manifest = dict(schema=1, packageName=PACKAGE, versionCode=code, versionName=version,
                    minSdk=min_sdk, apkUrl=f'https://github.com/{REPO}/releases/download/{tag}/{name}',
                    sha256=hashlib.sha256(data).hexdigest(), size=len(data))
    output.mkdir(parents=True, exist_ok=True)
    target = output / name
    if apk.resolve() != target.resolve():
        shutil.copyfile(apk, target)
    (output / 'my-tv-update.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (output / (name + '.sha256')).write_text(manifest['sha256'] + '  ' + name + '\n')
    return manifest, tag, target


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apk', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--notes', type=Path)
    parser.add_argument('--publish', action='store_true')
    args = parser.parse_args()
    manifest, tag, target = prepare(args.apk.resolve(), args.output.resolve())
    print('Prepared verified APK and update metadata:', target)
    if not args.publish:
        return
    if not args.notes or not args.notes.is_file():
        raise RuntimeError('--notes is required to publish.')
    privacy('release notes', args.notes.read_bytes())
    subprocess.run(['python3', 'server/tests/public_content_test.py'], cwd=ROOT, check=True)
    if command('git', 'status', '--porcelain'):
        raise RuntimeError('Commit changes before publishing.')
    revision = command('git', 'rev-parse', 'HEAD')
    if command('git', 'ls-remote', 'origin', 'refs/heads/main').split()[0] != revision:
        raise RuntimeError('Push this commit to main before publishing.')
    try:
        with urllib.request.urlopen(urllib.request.Request(FEED, headers={'User-Agent': 'My-TV-Release'}), timeout=30) as response:
            previous = json.load(response)
        if previous.get('packageName') != PACKAGE or previous['versionCode'] >= manifest['versionCode']:
            raise RuntimeError('Publishing would replace the feed with a non-newer version.')
    except urllib.error.HTTPError as error:
        if error.code != 404:
            raise
    # Keep the release private until every asset is uploaded successfully.
    subprocess.run(['gh', 'release', 'create', tag, str(target), str(target) + '.sha256',
                    str(target.parent / 'my-tv-update.json'), '--repo', REPO, '--target', revision,
                    '--title', f'My TV {manifest["versionName"]}', '--notes-file', str(args.notes.resolve()), '--draft'], check=True)
    subprocess.run(['gh', 'release', 'edit', tag, '--repo', REPO, '--draft=false', '--latest'], check=True)
    print('Published:', f'https://github.com/{REPO}/releases/tag/{tag}')


if __name__ == '__main__':
    main()
