# One-file site installer

Installs a hand-written PHP + MySQL site onto shared hosting **without FTP,
SSH, or a control-panel login**. You upload two files with your host's file
manager, open one page in a browser, and it does the rest.

Built for a migration where the hosting is only reachable through a remote
desktop / drag-and-drop file manager, so every extra file is another chance
for something to go missing. It is therefore a single `install.php`.

---

## What it does

1. **Unpacks the site archive** into the web root, stripping the wrapper
   folder if the zip has one (otherwise the site lands at `/mysite/index.php`
   instead of `/index.php`).
2. **Imports the SQL dump**, repairing on the way in the things that break a
   dump taken on a dev machine:
   - MySQL 8 collations (`utf8mb4_0900_ai_ci`) the host does not know
   - `DEFINER=` clauses naming a user that does not exist here
   - `NO_AUTO_CREATE_USER` in `sql_mode`, which MySQL 8 rejects
   - storage engines the host did not compile in
   - indexes too long for an old InnoDB (`ROW_FORMAT=DYNAMIC`)
   - the dump's own `CREATE DATABASE` / `USE`, which would otherwise move the
     import off the database your host actually gave you
3. **Rewrites the database credentials** in the site's own config files,
   covering the four shapes a hand-written site uses: `define()`, plain
   `$variable`, inline `mysqli_connect()` literals, and a PDO DSN. Every
   original is kept as `<file>.pre-install.bak`.
4. **Reports the dev-machine leftovers** it will not change for you: hardcoded
   `C:\xampp\...` paths, `http://localhost/...` URLs, `.test` hostnames, and
   `display_errors` left on.
5. **Self-checks** and prints a pass/warn/fail table: PHP version and
   extensions, database connect, table row counts, a real write-and-read-back
   probe, the public page and the dashboard fetched over HTTP, writable upload
   folders, and an index file at the root.
6. **Flags logins carried in from the dev database** that still use `admin` /
   `password` and similar.
7. **Deletes itself**, the archive, the dump, and every `.pre-install.bak`
   when you click the last button.

## Using it

```
php build.php                      # prints the file to upload and a one-time key
```

Then, in the host's file manager:

1. Upload `dist/install.php`, your site `.zip`, and your `.sql` dump into the
   web root, all three in the same folder.
2. Open `https://yourdomain/install.php` and paste the key.
3. Fill in the database name, user, and password, then press **Install**.
4. Read the report, check the site, then press **Delete the installer**.

### The key

`build.php` stamps a one-time key into the file, so the installer is never a
public "unpack anything" endpoint while it sits on a live site. If you build
without a key, it writes a random one to `install-key.txt` on first load and
asks you to read that file in the file manager instead, which proves
filesystem access just as well.

## Running the tests

```
sh tests/run.sh      # lint + unit + a full install against a scratch host
```

`tests/run.sh` rebuilds the fixture, drops and recreates a scratch database,
stages a throwaway docroot, runs the installer, and then asserts the result.
It refuses to run if the web server on the expected port is not serving its
own docroot, so a failed bind can never silently test someone else's site.

| suite             | what it covers                                                      |
|-------------------|---------------------------------------------------------------------|
| `tests/unit.php`  | SQL splitting, remediation, credential classification, archive safety |
| `tests/e2e.php`   | a real install, then HTTP + direct SQL assertions on the result     |
| `tests/ui.py`     | the browser flow: key gate, form, report, cleanup, screenshots       |

### The fixture is deliberately nasty

`tests/make_fixture.php` builds a handover that fails in every way a real one
does: a zip wrapped in `mysite/`, a MySQL 8 dump with `DEFINER` on a view and
a trigger, `DELIMITER` blocks, rows containing nested quotes, backslashes, a
`--` that is not a comment because it is inside a string, a semicolon inside a
string, and a 4-byte emoji to prove the charset survives. Three different
config files hold the dev credentials in three different shapes.

## Things worth knowing

**Triggers and stored routines may be refused by the host.** With binary
logging on and `log_bin_trust_function_creators` off, no non-SUPER user can
create a trigger, and stripping the `DEFINER` does not help. The installer
reports those separately as *blocked by the host* rather than as a failed
install, because the site and dashboard still work: the pages read the tables
directly. The report names the exact setting to ask the host to change. Both
cases are covered by the test suite.

**The page checks can be skipped on small hosting.** The self-check asks the
server to serve its own pages while the server is busy running the installer.
On a host with a single PHP worker that cannot be answered, so the check times
out after 8 seconds and is reported as "open these two links yourself" with
the URLs, not as a broken site.

**Large dumps.** The importer reads the dump into memory, so it refuses up
front if the file is large relative to PHP's `memory_limit` and tells you to
import that one through the panel instead. Re-running afterwards still does
the config rewiring and all the checks.

## Layout

```
build.php              bundles installer/ into dist/install.php and stamps the key
installer/install.php  the UI, the CLI entry point, and the flow
installer/lib_sql.php    dump splitting and statement repair
installer/lib_config.php credential rewriting and dev-leftover scanning
installer/lib_core.php   archive extraction, database, self-check
tests/                 fixture builder, unit, end-to-end, and browser suites
```

The `installer/` files are only split up so they can be unit tested. What ships
is the single bundled `dist/install.php`.
