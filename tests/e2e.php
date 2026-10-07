<?php
/**
 * End to end test of the installer against a simulated shared host.
 *
 * It asserts the client's actual acceptance criteria, not just HTTP 200:
 *   - the public site loads at the root and renders real rows from the dump
 *   - the draft article does NOT appear publicly
 *   - UTF-8 content (an emoji, a backslash, nested quotes) survived the import
 *   - the dashboard login works with a real POST and a session cookie
 *   - the dashboard WRITES: a new article appears, and the trigger fires
 *   - the site's own settings (SITE_NAME) were not clobbered by the rewriter
 *
 * Usage:
 *   DOCROOT=... BASEURL=... DBHOST=... DBNAME=... DBUSER=... DBPASS=... \
 *     php tests/e2e.php
 */

$docroot = getenv('DOCROOT');
$baseUrl = rtrim(getenv('BASEURL'), '/');
$creds = array(
    'host' => getenv('DBHOST'),
    'name' => getenv('DBNAME'),
    'user' => getenv('DBUSER'),
    'pass' => getenv('DBPASS'),
);
if (!$docroot || !$baseUrl) {
    fwrite(STDERR, "DOCROOT and BASEURL are required\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$failures = array();

function t($label, $ok, $detail = '')
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail ? "\n       " . $detail : '');
        echo "  FAIL $label" . ($detail ? " -- $detail" : '') . "\n";
    }
}

$cookieJar = tempnam(sys_get_temp_dir(), 'flcookie');

/**
 * One request, keeping cookies across calls so a login actually persists.
 */
function req($url, $post = null)
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 20,
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return array('status' => $status, 'body' => (string)$body, 'error' => $err);
}

/* ------------------------------------------------- 0. the report itself */

echo "\n-- installer report --\n";

$reportPath = getenv('REPORT');
if ($reportPath && is_file($reportPath)) {
    $r = json_decode(file_get_contents($reportPath), true);
    t('report is valid JSON', is_array($r));
    t('the wrapper folder was stripped',
        in_array(true, array_map(function ($n) {
            return strpos($n, 'stripped that folder') !== false;
        }, $r['notes']), true));
    t('all 7 site files were unpacked', isset($r['extracted']) && $r['extracted'] === 7,
        'extracted=' . (isset($r['extracted']) ? $r['extracted'] : 'unset'));
    t('no hard errors', empty($r['errors']), implode(' | ', (array)$r['errors']));
    t('no SQL statement failed unrepaired', empty($r['sql_failed']),
        implode(' | ', array_map(function ($x) {
            return $x['error'];
        }, (array)$r['sql_failed'])));
    t('the MySQL 8 sql_mode flag was repaired',
        count(array_filter((array)$r['sql_repaired'], function ($x) {
            return strpos($x['why'], 'NO_AUTO_CREATE_USER') !== false;
        })) === 1);
    t('the view\'s DEFINER was stripped',
        count(array_filter((array)$r['sql_repaired'], function ($x) {
            return strpos($x['why'], 'DEFINER') !== false;
        })) >= 1,
        'repaired: ' . implode(' | ', array_map(function ($x) {
            return $x['why'];
        }, (array)$r['sql_repaired'])));

    // Whether the trigger can be created at all depends on the server. With
    // binary logging on and log_bin_trust_function_creators off, no
    // non-SUPER user can create one, which is the common shared hosting
    // setup. Either outcome is acceptable; being UNCLEAR about it is not.
    $blocked = (array)(isset($r['sql_host_blocked']) ? $r['sql_host_blocked'] : array());
    $triggerBlocked = count(array_filter($blocked, function ($x) {
        return stripos($x['stmt'], 'TRIGGER') !== false;
    })) === 1;
    $GLOBALS['fl_trigger_blocked'] = $triggerBlocked;
    if ($triggerBlocked) {
        echo "  ..   this server forbids non-SUPER trigger creation; checking the message instead\n";
        $note = '';
        foreach ($blocked as $b) {
            if (stripos($b['stmt'], 'TRIGGER') !== false) {
                $note = $b['note'];
            }
        }
        t('the blocked trigger is explained in plain words, not raw MySQL',
            strpos($note, 'The site and the dashboard work without it') !== false);
        t('the message names the exact setting to ask the host for',
            strpos($note, 'log_bin_trust_function_creators') !== false, $note);
        t('a host-blocked object is NOT counted as a failure', empty($r['sql_failed']));
        t('it is surfaced as a warning', in_array($note, (array)$r['warnings'], true));
    } else {
        t('the trigger\'s DEFINER was stripped and it was created',
            count(array_filter((array)$r['sql_repaired'], function ($x) {
                return strpos($x['why'], 'DEFINER') !== false;
            })) === 2);
    }
    t('the dump\'s own CREATE DATABASE and USE were skipped',
        isset($r['sql_skipped']) && $r['sql_skipped'] >= 2);
    t('all five tables exist',
        isset($r['table_counts']) && count($r['table_counts']) === 5,
        'tables: ' . implode(',', array_keys((array)$r['table_counts'])));
    t('articles has 4 rows',
        isset($r['table_counts']['articles']) && $r['table_counts']['articles'] === 4);
    t('the view returns only the 3 published rows',
        isset($r['table_counts']['published_articles'])
        && $r['table_counts']['published_articles'] === 3);
    t('three config files were rewired', count((array)$r['config_changed']) === 3,
        'changed: ' . count((array)$r['config_changed']));
    t('SITE_NAME was NOT rewritten',
        !in_array(true, array_map(function ($f) {
            foreach ($f['changes'] as $c) {
                if (strpos($c, 'SITE_NAME') !== false) {
                    return true;
                }
            }
            return false;
        }, (array)$r['config_changed']), true));
    $kinds = array();
    foreach ((array)$r['leftovers'] as $l) {
        $kinds[$l['kind']] = true;
    }
    t('the hardcoded Windows upload path was reported', isset($kinds['windows-path']),
        'kinds: ' . implode(',', array_keys($kinds)));
    t('the localhost SITE_URL was reported', isset($kinds['localhost-url']));
    t('display_errors was reported', isset($kinds['errors-visible']));
    t('both weak dev logins were reported', count((array)$r['weak_logins']) === 2);
    t('the dashboard path was found',
        isset($r['admin_path']) && $r['admin_path'] === 'admin/');
    $fails = array_filter((array)$r['checks'], function ($c) {
        return $c[2] === 'fail';
    });
    t('no check failed', count($fails) === 0,
        implode(' | ', array_map(function ($c) {
            return $c[0] . ': ' . $c[1];
        }, $fails)));
} else {
    t('report file exists', false, (string)$reportPath);
}

/* ------------------------------------------------------ 1. the public site */

echo "\n-- public site --\n";

$home = req($baseUrl . '/');
t('the root returns 200', $home['status'] === 200, 'status=' . $home['status'] . ' ' . $home['error']);
t('the page prints no PHP error',
    strpos($home['body'], 'Fatal error') === false
    && strpos($home['body'], 'Warning:') === false
    && strpos($home['body'], 'Database connection failed') === false,
    substr(preg_replace('/\s+/', ' ', strip_tags($home['body'])), 0, 200));
t('the site name survived the rewrite',
    strpos($home['body'], 'Harbour &amp; Co') !== false || strpos($home['body'], 'Harbour & Co') !== false);
t('a published article renders',
    strpos($home['body'], 'Welcome to Harbour') !== false);
t('the nested-quotes row renders',
    strpos($home['body'], 'A quote inside a quote') !== false);
t('the emoji survived the import (charset is right)',
    strpos($home['body'], "\xF0\x9F\x8C\x8A") !== false);
t('the backslash path in the body survived',
    strpos($home['body'], 'C:\\Users\\dev\\file.txt') !== false);
t('the title containing "--" and ";" survived intact',
    strpos($home['body'], 'Not a comment -- this is body text; with a semicolon') !== false);
t('the DRAFT article is not public',
    strpos($home['body'], 'Still a draft') === false);
t('the stylesheet loads',
    req($baseUrl . '/assets/style.css')['status'] === 200);

/* --------------------------------------------------------- 2. the dashboard */

echo "\n-- dashboard --\n";

$adm = req($baseUrl . '/admin/');
t('the dashboard returns 200', $adm['status'] === 200, 'status=' . $adm['status']);
t('the dashboard shows a login form',
    strpos($adm['body'], 'name="username"') !== false);
t('the dashboard prints no DB error',
    strpos($adm['body'], 'Database connection failed') === false
    && strpos($adm['body'], 'Fatal error') === false);

$bad = req($baseUrl . '/admin/', array('username' => 'admin', 'password' => 'definitely-wrong'));
t('a wrong password is refused',
    strpos($bad['body'], 'Wrong username or password') !== false);
t('a wrong password does not sign you in',
    strpos($bad['body'], 'Signed in as') === false);

$ok = req($baseUrl . '/admin/', array('username' => 'admin', 'password' => 'admin'));
t('the dev credentials from the dump log in',
    strpos($ok['body'], 'Signed in as admin') !== false,
    substr(preg_replace('/\s+/', ' ', strip_tags($ok['body'])), 0, 200));

// A read: the article list must come out of the database.
t('the dashboard READS the articles table',
    strpos($ok['body'], 'Welcome to Harbour') !== false
    && strpos($ok['body'], 'Still a draft') !== false);

// A write, with a value unique to this run.
$marker = 'e2e marker ' . substr(md5(microtime(true) . getmypid()), 0, 10);
$wrote = req($baseUrl . '/admin/', array('title' => $marker, 'body' => 'written by the e2e test'));
t('the dashboard WRITES a new article',
    strpos($wrote['body'], $marker) !== false);

$homeAfter = req($baseUrl . '/');
t('the new article appears on the public site',
    strpos($homeAfter['body'], $marker) !== false);

/* ----------------------------------------------------- 3. straight to MySQL */

echo "\n-- database --\n";

require dirname(__DIR__) . '/installer/lib_sql.php';
require dirname(__DIR__) . '/installer/lib_config.php';
require dirname(__DIR__) . '/installer/lib_core.php';

$db = fl_db_connect($creds);
t('a direct connection works', $db['ok'], $db['error']);
if ($db['ok']) {
    $rows = fl_db_rows($db, 'SELECT COUNT(*) AS c FROM articles');
    t('the write really landed in the table', (int)$rows[0]['c'] === 5,
        'articles=' . $rows[0]['c']);

    $log = fl_db_rows($db, 'SELECT what FROM audit_log ORDER BY id DESC LIMIT 1');
    if (!empty($GLOBALS['fl_trigger_blocked'])) {
        t('audit_log is correctly empty, since this server refused the trigger',
            count($log) === 0);
    } else {
        t('the repaired trigger fired on the insert',
            $log && strpos($log[0]['what'], $marker) !== false,
            $log ? $log[0]['what'] : 'audit_log is empty');
    }

    $set = fl_db_rows($db, "SELECT v FROM settings WHERE k = 'tagline'");
    t('settings rows imported', $set && $set[0]['v'] === 'Chandlery since 1994');

    $enc = fl_db_rows($db, 'SELECT body FROM articles WHERE id = 3');
    t('the emoji is stored as 4 byte utf8mb4 in MySQL',
        $enc && strpos($enc[0]['body'], "\xF0\x9F\x8C\x8A") !== false);
}

/* -------------------------------------------------------- 4. the filesystem */

echo "\n-- filesystem --\n";

t('index.php is at the web root, not inside mysite/',
    is_file($docroot . '/index.php'));
t('the wrapper folder was not left behind',
    !is_dir($docroot . '/mysite'));
t('the admin folder is in place',
    is_file($docroot . '/admin/index.php'));
t('the uploads folder came across',
    is_dir($docroot . '/uploads'));
t('a backup of the edited config exists',
    is_file($docroot . '/includes/config.php.pre-install.bak'));
t('the rewritten config is still valid PHP',
    fl_lint($docroot . '/includes/config.php'));
t('the rewritten legacy db file is still valid PHP',
    fl_lint($docroot . '/includes/db_legacy.php'));
t('the rewritten PDO file is still valid PHP',
    fl_lint($docroot . '/includes/report.php'));
t('the installer did not rewrite itself',
    !is_file($docroot . '/install.php.pre-install.bak'));
$cfg = file_get_contents($docroot . '/includes/config.php');
t('the live config holds the NEW database name',
    strpos($cfg, $creds['name']) !== false);
t('the live config no longer holds the dev password',
    strpos($cfg, 'devpass123') === false);
t('SITE_NAME is untouched in the live config',
    strpos($cfg, "'Harbour & Co'") !== false);

echo "\n";
if ($fail) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
}
echo ($fail ? 'FAIL' : 'PASS') . ": $pass passed, $fail failed\n";
@unlink($cookieJar);
exit($fail ? 1 : 0);

function fl_lint($path)
{
    $out = array();
    $rc = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return $rc === 0;
}
