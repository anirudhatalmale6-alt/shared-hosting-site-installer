<?php
/**
 * One file site installer for a hand written PHP + MySQL site.
 *
 * Drop this next to the site .zip and the .sql dump in the web root, open it
 * in a browser once, and it will:
 *   1. unpack the site into the web root, stripping a wrapper folder
 *   2. import the dump, repairing collations and DEFINER clauses as it goes
 *   3. rewrite the database credentials in the site's own config files
 *   4. run a self check over HTTP and print exactly what passed and failed
 *   5. delete itself, the archive, the dump and its own backups
 *
 * Nothing here needs FTP, SSH or shell access.
 *
 * Targets PHP 5.6+ so it still runs on an older shared host.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(0);
@ignore_user_abort(true);

define('FL_VERSION', '1.0.0');

/**
 * sha256 of the one time key. Replaced per delivery by build.php; while it
 * holds the placeholder the installer falls back to writing a key file, so an
 * unkeyed copy is still not a public endpoint.
 */
define('FL_KEY_HASH', '__FL_KEY_HASH__');

define('FL_DIR', __DIR__);
define('FL_SELF', basename(__FILE__));
define('FL_KEYFILE', FL_DIR . '/install-key.txt');

foreach (array('lib_sql.php', 'lib_config.php', 'lib_core.php') as $fl_lib) {
    if (is_file(FL_DIR . '/' . $fl_lib)) {
        require_once FL_DIR . '/' . $fl_lib;
    }
}

if (!function_exists('fl_str_contains')) {
    function fl_str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

function fl_new_report()
{
    return array('notes' => array(), 'warnings' => array(), 'errors' => array());
}

/* ================================================================== auth */

/**
 * Proof of access, so this file is never a public unpack-anything endpoint.
 *
 * Keyed copy: the key I send separately must match the embedded hash.
 * Unkeyed copy: a random key is written to install-key.txt, and you prove
 * filesystem access by reading it in the panel's file manager.
 */
function fl_check_key($given)
{
    $hash = FL_KEY_HASH;
    if ($hash !== '__FL_KEY' . '_HASH__') {
        return hash_equals($hash, hash('sha256', (string)$given));
    }
    if (!is_file(FL_KEYFILE)) {
        $key = bin2hex(fl_random_bytes(12));
        @file_put_contents(FL_KEYFILE, $key . "\n");
        return false;
    }
    $want = trim((string)@file_get_contents(FL_KEYFILE));
    return $want !== '' && hash_equals($want, trim((string)$given));
}

function fl_random_bytes($n)
{
    if (function_exists('random_bytes')) {
        return random_bytes($n);
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return openssl_random_pseudo_bytes($n);
    }
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= chr(mt_rand(0, 255));
    }
    return $s;
}

if (!function_exists('hash_equals')) {
    function hash_equals($a, $b)
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $r = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $r |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $r === 0;
    }
}

/* =================================================================== CLI */

if (PHP_SAPI === 'cli' && isset($argv) && in_array('--cli', $argv, true)) {
    $opts = array();
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
            $opts[$m[1]] = isset($m[2]) ? $m[2] : true;
        }
    }
    $root = isset($opts['root']) ? rtrim($opts['root'], '/') : FL_DIR;
    $creds = array(
        'host' => isset($opts['dbhost']) ? $opts['dbhost'] : 'localhost',
        'name' => isset($opts['dbname']) ? $opts['dbname'] : '',
        'user' => isset($opts['dbuser']) ? $opts['dbuser'] : '',
        'pass' => isset($opts['dbpass']) ? $opts['dbpass'] : '',
    );
    $report = fl_new_report();
    $inputs = fl_find_inputs($root);

    $archive = isset($opts['archive']) ? $opts['archive']
        : (isset($inputs['archives'][0]) ? $inputs['archives'][0]['path'] : null);
    $dump = isset($opts['dump']) ? $opts['dump']
        : (isset($inputs['dumps'][0]) ? $inputs['dumps'][0]['path'] : null);

    if ($archive) {
        fl_extract_archive($archive, $root, $report);
        fl_demote_placeholder_index($root, $report);
    } else {
        $report['warnings'][] = 'No archive found, skipping extraction.';
    }

    if ($dump) {
        $db = fl_db_connect($creds);
        if (!$db['ok']) {
            $report['errors'][] = 'Database connect failed: ' . $db['error'];
        } else {
            list($sql, $err) = fl_read_dump($dump);
            if ($sql === null) {
                $report['errors'][] = $err;
            } else {
                fl_import_dump($db, $sql, $report);
            }
        }
    } else {
        $report['warnings'][] = 'No dump found, skipping import.';
    }

    fl_rewire_configs($root, $creds, $report);
    fl_selfcheck($root, $creds, isset($opts['baseurl']) ? $opts['baseurl'] : null, $report);

    echo json_encode($report, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0), "\n";
    $hasFail = !empty($report['errors']) || !empty($report['sql_failed']);
    if (!empty($report['checks'])) {
        foreach ($report['checks'] as $c) {
            if ($c[2] === 'fail') {
                $hasFail = true;
            }
        }
    }
    exit($hasFail ? 1 : 0);
}

/* ================================================================ web UI */

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : 'start');
$key = isset($_POST['key']) ? $_POST['key'] : (isset($_GET['key']) ? $_GET['key'] : '');
$authed = $key !== '' && fl_check_key($key);
$creds = array(
    'host' => isset($_POST['dbhost']) ? trim($_POST['dbhost']) : 'localhost',
    'name' => isset($_POST['dbname']) ? trim($_POST['dbname']) : '',
    'user' => isset($_POST['dbuser']) ? trim($_POST['dbuser']) : '',
    'pass' => isset($_POST['dbpass']) ? $_POST['dbpass'] : '',
);

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fl_hidden($creds, $key)
{
    echo '<input type="hidden" name="key" value="', h($key), '">';
    foreach (array('host' => 'dbhost', 'name' => 'dbname', 'user' => 'dbuser', 'pass' => 'dbpass') as $k => $f) {
        echo '<input type="hidden" name="', $f, '" value="', h($creds[$k]), '">';
    }
}

$inputs = fl_find_inputs(FL_DIR);

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Site installer</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--ink:#1d2530;--mut:#5d6b7e;--line:#dfe4ea;
--ok:#0a7d44;--okbg:#e8f6ee;--warn:#8a5a00;--warnbg:#fdf3e0;--bad:#b3261e;--badbg:#fdeceb;--acc:#1b56b8}
*{box-sizing:border-box}
body{margin:0;padding:28px 16px;background:var(--bg);color:var(--ink);
font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:860px;margin:0 auto}
h1{font-size:21px;margin:0 0 4px}
.sub{color:var(--mut);font-size:13px;margin:0 0 22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px;margin-bottom:16px}
h2{font-size:15px;margin:0 0 12px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
label{display:block;font-weight:600;font-size:13px;margin:12px 0 5px}
input[type=text],input[type=password]{width:100%;padding:9px 11px;border:1px solid var(--line);
border-radius:6px;font-size:14px;font-family:inherit;background:#fff;color:var(--ink)}
input:focus{outline:2px solid var(--acc);outline-offset:-1px;border-color:var(--acc)}
.hint{color:var(--mut);font-size:12px;margin-top:4px}
button{margin-top:18px;background:var(--acc);color:#fff;border:0;border-radius:6px;
padding:11px 20px;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}
button:hover{filter:brightness(1.08)}
button.sec{background:#fff;color:var(--ink);border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;font-size:14px}
td,th{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--mut)}
tr:last-child td{border-bottom:0}
.tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;
text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.pass{background:var(--okbg);color:var(--ok)}
.warn{background:var(--warnbg);color:var(--warn)}
.fail{background:var(--badbg);color:var(--bad)}
.msg{padding:11px 14px;border-radius:7px;margin:0 0 10px;font-size:14px}
.msg.e{background:var(--badbg);color:var(--bad)}
.msg.w{background:var(--warnbg);color:var(--warn)}
.msg.n{background:var(--okbg);color:var(--ok)}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
ul{margin:6px 0;padding-left:20px}
li{margin:3px 0}
.kv{display:flex;flex-wrap:wrap;gap:6px 22px;font-size:13px;color:var(--mut)}
.banner{border-radius:10px;padding:16px 18px;margin-bottom:16px;font-weight:600}
.banner.good{background:var(--okbg);color:var(--ok);border:1px solid #bfe3cd}
.banner.bad{background:var(--badbg);color:var(--bad);border:1px solid #f3c9c6}
@media (prefers-color-scheme:dark){
:root{--bg:#14181d;--card:#1b2026;--ink:#e8ecf1;--mut:#9aa7b6;--line:#2c343d;
--okbg:#11301f;--ok:#58d68d;--warnbg:#33260c;--warn:#f0c05a;--badbg:#3a1715;--bad:#f3807a;--acc:#4d8dfd}
input[type=text],input[type=password]{background:#11151a;color:var(--ink)}
button.sec{background:#11151a}
.banner.good{border-color:#1d4a30}.banner.bad{border-color:#5a2420}}
</style>
</head>
<body>
<div class="wrap">
<h1>Site installer</h1>
<p class="sub">v<?php echo FL_VERSION; ?> &middot; runs once, then deletes itself &middot;
PHP <?php echo h(PHP_VERSION); ?> on <?php echo h(php_uname('s')); ?></p>

<?php if (!$authed): ?>

  <div class="card">
    <h2>One time key</h2>
    <?php if (FL_KEY_HASH === '__FL_KEY' . '_HASH__'): ?>
      <p>Open <code>install-key.txt</code> in this same folder with the file manager and paste
      what is inside. That file was created the first time this page was opened.</p>
      <?php if ($key !== ''): ?><p class="msg e">That key did not match.</p><?php endif; ?>
    <?php else: ?>
      <p>Paste the key I sent you in chat. It only works for this install.</p>
      <?php if ($key !== ''): ?><p class="msg e">That key did not match.</p><?php endif; ?>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="preflight">
      <label for="k">Key</label>
      <input type="text" id="k" name="key" autocomplete="off" autofocus>
      <button type="submit">Continue</button>
    </form>
  </div>

<?php elseif ($action === 'install' || $action === 'recheck'): ?>

  <?php
  $report = fl_new_report();
  $root = FL_DIR;
  $wipe = !empty($_POST['wipe']);
  $doExtract = $action === 'install' && !empty($_POST['do_extract']);
  $doImport = $action === 'install' && !empty($_POST['do_import']);
  $archive = isset($_POST['archive']) ? $_POST['archive'] : '';
  $dump = isset($_POST['dump']) ? $_POST['dump'] : '';

  // Only ever touch a file that is actually sitting in this folder.
  $archivePath = null;
  foreach ($inputs['archives'] as $a) {
      if ($a['name'] === $archive) {
          $archivePath = $a['path'];
      }
  }
  $dumpPath = null;
  foreach ($inputs['dumps'] as $d) {
      if ($d['name'] === $dump) {
          $dumpPath = $d['path'];
      }
  }

  if ($doExtract) {
      if ($archivePath === null) {
          $report['errors'][] = 'The archive you picked is no longer in this folder.';
      } else {
          fl_extract_archive($archivePath, $root, $report);
          fl_demote_placeholder_index($root, $report);
      }
  }

  $db = fl_db_connect($creds);
  if (!$db['ok']) {
      $report['errors'][] = 'Could not connect to the database: ' . $db['error'];
  } elseif ($doImport) {
      if ($dumpPath === null) {
          $report['errors'][] = 'The dump you picked is no longer in this folder.';
      } else {
          if ($wipe) {
              $existing = array();
              foreach (fl_db_rows($db, 'SHOW TABLES') as $r) {
                  $existing[] = reset($r);
              }
              if ($existing) {
                  fl_db_exec($db, 'SET FOREIGN_KEY_CHECKS=0');
                  foreach ($existing as $t) {
                      fl_db_exec($db, 'DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
                  }
                  fl_db_exec($db, 'SET FOREIGN_KEY_CHECKS=1');
                  $report['notes'][] = 'Dropped ' . count($existing)
                      . ' table(s) that were already in the database, as you asked.';
              }
          }
          list($sql, $err) = fl_read_dump($dumpPath);
          if ($sql === null) {
              $report['errors'][] = $err;
          } else {
              fl_import_dump($db, $sql, $report);
          }
      }
  }

  fl_rewire_configs($root, $creds, $report);
  fl_selfcheck($root, $creds, fl_guess_base_url(), $report);

  $fails = 0;
  $warns = 0;
  foreach ($report['checks'] as $c) {
      if ($c[2] === 'fail') {
          $fails++;
      } elseif ($c[2] === 'warn') {
          $warns++;
      }
  }
  if (!empty($report['sql_failed'])) {
      $fails += count($report['sql_failed']);
  }
  $fails += count($report['errors']);
  ?>

  <div class="banner <?php echo $fails ? 'bad' : 'good'; ?>">
    <?php if ($fails): ?>
      <?php echo $fails; ?> thing<?php echo $fails === 1 ? '' : 's'; ?> still
      need<?php echo $fails === 1 ? 's' : ''; ?> fixing. Send me this page and I will deal with it.
    <?php else: ?>
      Everything passed<?php echo $warns ? ', with ' . $warns . ' note' . ($warns === 1 ? '' : 's') . ' to read' : ''; ?>.
      The site and the dashboard are live.
    <?php endif; ?>
  </div>

  <?php foreach ($report['errors'] as $m): ?><p class="msg e"><?php echo h($m); ?></p><?php endforeach; ?>
  <?php foreach ($report['warnings'] as $m): ?><p class="msg w"><?php echo h($m); ?></p><?php endforeach; ?>
  <?php foreach ($report['notes'] as $m): ?><p class="msg n"><?php echo h($m); ?></p><?php endforeach; ?>

  <?php
  $base = fl_guess_base_url();
  if ($base):
      $adminLink = isset($report['admin_path']) && $report['admin_path']
          ? rtrim($base, '/') . '/' . ltrim($report['admin_path'], '/') : null;
  ?>
  <div class="card">
    <h2>Open it</h2>
    <p><a href="<?php echo h(rtrim($base, '/') . '/'); ?>" target="_blank" rel="noopener">
      <?php echo h(rtrim($base, '/') . '/'); ?></a> &mdash; the public site</p>
    <?php if ($adminLink): ?>
      <p><a href="<?php echo h($adminLink); ?>" target="_blank" rel="noopener">
        <?php echo h($adminLink); ?></a> &mdash; the dashboard</p>
    <?php endif; ?>
    <?php if (!empty($report['manual_urls'])): ?>
      <p class="hint">Please click both: this server could not load its own pages while it was
      running me, so those two rows below are unchecked rather than failing.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Checks</h2>
    <div class="scroll"><table>
      <tr><th>What</th><th>Result</th><th>&nbsp;</th></tr>
      <?php foreach ($report['checks'] as $c): ?>
        <tr><td><?php echo h($c[0]); ?></td>
            <td class="mono"><?php echo h($c[1]); ?></td>
            <td><span class="tag <?php echo $c[2]; ?>"><?php echo $c[2]; ?></span></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>

  <?php if (isset($report['statements'])): ?>
  <div class="card">
    <h2>Database import</h2>
    <div class="kv">
      <span><?php echo (int)$report['statements']; ?> statements in the dump</span>
      <span><?php echo (int)$report['sql_ran']; ?> ran</span>
      <span><?php echo (int)$report['sql_skipped']; ?> skipped</span>
      <span><?php echo count($report['sql_repaired']); ?> repaired</span>
      <span><?php echo count($report['sql_host_blocked']); ?> blocked by the host</span>
      <span><?php echo count($report['sql_failed']); ?> failed</span>
    </div>
    <?php if ($report['sql_repaired']): ?>
      <p style="margin-top:14px"><strong>Repaired on the way in</strong></p>
      <ul>
      <?php foreach ($report['sql_repaired'] as $r): ?>
        <li><?php echo h($r['why']); ?><br><span class="mono" style="color:var(--mut)"><?php
            echo h($r['stmt']); ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($report['sql_host_blocked'])): ?>
      <p style="margin-top:14px"><strong>Blocked by the host, not broken</strong></p>
      <ul>
      <?php foreach ($report['sql_host_blocked'] as $r): ?>
        <li><?php echo h($r['note']); ?><br><span class="mono" style="color:var(--mut)"><?php
            echo h($r['stmt']); ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($report['sql_failed']): ?>
      <p style="margin-top:14px"><strong>Would not run</strong></p>
      <ul>
      <?php foreach ($report['sql_failed'] as $r): ?>
        <li><?php echo h($r['error']); ?><br><span class="mono" style="color:var(--mut)"><?php
            echo h($r['stmt']); ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['table_counts'])): ?>
  <div class="card">
    <h2>Tables</h2>
    <div class="scroll"><table>
      <tr><th>Table</th><th>Rows</th></tr>
      <?php foreach ($report['table_counts'] as $t => $c): ?>
        <tr><td class="mono"><?php echo h($t); ?></td>
            <td><?php echo $c < 0 ? 'unreadable' : (int)$c; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Config files</h2>
    <p class="hint"><?php echo (int)$report['config_scanned']; ?> PHP files read.</p>
    <?php if ($report['config_changed']): ?>
      <ul>
      <?php foreach ($report['config_changed'] as $f): ?>
        <li><span class="mono"><?php echo h($f['file']); ?></span>
          <ul><?php foreach ($f['changes'] as $ch): ?>
            <li class="mono"><?php echo h($ch); ?></li>
          <?php endforeach; ?></ul>
          <span class="hint">original kept as <?php echo h($f['file']); ?>.pre-install.bak</span>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="msg w">I did not find any database credentials to rewrite. If the site reads them
      from somewhere unusual, tell me the file name and I will wire it by hand.</p>
    <?php endif; ?>
  </div>

  <?php if (!empty($report['leftovers'])): ?>
  <div class="card">
    <h2>Dev machine leftovers</h2>
    <p class="hint">These are the things that break a site after a move. Nothing here was changed
    automatically, because the right replacement depends on what the page is for.</p>
    <div class="scroll"><table>
      <tr><th>File</th><th>Line</th><th>Found</th><th>Why it matters</th></tr>
      <?php foreach (array_slice($report['leftovers'], 0, 60) as $l): ?>
        <tr><td class="mono"><?php echo h($l['file']); ?></td>
            <td><?php echo (int)$l['line']; ?></td>
            <td class="mono"><?php echo h($l['text']); ?></td>
            <td><?php echo h($l['note']); ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if (count($report['leftovers']) > 60): ?>
      <p class="hint">and <?php echo count($report['leftovers']) - 60; ?> more.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['weak_logins'])): ?>
  <div class="card">
    <h2>Logins that need changing</h2>
    <p class="hint">Carried in from the dev database. Anyone who finds the dashboard can use these.</p>
    <div class="scroll"><table>
      <tr><th>Table</th><th>Account</th><th>Problem</th></tr>
      <?php foreach ($report['weak_logins'] as $w): ?>
        <tr><td class="mono"><?php echo h($w['table']); ?></td>
            <td class="mono"><?php echo h($w['user']); ?></td>
            <td><?php echo h($w['why']); ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Last step</h2>
    <p>Check the site yourself first. When you are happy, clear the installer away: it removes this
    file, its libraries, the key file, the archive, the dump and the <code>.pre-install.bak</code>
    copies. Leaving them on a live site is a real risk, so do not skip it.</p>
    <form method="post" onsubmit="return confirm('Delete the installer, the archive, the dump and the backups?')">
      <?php fl_hidden($creds, $key); ?>
      <input type="hidden" name="action" value="cleanup">
      <input type="hidden" name="archive" value="<?php echo h($archive); ?>">
      <input type="hidden" name="dump" value="<?php echo h($dump); ?>">
      <button type="submit">Delete the installer and the uploaded files</button>
    </form>
    <form method="post" style="display:inline">
      <?php fl_hidden($creds, $key); ?>
      <input type="hidden" name="action" value="recheck">
      <button type="submit" class="sec">Run the checks again</button>
    </form>
  </div>

<?php elseif ($action === 'cleanup'): ?>

  <?php
  $removed = array();
  $kept = array();
  $targets = array(FL_DIR . '/' . FL_SELF, FL_KEYFILE,
                   FL_DIR . '/lib_sql.php', FL_DIR . '/lib_config.php', FL_DIR . '/lib_core.php');
  foreach ($inputs['archives'] as $a) {
      if ($a['name'] === (isset($_POST['archive']) ? $_POST['archive'] : '')) {
          $targets[] = $a['path'];
      }
  }
  foreach ($inputs['dumps'] as $d) {
      if ($d['name'] === (isset($_POST['dump']) ? $_POST['dump'] : '')) {
          $targets[] = $d['path'];
      }
  }
  // The .bak copies hold the dev credentials, so they must not stay behind.
  $stack = array(FL_DIR);
  while ($stack) {
      $dir = array_pop($stack);
      foreach (array_diff((array)@scandir($dir), array('.', '..')) as $item) {
          $full = $dir . '/' . $item;
          if (is_dir($full)) {
              if (!in_array(strtolower($item), array('.git', 'node_modules', 'vendor'), true)) {
                  $stack[] = $full;
              }
          } elseif (substr($item, -16) === '.pre-install.bak') {
              $targets[] = $full;
          }
      }
  }
  foreach (array_unique($targets) as $t) {
      if (!is_file($t)) {
          continue;
      }
      if (@unlink($t)) {
          $removed[] = ltrim(substr($t, strlen(FL_DIR)), '/');
      } else {
          $kept[] = ltrim(substr($t, strlen(FL_DIR)), '/');
      }
  }
  ?>

  <div class="banner <?php echo $kept ? 'bad' : 'good'; ?>">
    <?php echo $kept ? 'Some files could not be deleted. Remove them by hand in the file manager.'
                     : 'Cleaned up. The installer is gone and the site is on its own.'; ?>
  </div>
  <div class="card">
    <h2>Deleted</h2>
    <ul><?php foreach ($removed as $r): ?><li class="mono"><?php echo h($r); ?></li><?php endforeach; ?></ul>
    <?php if ($kept): ?>
      <h2 style="margin-top:16px">Still there, delete these yourself</h2>
      <ul><?php foreach ($kept as $r): ?><li class="mono"><?php echo h($r); ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

<?php else: ?>

  <?php
  $db = $creds['name'] !== '' ? fl_db_connect($creds) : null;
  $existingTables = array();
  if ($db && $db['ok']) {
      foreach (fl_db_rows($db, 'SHOW TABLES') as $r) {
          $existingTables[] = reset($r);
      }
  }
  ?>

  <div class="card">
    <h2>What I found in this folder</h2>
    <?php if (!$inputs['archives'] && !$inputs['dumps']): ?>
      <p class="msg e">No site archive and no .sql dump here. Upload them into this same folder
      with the file manager, then reload this page.</p>
    <?php endif; ?>
    <div class="scroll"><table>
      <tr><th>File</th><th>Size</th><th>Read as</th></tr>
      <?php foreach ($inputs['archives'] as $a): ?>
        <tr><td class="mono"><?php echo h($a['name']); ?></td>
            <td><?php echo h(fl_human_bytes($a['size'])); ?></td><td>site archive</td></tr>
      <?php endforeach; ?>
      <?php foreach ($inputs['dumps'] as $d): ?>
        <tr><td class="mono"><?php echo h($d['name']); ?></td>
            <td><?php echo h(fl_human_bytes($d['size'])); ?></td><td>database dump</td></tr>
      <?php endforeach; ?>
    </table></div>
    <p class="hint" style="margin-top:12px">Web root here is
      <span class="mono"><?php echo h(FL_DIR); ?></span></p>
  </div>

  <div class="card">
    <h2>Database</h2>
    <form method="post">
      <input type="hidden" name="action" value="install">
      <input type="hidden" name="key" value="<?php echo h($key); ?>">

      <label for="dbhost">Host</label>
      <input type="text" id="dbhost" name="dbhost" value="<?php echo h($creds['host']); ?>">
      <p class="hint">Almost always <code>localhost</code> on shared hosting.</p>

      <label for="dbname">Database name</label>
      <input type="text" id="dbname" name="dbname" value="<?php echo h($creds['name']); ?>" autocomplete="off">

      <label for="dbuser">Database user</label>
      <input type="text" id="dbuser" name="dbuser" value="<?php echo h($creds['user']); ?>" autocomplete="off">

      <label for="dbpass">Database password</label>
      <input type="password" id="dbpass" name="dbpass" value="<?php echo h($creds['pass']); ?>" autocomplete="off">

      <?php if ($db !== null): ?>
        <?php if ($db['ok']): ?>
          <p class="msg n" style="margin-top:14px">Connected.
            <?php echo $existingTables ? 'This database already holds ' . count($existingTables)
                . ' table(s).' : 'The database is empty, which is what we want.'; ?></p>
        <?php else: ?>
          <p class="msg e" style="margin-top:14px"><?php echo h($db['error']); ?></p>
        <?php endif; ?>
      <?php endif; ?>

      <h2 style="margin-top:22px">What to do</h2>
      <label style="font-weight:400">
        <input type="checkbox" name="do_extract" value="1" <?php echo $inputs['archives'] ? 'checked' : 'disabled'; ?>>
        Unpack the site archive into the web root
      </label>
      <?php if ($inputs['archives']): ?>
        <select name="archive" style="margin-top:6px;padding:8px;border:1px solid var(--line);border-radius:6px">
          <?php foreach ($inputs['archives'] as $a): ?>
            <option value="<?php echo h($a['name']); ?>"><?php echo h($a['name']); ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <label style="font-weight:400;margin-top:14px">
        <input type="checkbox" name="do_import" value="1" <?php echo $inputs['dumps'] ? 'checked' : 'disabled'; ?>>
        Import the database dump
      </label>
      <?php if ($inputs['dumps']): ?>
        <select name="dump" style="margin-top:6px;padding:8px;border:1px solid var(--line);border-radius:6px">
          <?php foreach ($inputs['dumps'] as $d): ?>
            <option value="<?php echo h($d['name']); ?>"><?php echo h($d['name']); ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <?php if ($existingTables): ?>
        <label style="font-weight:400;margin-top:14px;color:var(--bad)">
          <input type="checkbox" name="wipe" value="1">
          Drop the <?php echo count($existingTables); ?> table(s) already in this database first.
          This deletes their data and cannot be undone.
        </label>
      <?php endif; ?>

      <button type="submit">Install</button>
      <p class="hint">A big dump can take a minute. Do not reload while it runs.</p>
    </form>
  </div>

<?php endif; ?>

</div>
</body>
</html>
