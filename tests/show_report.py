#!/usr/bin/env python3
"""Pretty print the installer's JSON report so a run is readable at a glance."""
import json
import sys

r = json.load(open(sys.argv[1]))


def section(title, items, fmt):
    if not items:
        return
    print(title)
    for x in items:
        print("   ", fmt(x))


section("NOTES", r.get("notes", []), lambda x: "+ " + x)
section("WARNINGS", r.get("warnings", []), lambda x: "! " + x)
section("ERRORS", r.get("errors", []), lambda x: "X " + x)

if r.get("statements") is not None:
    print(
        f"\nSQL  {r.get('statements')} statements: ran={r.get('sql_ran')} "
        f"skipped={r.get('sql_skipped')} repaired={len(r.get('sql_repaired', []))} "
        f"host-blocked={len(r.get('sql_host_blocked', []))} "
        f"failed={len(r.get('sql_failed', []))}"
    )
    for x in r.get("sql_repaired", []):
        print(f"  ~ {x['why']}\n      {x['stmt'][:120]}")
    for x in r.get("sql_host_blocked", []):
        print(f"  H {x['note']}\n      {x['stmt'][:120]}")
    for x in r.get("sql_failed", []):
        print(f"  X {x['error']}\n      {x['stmt'][:120]}")

if r.get("table_counts"):
    print("\nTABLES")
    for t, c in r["table_counts"].items():
        print(f"   {t:24} {c} rows")

print(f"\nCONFIG  {r.get('config_scanned')} php files scanned")
for f in r.get("config_changed", []):
    print("   " + f["file"])
    for c in f["changes"]:
        print("       - " + c)

if r.get("leftovers"):
    print("\nDEV LEFTOVERS")
    for l in r["leftovers"]:
        print(f"   {l['file']}:{l['line']} [{l['kind']}] {l['text'][:70]}")

if r.get("weak_logins"):
    print("\nWEAK LOGINS")
    for w in r["weak_logins"]:
        print(f"   {w['table']}.{w['user']}: {w['why']}")

print("\nADMIN PATH:", r.get("admin_path"))

print("\nCHECKS")
tally = {"pass": 0, "warn": 0, "fail": 0}
for c in r.get("checks", []):
    tally[c[2]] = tally.get(c[2], 0) + 1
    print(f"   [{c[2].upper():4}] {c[0]}: {c[1][:160]}")
print(f"\n   {tally['pass']} pass, {tally['warn']} warn, {tally['fail']} fail")
