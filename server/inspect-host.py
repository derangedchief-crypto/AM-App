#!/usr/bin/env python3
"""Read-only hosting inventory. Never executes site config, contacts a worker, or writes files."""
import argparse
import json
import pathlib
import re
import shutil
import subprocess


def read_text(path):
    try:
        return path.read_text(errors="replace")
    except OSError:
        return ""


def inspect(webroots, vhosts, php_directory):
    sites = []
    roots = set()
    if webroots.is_dir():
        roots.update(p for p in webroots.iterdir() if p.is_dir())
    vhost_rows = []
    if vhosts.is_dir():
        for path in sorted(vhosts.glob("*.conf")):
            source = read_text(path)
            root = re.search(r"(?m)^\s*root\s+([^;]+);", source)
            if not root:
                continue
            root_path = pathlib.Path(root.group(1).strip().strip("\"'"))
            names = re.findall(r"(?m)^\s*server_name\s+([^;]+);", source)
            php_versions = re.findall(r"enable-php-([0-9]+)\.conf", source)
            vhost_rows.append((root_path, names, php_versions))
            roots.add(root_path)
    for root in sorted(roots):
        if not (root / "manage-lines.php").is_file() or not (root / "includes" / "functions.php").is_file():
            continue
        config_path = root / "config.php"
        config = read_text(config_path)
        matched = [row for row in vhost_rows if row[0].resolve() == root.resolve()]
        sites.append({
            "document_root": str(root),
            "server_names": sorted(set(name for row in matched for name in row[1])),
            "nginx_php_versions": sorted(set(version for row in matched for version in row[2])),
            "config_file_exists": config_path.is_file(),
            "config_file_readable": bool(config),
            "auth_worker_section_present": bool(re.search(r"['\"]auth_worker['\"]\s*=>", config)),
            "customer_portal_section_present": bool(re.search(r"['\"]customer_portal['\"]\s*=>", config)),
            "am_api_directory_already_exists": (root / "am-api").exists(),
            "has_per_directory_php_settings": (root / ".user.ini").is_file(),
        })
    binaries = set()
    if php_directory.is_dir():
        binaries.update(php_directory.glob("*/bin/php"))
    system_php = shutil.which("php")
    if system_php:
        binaries.add(pathlib.Path(system_php))
    php = []
    probe = ('echo json_encode(array("version"=>PHP_VERSION,"sapi"=>PHP_SAPI,'
             '"curl"=>extension_loaded("curl"),"pdo_sqlite"=>extension_loaded("pdo_sqlite"),'
             '"openssl"=>extension_loaded("openssl")));')
    for binary in sorted(binaries):
        try:
            result = subprocess.run([str(binary), "-r", probe], stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL, universal_newlines=True, timeout=10)
            capabilities = json.loads(result.stdout)
            php.append(dict({"binary": str(binary)}, **capabilities))
        except (OSError, ValueError, subprocess.TimeoutExpired):
            php.append({"binary": str(binary), "probe": "unavailable"})
    return {"mode": "read_only", "reseller_sites": sites, "php_cli": php,
            "notes": ["No site config was executed; no secret values are included.",
                      "No site, worker, database, service, or web-server settings were changed.",
                      "CLI PHP extensions still need confirmation against the selected site's PHP runtime."]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--webroots", type=pathlib.Path, default=pathlib.Path("/www/wwwroot"))
    parser.add_argument("--vhosts", type=pathlib.Path, default=pathlib.Path("/www/server/panel/vhost/nginx"))
    parser.add_argument("--php-directory", type=pathlib.Path, default=pathlib.Path("/www/server/php"))
    args = parser.parse_args()
    print(json.dumps(inspect(args.webroots, args.vhosts, args.php_directory), indent=2))


if __name__ == "__main__":
    main()
