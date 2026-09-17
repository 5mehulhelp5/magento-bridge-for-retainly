#!/usr/bin/env python3
"""Checks that only CI can make.

CI cannot install Magento (magento/framework lives on repo.magento.com behind
auth keys), so nothing here exercises the module the way a store would. These
asserts cover the ways the module's *identity* silently breaks — a rename, a
moved file, a vendor typo — which would otherwise first surface as a fatal on
a merchant's `setup:di:compile`.

Run locally the same way CI does:  python3 .github/scripts/check_module.py
"""
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE = ROOT / "src/app/code/Retnly/MagentoBridge"

failures = []


def check(ok, msg):
    if not ok:
        failures.append(msg)


root_pkg = json.loads((ROOT / "composer.json").read_text())
mod_pkg = json.loads((MODULE / "composer.json").read_text())

# 1. Every autoload path the published package promises must actually exist.
#    composer validate does NOT check this; a moved file ships a broken package.
for f in root_pkg.get("autoload", {}).get("files", []):
    check((ROOT / f).is_file(), f"composer.json autoload.files missing: {f}")
for ns, path in root_pkg.get("autoload", {}).get("psr-4", {}).items():
    check((ROOT / path).is_dir(), f"composer.json psr-4 {ns} -> missing dir: {path}")

# 2. Package name must be the same in both composer.json files, or `composer
#    require` installs one thing and Magento registers another.
check(
    root_pkg["name"] == mod_pkg["name"],
    f"package name mismatch: root={root_pkg['name']} module={mod_pkg['name']}",
)

# 3. registration.php, module.xml and the directory path must agree on the
#    module name. Magento derives nothing — all three are written by hand.
path_name = f"{MODULE.parent.name}_{MODULE.name}"
reg = (MODULE / "registration.php").read_text()
reg_match = re.search(r"ComponentRegistrar::MODULE,\s*'([^']+)'", reg)
check(reg_match is not None, "registration.php: could not find the registered module name")

xml_match = re.search(r'<module\s+name="([^"]+)"', (MODULE / "etc/module.xml").read_text())
check(xml_match is not None, "etc/module.xml: could not find <module name=...>")

if reg_match and xml_match:
    names = {path_name, reg_match.group(1), xml_match.group(1)}
    check(
        len(names) == 1,
        f"module name disagrees across path/registration.php/module.xml: {sorted(names)}",
    )

for f in failures:
    print(f"FAIL: {f}", file=sys.stderr)
print("ok" if not failures else f"{len(failures)} failure(s)")
sys.exit(1 if failures else 0)
