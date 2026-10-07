#!/usr/bin/env python3
"""
Drive the installer's real web UI the way the client will: a browser, a key,
a form, a button. The CLI path is already covered by e2e.php; this proves the
page the client actually sees works, and leaves screenshots behind as evidence.

Usage: DOCROOT=... BASEURL=... KEY=... python3 tests/ui.py
"""
import os
import pathlib
import sys

from playwright.sync_api import sync_playwright

DOCROOT = pathlib.Path(os.environ["DOCROOT"])
BASE = os.environ["BASEURL"].rstrip("/")
KEY = os.environ["KEY"]
SHOTS = pathlib.Path(os.environ.get("SHOTS", "/tmp/shots"))
SHOTS.mkdir(parents=True, exist_ok=True)

DB = {
    "dbhost": os.environ.get("DBHOST", "127.0.0.1:33306"),
    "dbname": os.environ.get("DBNAME", "gnametest_db"),
    "dbuser": os.environ.get("DBUSER", "gnametest_u"),
    "dbpass": os.environ.get("DBPASS", "Str0ng#Pass!"),
}

passed = 0
failed = []


def t(label, ok, detail=""):
    global passed
    if ok:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed.append(f"{label}" + (f" -- {detail}" if detail else ""))
        print(f"  FAIL {label}" + (f" -- {detail}" if detail else ""))


with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    # Hard cap: the API rejects images over 2000px, so never resize past this
    # and never screenshot full_page.
    page.set_viewport_size({"width": 1280, "height": 900})
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))

    print("\n-- the key gate --")
    page.goto(f"{BASE}/install.php", wait_until="domcontentloaded")
    t("the installer loads", "Site installer" in page.inner_text("h1"))
    t("it asks for a key before anything else", page.locator("#k").count() == 1)
    t("no database form is reachable yet", page.locator("#dbname").count() == 0)
    page.screenshot(path=str(SHOTS / "01-key-gate.png"))

    page.fill("#k", "wrong-key-entirely")
    page.click("button[type=submit]")
    page.wait_for_load_state("domcontentloaded")
    body = page.inner_text("body")
    t("a wrong key is refused", "did not match" in body)
    t("a wrong key still shows no database form", page.locator("#dbname").count() == 0)

    print("\n-- preflight --")
    page.fill("#k", KEY)
    page.click("button[type=submit]")
    page.wait_for_load_state("domcontentloaded")
    body = page.inner_text("body")
    t("the right key gets in", page.locator("#dbname").count() == 1, body[:200])
    t("the site archive was detected", "mysite.zip" in body)
    t("the dump was detected", "mysite_dev.sql" in body)
    t("it reads mysite.zip as the site archive", "site archive" in body)
    t("it reads the .sql as the database dump", "database dump" in body)
    page.screenshot(path=str(SHOTS / "02-preflight.png"))

    print("\n-- install --")
    for field, value in DB.items():
        page.fill(f"#{field}", value)
    page.screenshot(path=str(SHOTS / "03-filled.png"))
    page.click("button[type=submit]")
    page.wait_for_load_state("domcontentloaded")
    # The banner is the summary line; wait for the real DOM, not the markup.
    page.wait_for_selector(".banner", timeout=60_000)
    body = page.inner_text("body")

    banner = page.inner_text(".banner")
    t("the run reports success", "Everything passed" in banner, banner[:200])
    t("no check row failed", page.locator("span.tag.fail").count() == 0,
      f"{page.locator('span.tag.fail').count()} fail tags")
    t("at least ten checks ran", page.locator("span.tag").count() >= 10)
    t("the wrapper folder was reported as stripped", "stripped that folder" in body)
    t("the config rewrite is shown", "includes/config.php" in body)
    t("the dev password is never printed back", "devpass123" not in body)
    t("the new password is masked", DB["dbpass"] not in body, "password leaked into the page")
    # The card heading is uppercased by CSS, so inner_text returns "LOGINS
    # THAT NEED CHANGING". Compare case-insensitively, and assert on the row
    # contents too, since those carry no text-transform.
    t("the weak dev logins card is shown", "logins that need changing" in body.lower())
    t("  it names the admin account", "admin_users" in body and "editor" in body)
    t("  it says why", 'password hash matches "admin"' in body)
    t("the hardcoded Windows path is listed", "xampp" in body.lower())
    t("the table row counts are shown", "articles" in body)
    t("no JS error on the page", not errors, "; ".join(errors))
    page.screenshot(path=str(SHOTS / "04-report-top.png"))
    page.evaluate("window.scrollTo(0, document.body.scrollHeight / 2)")
    page.screenshot(path=str(SHOTS / "05-report-mid.png"))
    page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
    page.screenshot(path=str(SHOTS / "06-report-end.png"))

    print("\n-- the installed site --")
    site = browser.new_page()
    site.set_viewport_size({"width": 1280, "height": 900})
    site.goto(f"{BASE}/", wait_until="domcontentloaded")
    txt = site.inner_text("body")
    t("the public site renders the site name", "Harbour & Co" in txt)
    t("the public site renders imported articles", "Welcome to Harbour" in txt)
    t("the draft stays unpublished", "Still a draft" not in txt)
    site.screenshot(path=str(SHOTS / "07-public-site.png"))

    site.goto(f"{BASE}/admin/", wait_until="domcontentloaded")
    t("the dashboard shows its login", site.locator("#username").count() == 1)
    site.screenshot(path=str(SHOTS / "08-dashboard-login.png"))
    site.fill("#username", "admin")
    site.fill("#password", "admin")
    site.click("#login")
    site.wait_for_load_state("domcontentloaded")
    t("the dashboard logs in", site.locator("#welcome").count() == 1,
      site.inner_text("body")[:200])
    t("the dashboard lists articles from the database",
      "Welcome to Harbour" in site.inner_text("#articles"))
    site.screenshot(path=str(SHOTS / "09-dashboard-signed-in.png"))

    site.fill("#title", "Added through the browser")
    site.fill("#body", "Proving the dashboard writes to the database.")
    site.click("#save")
    site.wait_for_load_state("domcontentloaded")
    t("the dashboard writes a new article",
      "Added through the browser" in site.inner_text("#articles"))
    site.screenshot(path=str(SHOTS / "10-dashboard-after-write.png"))

    print("\n-- cleanup --")
    page.bring_to_front()
    page.once("dialog", lambda d: d.accept())
    page.click("text=Delete the installer and the uploaded files")
    page.wait_for_load_state("domcontentloaded")
    body = page.inner_text("body")
    t("cleanup reports success", "Cleaned up" in body, body[:300])
    t("install.php is gone from disk", not (DOCROOT / "install.php").exists())
    t("the zip is gone from disk", not (DOCROOT / "mysite.zip").exists())
    t("the dump is gone from disk", not (DOCROOT / "mysite_dev.sql").exists())
    t("no .pre-install.bak files are left",
      not list(DOCROOT.rglob("*.pre-install.bak")),
      str([str(x) for x in DOCROOT.rglob("*.pre-install.bak")]))
    page.screenshot(path=str(SHOTS / "11-cleanup.png"))

    gone = browser.new_page()
    r = gone.goto(f"{BASE}/install.php")
    t("the installer URL no longer serves anything", r.status in (403, 404),
      f"status={r.status}")

    print("\n-- the site still works after cleanup --")
    site.goto(f"{BASE}/", wait_until="domcontentloaded")
    txt = site.inner_text("body")
    t("the public site still renders", "Welcome to Harbour" in txt)
    t("the browser-added article is live", "Added through the browser" in txt)
    t("no PHP error on the page", "Fatal error" not in txt and "Warning:" not in txt)
    site.screenshot(path=str(SHOTS / "12-site-after-cleanup.png"))

    browser.close()

print()
if failed:
    print("FAILURES:")
    for f in failed:
        print("  -", f)
print(f"{'FAIL' if failed else 'PASS'}: {passed} passed, {len(failed)} failed")
print(f"screenshots in {SHOTS}")
sys.exit(1 if failed else 0)
