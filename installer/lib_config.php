<?php
/**
 * Finds the database credentials inside a hand written PHP site and rewrites
 * them, plus flags the dev-machine leftovers that break a migrated site.
 *
 * Targets PHP 7.0+.
 */

if (!function_exists('fl_str_contains')) {
    function fl_str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/**
 * Identifiers that unambiguously hold a database credential. Exact matches
 * only. Deliberately conservative: a false positive here silently overwrites
 * real site content, which is far worse than missing one credential that I
 * can then wire by hand.
 */
function fl_cred_name_map()
{
    return array(
        'host' => array('db_host', 'dbhost', 'database_host', 'db_server', 'dbserver',
                        'mysql_host', 'mysqli_host', 'sql_host', 'pdo_host',
                        'servername', 'server_name', 'hostname', 'db_hostname'),
        'name' => array('db_name', 'dbname', 'database_name', 'db_database', 'dbdatabase',
                        'mysql_database', 'mysql_db', 'mysql_name', 'sql_db', 'sql_database',
                        'db_schema', 'dbschema', 'pdo_dbname'),
        'user' => array('db_user', 'dbuser', 'db_username', 'dbusername', 'database_user',
                        'database_username', 'mysql_user', 'mysql_username', 'sql_user',
                        'pdo_user', 'db_uname', 'username'),
        'pass' => array('db_pass', 'dbpass', 'db_password', 'dbpassword', 'database_password',
                        'database_pass', 'mysql_pass', 'mysql_password', 'sql_pass',
                        'sql_password', 'pdo_pass', 'db_pwd', 'dbpwd', 'password', 'passwd'),
    );
}

/**
 * Tokens that mark an identifier as being about the database at all.
 */
function fl_db_tokens()
{
    return array('db', 'database', 'mysql', 'mysqli', 'sql', 'pdo');
}

/**
 * Role tokens, by credential kind, for the compound rule.
 */
function fl_role_tokens()
{
    return array(
        'host' => array('host', 'server', 'hostname'),
        'name' => array('name', 'database', 'schema', 'base', 'db'),
        'user' => array('user', 'username', 'uname', 'login'),
        'pass' => array('pass', 'password', 'passwd', 'pwd'),
    );
}

/**
 * Which credential does this identifier hold?
 *
 * An identifier qualifies only if it is in the exact list above, or if it
 * contains BOTH a database token and a role token. That second rule catches
 * things like "my_db_hostname" while refusing "site_name", which is what
 * made an earlier version overwrite a site's own title with the db name.
 *
 * @param string $ident
 * @return string|null one of host|name|user|pass
 */
function fl_classify_credential($ident)
{
    $ident = strtolower(trim($ident));
    if ($ident === '') {
        return null;
    }

    foreach (fl_cred_name_map() as $kind => $names) {
        if (in_array($ident, $names, true)) {
            return $kind;
        }
    }

    // Compound rule: needs a db token AND a role token, as separate words.
    $parts = preg_split('/[^a-z0-9]+/', $ident);
    $hasDbToken = false;
    foreach (fl_db_tokens() as $t) {
        if (in_array($t, $parts, true)) {
            $hasDbToken = true;
            break;
        }
    }
    if (!$hasDbToken) {
        return null;
    }
    $best = null;
    $bestLen = 0;
    foreach (fl_role_tokens() as $kind => $roles) {
        foreach ($roles as $r) {
            if (in_array($r, $parts, true) && strlen($r) > $bestLen) {
                // "db" counts as the db token, not as the name role, unless
                // it is the only thing left.
                if ($r === 'db' && count(array_diff($parts, array('db', ''))) > 0) {
                    continue;
                }
                $best = $kind;
                $bestLen = strlen($r);
            }
        }
    }
    return $best;
}

/**
 * Rewrite the DB credentials in one PHP source string.
 *
 * Covers the four shapes a hand written site actually uses:
 *   define('DB_HOST', 'localhost');
 *   $db_host = 'localhost';
 *   mysqli_connect('localhost', 'user', 'pass', 'dbname');
 *   new mysqli(...) / new PDO('mysql:host=...;dbname=...', 'user', 'pass')
 *
 * @param string $src
 * @param array  $creds host|name|user|pass
 * @return array{0:string,1:array} [new source, list of change descriptions]
 */
function fl_rewrite_db_config($src, $creds)
{
    $changes = array();
    $out = $src;

    $quote = function ($v) {
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $v) . "'";
    };

    // 1. define('DB_HOST', 'value')
    $out = preg_replace_callback(
        '/\bdefine\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*,\s*([\'"])(.*?)\3\s*\)/s',
        function ($m) use ($creds, $quote, &$changes) {
            $kind = fl_classify_credential($m[2]);
            if ($kind === null || !array_key_exists($kind, $creds)) {
                return $m[0];
            }
            if ($m[4] === $creds[$kind]) {
                return $m[0];
            }
            $changes[] = "define('" . $m[2] . "') " . fl_mask($kind, $m[4]) . ' -> ' . fl_mask($kind, $creds[$kind]);
            return "define('" . $m[2] . "', " . $quote($creds[$kind]) . ')';
        },
        $out
    );

    // 2. $db_host = 'value';   and   const DB_HOST = 'value';
    $out = preg_replace_callback(
        '/(\$|\bconst\s+)([A-Za-z_][A-Za-z0-9_]*)(\s*=\s*)([\'"])(.*?)\4/s',
        function ($m) use ($creds, $quote, &$changes) {
            $kind = fl_classify_credential($m[2]);
            if ($kind === null || !array_key_exists($kind, $creds)) {
                return $m[0];
            }
            if ($m[5] === $creds[$kind]) {
                return $m[0];
            }
            $label = ($m[1] === '$' ? '$' : 'const ') . $m[2];
            $changes[] = $label . ' ' . fl_mask($kind, $m[5]) . ' -> ' . fl_mask($kind, $creds[$kind]);
            return $m[1] . $m[2] . $m[3] . $quote($creds[$kind]);
        },
        $out
    );

    // 3. mysqli_connect / new mysqli / mysql_connect with inline literals.
    $out = preg_replace_callback(
        '/\b(mysqli_connect|mysql_connect|new\s+mysqli)\s*\(([^()]*)\)/i',
        function ($m) use ($creds, $quote, &$changes) {
            $args = fl_split_php_args($m[2]);
            if (count($args) < 3) {
                return $m[0];
            }
            // Positional order for all three: host, user, pass, [db]
            $order = array('host', 'user', 'pass', 'name');
            $touched = false;
            foreach ($order as $idx => $kind) {
                if (!isset($args[$idx]) || !array_key_exists($kind, $creds)) {
                    continue;
                }
                $lit = fl_php_string_literal($args[$idx]);
                if ($lit === null) {
                    continue; // a variable, leave it: rule 1 or 2 already fixed it
                }
                if ($lit === $creds[$kind]) {
                    continue;
                }
                $changes[] = trim($m[1]) . '() arg ' . ($idx + 1) . ' (' . $kind . ') '
                    . fl_mask($kind, $lit) . ' -> ' . fl_mask($kind, $creds[$kind]);
                $args[$idx] = $quote($creds[$kind]);
                $touched = true;
            }
            if (!$touched) {
                return $m[0];
            }
            return $m[1] . '(' . implode(', ', array_map('trim', $args)) . ')';
        },
        $out
    );

    // 4. PDO DSN: 'mysql:host=...;dbname=...'
    $out = preg_replace_callback(
        '/([\'"])mysql:([^\'"]*)\1/i',
        function ($m) use ($creds, &$changes) {
            $dsn = $m[2];
            $new = $dsn;
            if (array_key_exists('host', $creds)) {
                $new = preg_replace('/\bhost\s*=\s*[^;]*/i', 'host=' . $creds['host'], $new);
            }
            if (array_key_exists('name', $creds)) {
                $new = preg_replace('/\bdbname\s*=\s*[^;]*/i', 'dbname=' . $creds['name'], $new);
            }
            if ($new === $dsn) {
                return $m[0];
            }
            $changes[] = 'PDO DSN mysql:' . $dsn . ' -> mysql:' . $new;
            return $m[1] . 'mysql:' . $new . $m[1];
        },
        $out
    );

    return array($out, $changes);
}

/**
 * Hide the password while still proving which value changed.
 */
function fl_mask($kind, $value)
{
    if ($kind !== 'pass') {
        return "'" . $value . "'";
    }
    $len = strlen($value);
    if ($len === 0) {
        return "'' (empty)";
    }
    return "'" . substr($value, 0, 1) . str_repeat('*', max(1, $len - 1)) . "' (" . $len . ' chars)';
}

/**
 * Split a PHP argument list on top level commas only.
 *
 * @param string $s
 * @return array
 */
function fl_split_php_args($s)
{
    $args = array();
    $depth = 0;
    $cur = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'" || $c === '"') {
            $q = $c;
            $cur .= $c;
            $i++;
            while ($i < $n) {
                if ($s[$i] === '\\') {
                    $cur .= substr($s, $i, 2);
                    $i += 2;
                    continue;
                }
                $cur .= $s[$i];
                if ($s[$i] === $q) {
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        }
        if ($c === ',' && $depth === 0) {
            $args[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '' || count($args) > 0) {
        $args[] = $cur;
    }
    return $args;
}

/**
 * If the argument is a plain quoted string, return its value, else null.
 *
 * @param string $arg
 * @return string|null
 */
function fl_php_string_literal($arg)
{
    $arg = trim($arg);
    if (strlen($arg) < 2) {
        return null;
    }
    $q = $arg[0];
    if (($q !== "'" && $q !== '"') || substr($arg, -1) !== $q) {
        return null;
    }
    $inner = substr($arg, 1, -1);
    if ($q === "'" && fl_str_contains($inner, $q)) {
        return null;
    }
    // A double quoted literal with interpolation is not a constant.
    if ($q === '"' && preg_match('/[$]{|[$][A-Za-z_]/', $inner)) {
        return null;
    }
    return stripcslashes($inner);
}

/**
 * Leftovers from the dev machine that silently break a migrated site.
 *
 * @param string $relpath
 * @param string $src
 * @return array list of ['line'=>int,'kind'=>string,'text'=>string,'note'=>string]
 */
function fl_scan_dev_leftovers($relpath, $src)
{
    $hits = array();
    $patterns = array(
        // The slash class is one-or-more because in PHP source a Windows path
        // is written 'C:\\xampp\\...', so the real text holds doubled
        // backslashes and a single-slash pattern silently finds nothing.
        array('/\b[A-Za-z]:[\\\\\/]+(?:xampp|wamp|wamp64|laragon|mamp|users|inetpub|htdocs)[\\\\\/]+[^\s\'"<>()]*/i',
              'windows-path', 'a Windows dev path that does not exist on the server'),
        array('/\/(?:var\/www|home\/[A-Za-z0-9_.-]+\/public_html|opt\/lampp|Applications\/MAMP)\/[^\s\'"<>()]*/',
              'absolute-path', 'an absolute filesystem path from the dev machine'),
        array('/https?:\/\/(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])(?::\d+)?[^\s\'"<>()]*/i',
              'localhost-url', 'a localhost URL, so this will point at the visitor\'s own machine'),
        array('/https?:\/\/[A-Za-z0-9.-]+\.(?:local|test|localhost|dev|loc)(?::\d+)?[^\s\'"<>()]*/i',
              'dev-hostname', 'a dev-only hostname'),
        array('/\b(?:ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"]?(?:1|on|true)|error_reporting\s*\(\s*E_ALL\s*\))/i',
              'errors-visible', 'PHP errors are printed to visitors, which leaks paths and queries'),
    );
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        if (strlen($line) > 4000) {
            $line = substr($line, 0, 4000);
        }
        foreach ($patterns as $p) {
            if (preg_match($p[0], $line, $m)) {
                $hits[] = array(
                    'file' => $relpath,
                    'line' => $i + 1,
                    'kind' => $p[1],
                    'text' => trim($m[0]),
                    'note' => $p[2],
                );
            }
        }
    }
    return $hits;
}
