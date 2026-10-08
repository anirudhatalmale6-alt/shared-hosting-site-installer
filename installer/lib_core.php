<?php
/**
 * Core install steps: archive discovery, extraction, dump import, config
 * rewiring, and the post-install self check.
 *
 * Targets PHP 7.0+.
 */

/* ------------------------------------------------------------------ paths */

/**
 * Find the site archive and the SQL dump sitting next to the installer.
 *
 * @param string $dir
 * @return array{archives:array,dumps:array}
 */
function fl_find_inputs($dir)
{
    $archives = array();
    $dumps = array();
    $items = @scandir($dir);
    if ($items === false) {
        return array('archives' => $archives, 'dumps' => $dumps);
    }
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $full = $dir . '/' . $f;
        if (!is_file($full)) {
            continue;
        }
        $lower = strtolower($f);

        // Never treat the installer's own files as input.
        if (in_array($f, array('install.php', 'lib_sql.php', 'lib_config.php',
                               'lib_core.php', 'install-key.txt'), true)) {
            continue;
        }

        if (preg_match('/\.sql$/', $lower) || preg_match('/\.sql\.gz$/', $lower)
            || preg_match('/\.sql\.zip$/', $lower) || preg_match('/\.dump$/', $lower)) {
            $dumps[] = array('name' => $f, 'path' => $full, 'size' => filesize($full));
            continue;
        }

        if (preg_match('/\.(zip|tar\.gz|tgz|tar)$/', $lower)) {
            // A zip holding only SQL is a dump someone zipped to get it past
            // an upload filter, not a site archive.
            if (fl_zip_is_only_sql($full)) {
                $dumps[] = array('name' => $f, 'path' => $full, 'size' => filesize($full));
            } else {
                $archives[] = array('name' => $f, 'path' => $full, 'size' => filesize($full));
            }
            continue;
        }

        // Last resort: a control panel that refuses .sql uploads pushes
        // people to rename the file (dump.txt, db.bak, database). Look at
        // what is inside instead of trusting the extension.
        if (fl_looks_like_sql_dump($full)) {
            $dumps[] = array('name' => $f, 'path' => $full, 'size' => filesize($full),
                             'sniffed' => true);
        }
    }
    usort($archives, 'fl_cmp_size_desc');
    usort($dumps, 'fl_cmp_size_desc');
    return array('archives' => $archives, 'dumps' => $dumps);
}

/**
 * Does this file contain a SQL dump, whatever it happens to be called?
 *
 * Needed because a hosting panel can refuse a .sql upload outright, and the
 * only way the client gets the file onto the server is by renaming it.
 * Reads the head of the file only, so a large dump costs nothing here.
 *
 * @param string $path
 * @return bool
 */
function fl_looks_like_sql_dump($path)
{
    $size = @filesize($path);
    if ($size === false || $size < 24 || $size > 2147483647) {
        return false;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $head = (string)fread($fh, 65536);
    fclose($fh);

    if ($head === '') {
        return false;
    }
    // Reject anything binary; a dump is text.
    if (strpos($head, "\0") !== false) {
        return false;
    }
    // Reject source files that merely mention SQL.
    if (preg_match('/^\s*(<\?php|<!doctype|<html|\{|\[)/i', ltrim($head))) {
        return false;
    }

    $markers = 0;
    foreach (array('CREATE TABLE', 'INSERT INTO', 'DROP TABLE', 'MySQL dump',
                   'ENGINE=', 'LOCK TABLES', 'CREATE DATABASE', 'ALTER TABLE') as $m) {
        if (stripos($head, $m) !== false) {
            $markers++;
        }
    }
    // Two independent markers, so a stray "INSERT INTO" in a text file is
    // not enough to get a file imported into the client's database.
    return $markers >= 2;
}

/**
 * Is this zip just a SQL dump in a wrapper, rather than the site?
 *
 * @param string $path
 * @return bool
 */
function fl_zip_is_only_sql($path)
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $sql = 0;
    $other = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if ($n === false || substr($n, -1) === '/') {
            continue;
        }
        $base = basename($n);
        if ($base === '' || strpos($n, '__MACOSX') === 0 || $base === '.DS_Store') {
            continue;
        }
        if (preg_match('/\.sql$/i', $n)) {
            $sql++;
        } else {
            $other++;
        }
    }
    $zip->close();
    return $sql > 0 && $other === 0;
}

function fl_cmp_size_desc($a, $b)
{
    if ($a['size'] === $b['size']) {
        return strcmp($a['name'], $b['name']);
    }
    return ($a['size'] < $b['size']) ? 1 : -1;
}

function fl_human_bytes($n)
{
    $u = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return round($n, ($i === 0 ? 0 : 1)) . ' ' . $u[$i];
}

/* -------------------------------------------------------------- extraction */

/**
 * If every entry in the archive sits under one folder, that folder is
 * packaging, not content, and must be stripped or the site lands at
 * /mysite/index.php instead of /index.php.
 *
 * @param array $names
 * @return string the common first segment including its slash, or ''
 */
function fl_common_prefix($names)
{
    $first = null;
    foreach ($names as $n) {
        $n = ltrim(str_replace('\\', '/', $n), '/');
        if ($n === '' || strpos($n, '__MACOSX') === 0 || basename($n) === '.DS_Store') {
            continue;
        }
        $slash = strpos($n, '/');
        if ($slash === false) {
            return ''; // a file at the root, so there is no single wrapper
        }
        $seg = substr($n, 0, $slash + 1);
        if ($first === null) {
            $first = $seg;
        } elseif ($first !== $seg) {
            return '';
        }
    }
    return $first === null ? '' : $first;
}

/**
 * Reject entries that would escape the destination directory.
 *
 * @param string $name
 * @return bool
 */
function fl_entry_is_safe($name)
{
    $n = str_replace('\\', '/', $name);
    if ($n === '' || $n[0] === '/' || preg_match('#^[A-Za-z]:#', $n)) {
        return false;
    }
    foreach (explode('/', $n) as $seg) {
        if ($seg === '..') {
            return false;
        }
    }
    return true;
}

/**
 * Extract a .zip (or .tar.gz via the tar binary when available) into $dest.
 *
 * @param string $path
 * @param string $dest
 * @param array  $report
 * @return array report rows
 */
function fl_extract_archive($path, $dest, &$report)
{
    $lower = strtolower($path);

    if (preg_match('/\.zip$/', $lower)) {
        if (!class_exists('ZipArchive')) {
            $report['errors'][] = 'This server has no ZipArchive extension, so I cannot unpack '
                . basename($path) . '. Unpack it with the panel\'s own "Extract" option and re-run me.';
            return $report;
        }
        $zip = new ZipArchive();
        $rc = $zip->open($path);
        if ($rc !== true) {
            $report['errors'][] = 'Could not open ' . basename($path) . ' (ZipArchive code ' . $rc . ').';
            return $report;
        }
        $names = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $prefix = fl_common_prefix($names);
        if ($prefix !== '') {
            $report['notes'][] = 'The zip wraps everything in "' . rtrim($prefix, '/')
                . '", so I stripped that folder and put the site at the web root.';
        }
        $written = 0;
        $skipped = array();
        $extractedNames = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $names[$i];
            $rel = str_replace('\\', '/', $name);
            if ($prefix !== '' && strpos($rel, $prefix) === 0) {
                $rel = substr($rel, strlen($prefix));
            }
            $rel = ltrim($rel, '/');
            if ($rel === '' || strpos($rel, '__MACOSX') === 0 || basename($rel) === '.DS_Store') {
                continue;
            }
            if (!fl_entry_is_safe($rel)) {
                $skipped[] = $name;
                continue;
            }
            $target = $dest . '/' . $rel;
            if (substr($name, -1) === '/') {
                if (!is_dir($target) && !@mkdir($target, 0755, true)) {
                    $report['errors'][] = 'Could not create folder ' . $rel;
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                $report['errors'][] = 'Could not create folder ' . dirname($rel);
                continue;
            }
            $in = $zip->getStream($name);
            if (!$in) {
                $report['errors'][] = 'Could not read ' . $rel . ' out of the archive';
                continue;
            }
            $out = @fopen($target, 'wb');
            if (!$out) {
                fclose($in);
                $report['errors'][] = 'Could not write ' . $rel . ' (permission denied)';
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $written++;
            $extractedNames[] = $rel;
        }
        $zip->close();
        $report['extracted'] = $written;
        $report['extracted_names'] = $extractedNames;
        if ($skipped) {
            $report['warnings'][] = 'Skipped ' . count($skipped)
                . ' archive entries whose paths pointed outside the web root.';
        }
        $report['notes'][] = 'Unpacked ' . $written . ' files from ' . basename($path) . '.';
        return $report;
    }

    if (preg_match('/\.(tar\.gz|tgz|tar)$/', $lower)) {
        // No PharData on many shared hosts and no tar binary either, so try
        // both and say plainly if neither is there.
        if (class_exists('PharData')) {
            try {
                $tmp = $dest . '/.fl_tar_' . substr(md5($path), 0, 8);
                @mkdir($tmp, 0755, true);
                $p = new PharData($path);
                $p->extractTo($tmp, null, true);
                fl_move_tree_stripping_wrapper($tmp, $dest, $report);
                fl_rmtree($tmp);
                $report['notes'][] = 'Unpacked ' . basename($path) . ' with PharData.';
                return $report;
            } catch (Exception $e) {
                $report['warnings'][] = 'PharData could not unpack the tar: ' . $e->getMessage();
            }
        }
        $report['errors'][] = 'I cannot unpack ' . basename($path)
            . ' on this server. Please send the site as a .zip instead.';
        return $report;
    }

    $report['errors'][] = 'Unrecognised archive type: ' . basename($path);
    return $report;
}

/**
 * Move an extracted tree into place, stripping a single wrapper folder.
 */
function fl_move_tree_stripping_wrapper($from, $to, &$report)
{
    $entries = array_values(array_diff((array)@scandir($from), array('.', '..')));
    $src = $from;
    if (count($entries) === 1 && is_dir($from . '/' . $entries[0])) {
        $src = $from . '/' . $entries[0];
        $report['notes'][] = 'The archive wraps everything in "' . $entries[0]
            . '", so I stripped that folder.';
    }
    fl_copy_tree($src, $to, $report);
}

function fl_copy_tree($from, $to, &$report)
{
    $items = array_diff((array)@scandir($from), array('.', '..'));
    foreach ($items as $item) {
        $s = $from . '/' . $item;
        $d = $to . '/' . $item;
        if (is_dir($s)) {
            if (!is_dir($d)) {
                @mkdir($d, 0755, true);
            }
            fl_copy_tree($s, $d, $report);
        } else {
            if (!@copy($s, $d)) {
                $report['errors'][] = 'Could not write ' . $item;
            } else {
                $report['extracted'] = (isset($report['extracted']) ? $report['extracted'] : 0) + 1;
            }
        }
    }
}

function fl_rmtree($dir)
{
    if (!is_dir($dir)) {
        @unlink($dir);
        return;
    }
    foreach (array_diff((array)@scandir($dir), array('.', '..')) as $i) {
        fl_rmtree($dir . '/' . $i);
    }
    @rmdir($dir);
}

/**
 * Move the host's placeholder index.html out of the way.
 *
 * Shared hosting drops an `index.html` into the web root when the account is
 * created, and Apache/LiteSpeed almost always list `index.html` BEFORE
 * `index.php` in DirectoryIndex. So a perfectly installed PHP site still
 * shows the host's placeholder at the root, and nothing anywhere reports an
 * error: it is a 200, with the wrong page.
 *
 * Only ever touches an index.html that the archive did NOT bring, so a site
 * whose real home page is static is left alone.
 *
 * @param string $root
 * @param array  $report
 * @return array $report
 */
function fl_demote_placeholder_index($root, &$report)
{
    $html = $root . '/index.html';
    $php = $root . '/index.php';
    if (!is_file($html) || !is_file($php)) {
        return $report;
    }

    // If the archive shipped the index.html, it is the site's own page.
    $fromArchive = isset($report['extracted_names']) ? $report['extracted_names'] : array();
    foreach ($fromArchive as $n) {
        if (strtolower($n) === 'index.html') {
            $report['notes'][] = 'Both index.html and index.php are at the web root and BOTH came '
                . 'from your archive. I left them as they are, but the server will serve '
                . 'index.html first, so tell me if the home page should be the PHP one.';
            return $report;
        }
    }

    $to = $root . '/index.html.placeholder.bak';
    $i = 2;
    while (file_exists($to)) {
        $to = $root . '/index.html.placeholder' . $i . '.bak';
        $i++;
    }
    if (@rename($html, $to)) {
        $report['notes'][] = 'Your host had left a placeholder index.html in the web root. The '
            . 'server loads index.html before index.php, so it would have kept showing that '
            . 'instead of your site. I renamed it to ' . basename($to) . ' rather than deleting it.';
        $report['placeholder_demoted'] = basename($to);
    } else {
        $report['warnings'][] = 'There is a placeholder index.html in the web root and I could not '
            . 'rename it. The server loads index.html before index.php, so please delete or rename '
            . 'index.html in the file manager, otherwise the old placeholder keeps showing.';
    }
    return $report;
}

/* ---------------------------------------------------------------- database */

/**
 * From PHP 8.1 mysqli THROWS on error instead of returning false. An
 * exception escaping here would kill the installer with a blank page, because
 * a failing statement in a dump is normal and expected. Turn reporting off so
 * mysqli returns false again, and every call below is still wrapped in a
 * try/catch in case a host has forced the setting back on.
 */
if (function_exists('mysqli_report') && defined('MYSQLI_REPORT_OFF')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

/**
 * Connect with mysqli, falling back to PDO. Returns a tiny uniform wrapper so
 * the rest of the installer does not care which one is available.
 *
 * @param array $creds
 * @return array{ok:bool,error:string,kind:string,h:mixed}
 */
function fl_db_connect($creds)
{
    $host = $creds['host'];
    $port = null;
    $socket = null;
    if (fl_str_contains($host, ':')) {
        list($h, $tail) = explode(':', $host, 2);
        if (ctype_digit($tail)) {
            $host = $h;
            $port = (int)$tail;
        } else {
            $host = $h;
            $socket = $tail;
        }
    }

    if (function_exists('mysqli_connect')) {
        try {
            $h = @mysqli_init();
            if ($h) {
                @mysqli_options($h, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
                $ok = @mysqli_real_connect(
                    $h, $host, $creds['user'], $creds['pass'],
                    isset($creds['name']) ? $creds['name'] : null,
                    $port, $socket
                );
                if ($ok) {
                    @mysqli_set_charset($h, 'utf8mb4');
                    return array('ok' => true, 'error' => '', 'kind' => 'mysqli', 'h' => $h);
                }
                $err = mysqli_connect_error();
                if (!$err) {
                    $err = @mysqli_error($h);
                }
                return array('ok' => false, 'error' => $err ? $err : 'connection refused',
                             'kind' => 'mysqli', 'h' => null);
            }
        } catch (Exception $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'kind' => 'mysqli', 'h' => null);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'kind' => 'mysqli', 'h' => null);
        }
    }

    if (class_exists('PDO')) {
        $dsn = 'mysql:host=' . $host;
        if ($port) {
            $dsn .= ';port=' . $port;
        }
        if ($socket) {
            $dsn = 'mysql:unix_socket=' . $socket;
        }
        if (!empty($creds['name'])) {
            $dsn .= ';dbname=' . $creds['name'];
        }
        $dsn .= ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, $creds['user'], $creds['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
            ));
            return array('ok' => true, 'error' => '', 'kind' => 'pdo', 'h' => $pdo);
        } catch (Exception $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'kind' => 'pdo', 'h' => null);
        }
    }

    return array('ok' => false, 'error' => 'This server has neither mysqli nor PDO_MySQL enabled.',
                 'kind' => 'none', 'h' => null);
}

/**
 * Run one statement. Returns '' on success or the error text.
 */
function fl_db_exec($db, $sql)
{
    if ($db['kind'] === 'mysqli') {
        try {
            $ok = @mysqli_real_query($db['h'], $sql);
            // Drain any result so the connection stays usable for the next one.
            if ($ok) {
                do {
                    $res = @mysqli_store_result($db['h']);
                    if ($res) {
                        @mysqli_free_result($res);
                    }
                } while (@mysqli_more_results($db['h']) && @mysqli_next_result($db['h']));
            }
            return $ok ? '' : mysqli_error($db['h']);
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
    try {
        $db['h']->exec($sql);
        return '';
    } catch (Exception $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * Run a SELECT and return rows as arrays.
 */
function fl_db_rows($db, $sql)
{
    if ($db['kind'] === 'mysqli') {
        try {
            $res = @mysqli_query($db['h'], $sql);
            if (!$res) {
                return array();
            }
            $out = array();
            while ($r = mysqli_fetch_assoc($res)) {
                $out[] = $r;
            }
            mysqli_free_result($res);
            return $out;
        } catch (Exception $e) {
            return array();
        } catch (Throwable $e) {
            return array();
        }
    }
    try {
        $st = $db['h']->query($sql);
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        return array();
    } catch (Throwable $e) {
        return array();
    }
}

function fl_db_quote($db, $v)
{
    if ($db['kind'] === 'mysqli') {
        return "'" . mysqli_real_escape_string($db['h'], $v) . "'";
    }
    return $db['h']->quote($v);
}

/**
 * Read a dump off disk, transparently handling .gz and .sql.zip, and refuse
 * politely rather than dying on an out-of-memory if it is too big.
 *
 * @return array{0:string|null,1:string} [sql, error]
 */
function fl_read_dump($path)
{
    $lower = strtolower($path);
    $size = filesize($path);
    $limit = fl_memory_limit_bytes();
    if ($limit > 0 && $size > 0 && $size * 3 > $limit) {
        return array(null, 'The dump is ' . fl_human_bytes($size) . ' but PHP here is capped at '
            . fl_human_bytes($limit) . ' of memory, so reading it would crash mid-import. '
            . 'Import this one through the panel\'s database import instead, then re-run me and '
            . 'I will skip the import and still do the config and the checks.');
    }

    if (preg_match('/\.gz$/', $lower)) {
        if (!function_exists('gzopen')) {
            return array(null, 'This server has no zlib, so I cannot read a .gz dump. Send it uncompressed.');
        }
        $fh = gzopen($path, 'rb');
        if (!$fh) {
            return array(null, 'Could not open ' . basename($path));
        }
        $sql = '';
        while (!gzeof($fh)) {
            $sql .= gzread($fh, 262144);
        }
        gzclose($fh);
        return array($sql, '');
    }

    if (preg_match('/\.zip$/', $lower)) {
        if (!class_exists('ZipArchive')) {
            return array(null, 'No ZipArchive here, so I cannot read a zipped dump. Send the plain .sql.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return array(null, 'Could not open ' . basename($path));
        }
        $sql = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('/\.sql$/i', $n)) {
                $sql = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();
        if ($sql === null) {
            return array(null, 'No .sql file inside ' . basename($path));
        }
        return array($sql, '');
    }

    $sql = @file_get_contents($path);
    if ($sql === false) {
        return array(null, 'Could not read ' . basename($path));
    }
    return array($sql, '');
}

function fl_memory_limit_bytes()
{
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $num = (float)$v;
    if ($unit === 'g') {
        return (int)($num * 1073741824);
    }
    if ($unit === 'm') {
        return (int)($num * 1048576);
    }
    if ($unit === 'k') {
        return (int)($num * 1024);
    }
    return (int)$num;
}

/**
 * Import a dump statement by statement, repairing what can be repaired.
 *
 * @param array  $db
 * @param string $sql
 * @param array  $report
 * @return array $report
 */
function fl_import_dump($db, $sql, &$report)
{
    $statements = fl_split_sql($sql);
    $report['statements'] = count($statements);
    $ran = 0;
    $repaired = array();
    $failed = array();
    $blocked = array();
    $skipped = 0;

    fl_db_exec($db, 'SET FOREIGN_KEY_CHECKS=0');
    fl_db_exec($db, "SET SESSION sql_mode=''");

    foreach ($statements as $stmt) {
        // A dump that carries its own CREATE DATABASE / USE would move us off
        // the database the host actually gave us.
        if (preg_match('/^\s*(CREATE\s+(DATABASE|SCHEMA)|USE\s)/i', $stmt)) {
            $skipped++;
            continue;
        }
        $err = fl_db_exec($db, $stmt);
        if ($err === '') {
            $ran++;
            continue;
        }
        $fix = fl_remediate_sql($stmt, $err);
        if ($fix !== null) {
            $err2 = fl_db_exec($db, $fix[0]);
            if ($err2 === '') {
                $ran++;
                $repaired[] = array('why' => $fix[1], 'stmt' => fl_stmt_label($stmt), 'error' => $err);
                continue;
            }
            $err = $err2;
        }
        if (fl_sql_error_is_harmless($stmt, $err)) {
            $skipped++;
            continue;
        }
        // A privilege the host withholds is not a broken install, so keep it
        // out of the failure count and say what to ask for instead.
        $hostNote = fl_sql_blocked_by_host($stmt, $err);
        if ($hostNote !== null) {
            $blocked[] = array('stmt' => fl_stmt_label($stmt), 'error' => $err, 'note' => $hostNote);
            continue;
        }
        $failed[] = array('stmt' => fl_stmt_label($stmt), 'error' => $err);
    }

    fl_db_exec($db, 'SET FOREIGN_KEY_CHECKS=1');

    $report['sql_ran'] = $ran;
    $report['sql_skipped'] = $skipped;
    $report['sql_repaired'] = $repaired;
    $report['sql_failed'] = $failed;
    $report['sql_host_blocked'] = $blocked;
    foreach ($blocked as $b) {
        $report['warnings'][] = $b['note'];
    }
    return $report;
}

/**
 * A short, readable label for a statement, for the report.
 */
function fl_stmt_label($stmt)
{
    $s = preg_replace('/\s+/', ' ', trim($stmt));
    if (strlen($s) > 140) {
        $s = substr($s, 0, 137) . '...';
    }
    return $s;
}

/* ------------------------------------------------------------ config files */

/**
 * Walk the site, rewrite DB credentials, and collect dev-machine leftovers.
 *
 * @return array $report
 */
function fl_rewire_configs($root, $creds, &$report)
{
    $changedFiles = array();
    $leftovers = array();
    $scanned = 0;

    $skipDirs = array('.git', 'node_modules', 'vendor', '.svn', 'cache', 'tmp');
    $stack = array($root);
    while ($stack) {
        $dir = array_pop($stack);
        $items = @scandir($dir);
        if ($items === false) {
            continue;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                if (in_array(strtolower($item), $skipDirs, true)) {
                    continue;
                }
                $stack[] = $full;
                continue;
            }
            // Never read or rewrite the installer's own files: it would edit
            // itself, leave .bak copies of itself, and report its own
            // display_errors line as the site's problem.
            if (in_array($item, array('install.php', 'lib_sql.php', 'lib_config.php',
                                      'lib_core.php', 'install-key.txt'), true)) {
                continue;
            }
            if (substr($item, -16) === '.pre-install.bak') {
                continue;
            }
            if (!preg_match('/\.(php|phtml|inc|ini|env)$/i', $item) && strtolower($item) !== '.env') {
                continue;
            }
            if (filesize($full) > 2097152) {
                continue; // a 2MB PHP file is data, not config
            }
            $src = @file_get_contents($full);
            if ($src === false) {
                continue;
            }
            $scanned++;
            $rel = ltrim(substr($full, strlen($root)), '/');

            list($new, $changes) = fl_rewrite_db_config($src, $creds);
            if ($changes) {
                if (!@copy($full, $full . '.pre-install.bak')) {
                    $report['warnings'][] = 'Could not back up ' . $rel . ' before editing it.';
                }
                if (@file_put_contents($full, $new) === false) {
                    $report['errors'][] = 'Could not write ' . $rel . ' (permission denied).';
                } else {
                    $changedFiles[] = array('file' => $rel, 'changes' => $changes);
                }
            }

            foreach (fl_scan_dev_leftovers($rel, $src) as $hit) {
                $leftovers[] = $hit;
            }
        }
    }

    $report['config_scanned'] = $scanned;
    $report['config_changed'] = $changedFiles;
    $report['leftovers'] = $leftovers;
    return $report;
}

/* -------------------------------------------------------------- self check */

/**
 * Everything that has to be true for the client's two acceptance criteria to
 * hold: the public site loads clean, and the dashboard reads and writes.
 */
function fl_selfcheck($root, $creds, $baseUrl, &$report)
{
    $checks = array();

    $checks[] = array('PHP version', PHP_VERSION,
        version_compare(PHP_VERSION, '5.6', '>=') ? 'pass' : 'fail');

    foreach (array('mysqli', 'pdo_mysql', 'mbstring', 'json', 'zip', 'session') as $ext) {
        $has = extension_loaded($ext);
        $needed = in_array($ext, array('json', 'session'), true);
        $required = $needed || ($ext === 'mysqli' && !extension_loaded('pdo_mysql'));
        $checks[] = array('PHP extension ' . $ext, $has ? 'loaded' : 'missing',
            $has ? 'pass' : ($required ? 'fail' : 'warn'));
    }

    $db = fl_db_connect($creds);
    $checks[] = array('Database connect', $db['ok'] ? 'connected to ' . $creds['name'] : $db['error'],
        $db['ok'] ? 'pass' : 'fail');

    $tables = array();
    if ($db['ok']) {
        foreach (fl_db_rows($db, 'SHOW TABLES') as $row) {
            $tables[] = reset($row);
        }
        $checks[] = array('Tables present', count($tables) . ' table'
            . (count($tables) === 1 ? '' : 's'), count($tables) > 0 ? 'pass' : 'fail');

        $counts = array();
        $empty = array();
        foreach ($tables as $t) {
            $r = fl_db_rows($db, 'SELECT COUNT(*) AS c FROM `' . str_replace('`', '', $t) . '`');
            $c = $r ? (int)$r[0]['c'] : -1;
            $counts[$t] = $c;
            if ($c === 0) {
                $empty[] = $t;
            }
        }
        $report['table_counts'] = $counts;
        if ($empty) {
            $checks[] = array('Empty tables', implode(', ', array_slice($empty, 0, 8))
                . (count($empty) > 8 ? ' and ' . (count($empty) - 8) . ' more' : ''),
                count($empty) === count($tables) ? 'fail' : 'warn');
        }

        // Prove writes work, which is half of his acceptance criteria, then
        // remove only what this check created.
        $probe = 'fl_write_probe_' . substr(md5(__FILE__ . count($tables)), 0, 8);
        $e1 = fl_db_exec($db, 'CREATE TABLE `' . $probe . '` (id INT PRIMARY KEY, v VARCHAR(16))');
        if ($e1 === '') {
            $e2 = fl_db_exec($db, 'INSERT INTO `' . $probe . '` (id, v) VALUES (1, \'ok\')');
            $rows = fl_db_rows($db, 'SELECT v FROM `' . $probe . '` WHERE id = 1');
            $readBack = ($rows && isset($rows[0]['v']) && $rows[0]['v'] === 'ok');
            fl_db_exec($db, 'DROP TABLE `' . $probe . '`');
            $checks[] = array('Database write and read back',
                $readBack ? 'insert and select both worked' : ('insert failed: ' . $e2),
                $readBack ? 'pass' : 'fail');
        } else {
            $checks[] = array('Database write and read back',
                'the db user cannot create a table: ' . $e1, 'fail');
        }

        $report['weak_logins'] = fl_scan_weak_logins($db, $tables);
    }

    // Front door and dashboard over real HTTP.
    //
    // Careful: this asks the server to serve a page while the server is busy
    // serving THIS page. On hosting with a single PHP worker that deadlocks
    // until the timeout, so the timeout is short and a timeout is reported as
    // "check it yourself" rather than as a broken site. Never let a limit of
    // the test look like a fault in the site.
    $adminPath = fl_guess_admin_path($root);
    $report['admin_path'] = $adminPath;

    if ($baseUrl) {
        $base = rtrim($baseUrl, '/');
        $home = fl_http_get($base . '/', 8);
        if (fl_http_looks_like_deadlock($home)) {
            $urls = $base . '/';
            if ($adminPath) {
                $urls .= ' and ' . $base . '/' . ltrim($adminPath, '/');
            }
            $checks[] = array('Page checks over HTTP',
                'this server could not fetch its own pages while it was busy running me, '
                . 'which is normal on smaller hosting. Open ' . $urls . ' yourself: '
                . 'everything else below was still checked directly.', 'warn');
            $report['manual_urls'] = array_filter(array(
                $base . '/',
                $adminPath ? $base . '/' . ltrim($adminPath, '/') : null,
            ));
        } else {
            $checks[] = fl_page_check('Public site at /', $home);
            if ($adminPath) {
                $adm = fl_http_get($base . '/' . ltrim($adminPath, '/'), 8);
                if (fl_http_looks_like_deadlock($adm)) {
                    $checks[] = array('Dashboard at /' . ltrim($adminPath, '/'),
                        'could not be fetched from the server itself, open it yourself', 'warn');
                } else {
                    $checks[] = fl_page_check('Dashboard at /' . ltrim($adminPath, '/'), $adm);
                }
            } else {
                $checks[] = array('Dashboard', 'could not find an admin folder to test', 'warn');
            }
        }
    } else {
        $checks[] = array('HTTP checks', 'skipped, no base URL known', 'warn');
    }

    // Writable folders, for uploads and caches.
    $writeTargets = array();
    foreach (array('uploads', 'upload', 'images/uploads', 'assets/uploads', 'cache', 'tmp', 'logs') as $d) {
        if (is_dir($root . '/' . $d)) {
            $writeTargets[] = $d;
        }
    }
    foreach ($writeTargets as $d) {
        $probe = $root . '/' . $d . '/.fl_write_probe';
        $ok = @file_put_contents($probe, 'x') !== false;
        if ($ok) {
            @unlink($probe);
        }
        $checks[] = array('Writable: ' . $d, $ok ? 'yes' : 'no, uploads here will fail',
            $ok ? 'pass' : 'warn');
    }

    // An index at the root, or the root 403s.
    $hasIndex = false;
    foreach (array('index.php', 'index.html', 'index.htm') as $f) {
        if (is_file($root . '/' . $f)) {
            $hasIndex = true;
            break;
        }
    }
    $checks[] = array('Index file at the web root', $hasIndex ? 'present'
        : 'missing, the root will 403 or show a file listing', $hasIndex ? 'pass' : 'fail');

    // index.html winning over index.php is a 200 showing the wrong page, so
    // it has to be a check in its own right, not a footnote.
    if (is_file($root . '/index.html') && is_file($root . '/index.php')) {
        $checks[] = array('Only one index at the web root',
            'both index.html and index.php are here, and the server serves index.html first',
            'warn');
    } elseif (isset($report['placeholder_demoted'])) {
        $checks[] = array('Only one index at the web root',
            'the host placeholder was renamed to ' . $report['placeholder_demoted']
            . ', so index.php is the home page', 'pass');
    }

    $report['checks'] = $checks;
    return $report;
}

/**
 * Did the loopback request fail because the server cannot answer itself?
 *
 * A single-worker host is busy with the installer, so its own request to
 * itself never gets a turn and dies on the timeout. That says nothing about
 * whether the site works.
 *
 * @param array $res
 * @return bool
 */
function fl_http_looks_like_deadlock($res)
{
    if ($res['ok']) {
        return false;
    }
    $e = strtolower($res['error']);
    foreach (array('timed out', 'timeout', 'operation too slow', 'request blocked',
                   'empty reply', 'recv failure', 'connection reset') as $needle) {
        if (strpos($e, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Turn an HTTP result into a pass/warn/fail row. A 200 is not enough: PHP
 * prints fatals inside a 200 body.
 */
function fl_page_check($label, $res)
{
    if (!$res['ok']) {
        return array($label, 'request failed: ' . $res['error'], 'fail');
    }
    $body = $res['body'];
    $bad = array('Fatal error', 'Parse error', 'Uncaught Exception', 'Uncaught Error',
                 'Access denied for user', "Can't connect to", 'Unknown database',
                 'Table \'', 'SQLSTATE[', 'mysqli_');
    foreach ($bad as $needle) {
        if (strpos($body, $needle) !== false) {
            $line = fl_excerpt_around($body, $needle);
            return array($label, 'HTTP ' . $res['status'] . ' but the page prints an error: ' . $line, 'fail');
        }
    }
    if ($res['status'] >= 500) {
        return array($label, 'HTTP ' . $res['status'], 'fail');
    }
    if ($res['status'] >= 400) {
        return array($label, 'HTTP ' . $res['status'], 'fail');
    }
    $warn = array('Warning:', 'Notice:', 'Deprecated:', 'Undefined variable', 'Undefined index');
    foreach ($warn as $needle) {
        if (strpos($body, $needle) !== false) {
            return array($label, 'HTTP ' . $res['status'] . ', loads but prints "'
                . $needle . '" to visitors: ' . fl_excerpt_around($body, $needle), 'warn');
        }
    }
    if (strlen(trim(strip_tags($body))) < 20) {
        return array($label, 'HTTP ' . $res['status'] . ' but the page is blank', 'fail');
    }
    return array($label, 'HTTP ' . $res['status'] . ', ' . fl_human_bytes(strlen($body))
        . ', no errors in the output', 'pass');
}

function fl_excerpt_around($body, $needle)
{
    $pos = strpos($body, $needle);
    $start = max(0, $pos - 20);
    $txt = substr($body, $start, 220);
    $txt = preg_replace('/\s+/', ' ', strip_tags($txt));
    return trim($txt);
}

/**
 * Where is the dashboard? Look for a folder with a login form in it.
 */
function fl_guess_admin_path($root)
{
    $candidates = array('admin', 'administrator', 'dashboard', 'adminpanel', 'admin_panel',
                        'panel', 'backend', 'cp', 'manage', 'adm');
    foreach ($candidates as $c) {
        if (is_dir($root . '/' . $c)) {
            foreach (array('index.php', 'login.php', 'index.html') as $f) {
                if (is_file($root . '/' . $c . '/' . $f)) {
                    return $c . '/' . ($f === 'index.php' || $f === 'index.html' ? '' : $f);
                }
            }
            return $c . '/';
        }
    }
    foreach (array('login.php', 'admin.php', 'dashboard.php') as $f) {
        if (is_file($root . '/' . $f)) {
            return $f;
        }
    }
    return null;
}

/**
 * Credentials carried over from a dev machine are usually admin/admin. Report
 * them; never silently change a login the client may be relying on.
 */
function fl_scan_weak_logins($db, $tables)
{
    $weak = array('admin', 'password', '123456', 'admin123', 'test', 'pass', '1234',
                  'secret', 'changeme', 'demo', 'root', 'qwerty', 'letmein', 'password123');
    $hashes = array();
    foreach ($weak as $w) {
        $hashes[md5($w)] = $w;
        $hashes[sha1($w)] = $w;
    }
    $found = array();

    foreach ($tables as $t) {
        if (!preg_match('/user|admin|member|account|login|staff/i', $t)) {
            continue;
        }
        $cols = fl_db_rows($db, 'SHOW COLUMNS FROM `' . str_replace('`', '', $t) . '`');
        $passCol = null;
        $userCol = null;
        foreach ($cols as $c) {
            $f = isset($c['Field']) ? $c['Field'] : (isset($c['field']) ? $c['field'] : '');
            if ($passCol === null && preg_match('/^(password|passwd|pass|pwd|user_pass|hash)$/i', $f)) {
                $passCol = $f;
            }
            if ($userCol === null && preg_match('/^(username|user_name|user|login|email|name)$/i', $f)) {
                $userCol = $f;
            }
        }
        if ($passCol === null) {
            continue;
        }
        $sel = '`' . $passCol . '` AS p' . ($userCol ? ', `' . $userCol . '` AS u' : '');
        $rows = fl_db_rows($db, 'SELECT ' . $sel . ' FROM `' . str_replace('`', '', $t) . '` LIMIT 50');
        foreach ($rows as $r) {
            $p = (string)$r['p'];
            $u = isset($r['u']) ? (string)$r['u'] : '(unknown)';
            $lower = strtolower($p);
            if (isset($hashes[$lower])) {
                $found[] = array('table' => $t, 'user' => $u,
                    'why' => 'password hash matches "' . $hashes[$lower] . '"');
            } elseif (in_array($lower, $weak, true)) {
                $found[] = array('table' => $t, 'user' => $u,
                    'why' => 'password is stored in plain text as "' . $p . '"');
            } elseif ($p !== '' && strlen($p) < 20 && !preg_match('/^\$2[aby]\$/', $p)) {
                $found[] = array('table' => $t, 'user' => $u,
                    'why' => 'password looks stored in plain text, not hashed');
            }
        }
    }
    return $found;
}

/* ------------------------------------------------------------------- http */

/**
 * GET a URL with whatever this host allows.
 */
function fl_http_get($url, $timeout = 20)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'post-install-self-check',
        ));
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return array('ok' => false, 'error' => $err ? $err : 'curl failed',
                         'status' => $status, 'body' => '');
        }
        return array('ok' => true, 'error' => '', 'status' => $status, 'body' => $body);
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array(
            'http' => array('timeout' => $timeout, 'ignore_errors' => true,
                            'user_agent' => 'post-install-self-check'),
            'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
        ));
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if ($body === false) {
            return array('ok' => false, 'error' => 'request blocked', 'status' => $status, 'body' => '');
        }
        return array('ok' => true, 'error' => '', 'status' => $status ? $status : 200, 'body' => $body);
    }

    return array('ok' => false, 'error' => 'no curl and allow_url_fopen is off',
                 'status' => 0, 'body' => '');
}

/**
 * The site's own base URL, as seen from the request that is running us.
 */
function fl_guess_base_url()
{
    if (!isset($_SERVER['HTTP_HOST'])) {
        return null;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $dir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $dir;
}
