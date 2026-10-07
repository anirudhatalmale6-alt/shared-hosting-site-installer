<?php
/**
 * SQL dump splitter + remediation helpers.
 *
 * Kept in its own file so it can be unit tested from the CLI. The shipped
 * install.php is built by concatenating this into a single drag-and-drop file.
 *
 * Targets PHP 7.0+ (shared hosting may be behind), so no PHP 8 only syntax.
 */

if (!function_exists('fl_str_contains')) {
    function fl_str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/**
 * Split a mysqldump into executable statements.
 *
 * Handles, because every one of these appears in a real dump:
 *   - single quoted, double quoted and backtick quoted spans
 *   - backslash escapes and doubled quotes inside strings
 *   - "--" and "#" line comments, but only outside strings
 *   - /* *\/ block comments, while KEEPING /*! executable comments
 *   - DELIMITER changes, so triggers and procedures survive
 *   - a UTF-8 BOM at the head of the file
 *
 * @param string $sql
 * @return array list of statements, trimmed, no trailing delimiter
 */
function fl_split_sql($sql)
{
    // A BOM in front of the first statement makes MySQL reject it.
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3);
    }
    $sql = str_replace("\r\n", "\n", $sql);

    $out = array();
    $delim = ';';
    $buf = '';
    $i = 0;
    $n = strlen($sql);

    while ($i < $n) {
        $c = $sql[$i];

        // DELIMITER only counts at the start of a statement, on its own line.
        if (($c === 'd' || $c === 'D') && trim($buf) === ''
            && ($i === 0 || $sql[$i - 1] === "\n")
            && preg_match('/^DELIMITER[ \t]+(\S+)[ \t]*(\n|$)/i', substr($sql, $i, 64), $m)) {
            $delim = $m[1];
            $i += strlen($m[0]);
            $buf = '';
            continue;
        }

        // Line comments, outside any quoted span.
        if ($c === '#' || ($c === '-' && substr($sql, $i, 2) === '--'
                && (!isset($sql[$i + 2]) || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n"))) {
            $eol = strpos($sql, "\n", $i);
            $i = ($eol === false) ? $n : $eol + 1;
            continue;
        }

        // Block comments. /*! ... */ is executable, so it stays in the buffer.
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            if (isset($sql[$i + 2]) && $sql[$i + 2] === '!') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    $buf .= substr($sql, $i);
                    $i = $n;
                } else {
                    $buf .= substr($sql, $i, $end + 2 - $i);
                    $i = $end + 2;
                }
            } else {
                $end = strpos($sql, '*/', $i + 2);
                $i = ($end === false) ? $n : $end + 2;
            }
            continue;
        }

        // Quoted spans: copy verbatim, respecting escapes and doubling.
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            $i++;
            while ($i < $n) {
                $ch = $sql[$i];
                if ($ch === '\\' && $quote !== '`') {
                    // Backslash escapes do not apply inside backticks.
                    $buf .= substr($sql, $i, 2);
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    if (isset($sql[$i + 1]) && $sql[$i + 1] === $quote) {
                        $buf .= $quote . $quote;
                        $i += 2;
                        continue;
                    }
                    $buf .= $quote;
                    $i++;
                    break;
                }
                $buf .= $ch;
                $i++;
            }
            continue;
        }

        // End of statement.
        if ($c === $delim[0] && substr($sql, $i, strlen($delim)) === $delim) {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            $i += strlen($delim);
            continue;
        }

        $buf .= $c;
        $i++;
    }

    $stmt = trim($buf);
    if ($stmt !== '') {
        $out[] = $stmt;
    }
    return $out;
}

/**
 * Try to rewrite a statement that the target server rejected.
 *
 * Every rule here comes from a failure mode that actually bites when a dump
 * taken on a dev machine meets shared hosting: a newer collation, a DEFINER
 * naming a user that does not exist here, a sql_mode flag the server dropped.
 *
 * @param string $stmt  the statement that failed
 * @param string $error the server error text
 * @return array{0:string,1:string}|null [rewritten statement, human reason] or null
 */
function fl_remediate_sql($stmt, $error)
{
    $e = strtolower($error);
    $new = $stmt;
    $why = null;

    // MySQL 8 default collation is unknown to MariaDB and MySQL 5.7.
    if (fl_str_contains($e, 'unknown collation') || fl_str_contains($e, 'unknown character set')
        || fl_str_contains($e, 'invalid default collation') || fl_str_contains($e, "collation 'utf8mb4_0900")) {
        $map = array(
            '/utf8mb4_0900_as_cs/i'       => 'utf8mb4_bin',
            '/utf8mb4_0900_ai_ci/i'       => 'utf8mb4_unicode_ci',
            '/utf8mb4_0900_[a-z_]+/i'     => 'utf8mb4_unicode_ci',
            '/utf8mb4_unicode_520_ci/i'   => 'utf8mb4_unicode_ci',
            '/utf8mb3_[a-z0-9_]+/i'       => 'utf8_general_ci',
            '/\butf8mb3\b/i'              => 'utf8',
        );
        $new = preg_replace(array_keys($map), array_values($map), $new);
        if ($new !== $stmt) {
            $why = 'collation not available on this server, mapped to a supported one';
        } elseif (fl_str_contains($e, 'utf8mb4')) {
            // Server predates utf8mb4 entirely (MySQL < 5.5.3).
            $new = preg_replace('/utf8mb4_[a-z0-9_]+/i', 'utf8_general_ci', $stmt);
            $new = preg_replace('/\butf8mb4\b/i', 'utf8', $new);
            if ($new !== $stmt) {
                $why = 'server has no utf8mb4, downgraded to utf8';
            }
        }
        if ($why !== null) {
            return array($new, $why);
        }
    }

    // The dev machine's MySQL user does not exist here, so every view,
    // trigger, routine and event in the dump dies on its DEFINER clause.
    // Note the plain 'super' check: MySQL words this as "You do not have the
    // SUPER privilege", which a 'superuser' test misses entirely.
    if (fl_str_contains($e, 'definer') || fl_str_contains($e, 'access denied')
        || fl_str_contains($e, 'super') || fl_str_contains($e, 'set_user_id')
        || fl_str_contains($e, 'log_bin_trust_function_creators')) {
        $new = preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|[^\s]+)/i', '', $stmt);
        $new = preg_replace('/\sSQL\s+SECURITY\s+DEFINER/i', ' SQL SECURITY INVOKER', $new);
        if ($new !== $stmt) {
            return array($new, 'dropped the DEFINER of a user that does not exist on this server');
        }
    }

    // MySQL 8 removed NO_AUTO_CREATE_USER from sql_mode; dumps from 5.7 set it.
    if (fl_str_contains($e, 'variable') || fl_str_contains($e, 'sql_mode')
        || fl_str_contains($e, 'unknown system variable')) {
        $new = preg_replace('/,?\s*NO_AUTO_CREATE_USER\s*/i', '', $stmt);
        $new = preg_replace("/''\\s*,/", "'", $new);
        if ($new !== $stmt) {
            return array($new, 'removed NO_AUTO_CREATE_USER, which MySQL 8 no longer accepts');
        }
    }

    // Storage engine the host did not compile in.
    if (fl_str_contains($e, 'unknown storage engine') || fl_str_contains($e, "storage engine")) {
        $new = preg_replace('/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $stmt);
        if ($new !== $stmt) {
            return array($new, 'storage engine unavailable here, switched to InnoDB');
        }
    }

    // Old InnoDB without large prefixes cannot index a 255 char utf8mb4 column.
    if (fl_str_contains($e, 'specified key was too long') || fl_str_contains($e, 'too long; max key length')) {
        if (preg_match('/^\s*CREATE\s+TABLE/i', $stmt) && !preg_match('/ROW_FORMAT\s*=/i', $stmt)) {
            $new = preg_replace('/(\)\s*ENGINE\s*=\s*\w+)/i', '$1 ROW_FORMAT=DYNAMIC', $stmt, 1);
            if ($new !== $stmt) {
                return array($new, 'added ROW_FORMAT=DYNAMIC so the long index fits');
            }
        }
    }

    return null;
}

/**
 * Is this failure the HOST refusing a privilege, rather than a bad dump?
 *
 * Shared hosting almost never grants SUPER. With binary logging on and
 * log_bin_trust_function_creators off, a non-SUPER user cannot create a
 * trigger or a stored routine AT ALL - stripping the DEFINER does not help,
 * because the restriction is about who is creating it, not who owns it.
 *
 * This matters because the site itself still works: a trigger or a view is a
 * convenience, and the pages read tables directly. So it has to be reported
 * as "ask your host for this one thing", not as a failed install.
 *
 * @param string $stmt
 * @param string $error
 * @return string|null a plain explanation, or null if this is not that case
 */
function fl_sql_blocked_by_host($stmt, $error)
{
    $e = strtolower($error);
    $privilege = fl_str_contains($e, 'super privilege') || fl_str_contains($e, 'super')
        || fl_str_contains($e, 'access denied') || fl_str_contains($e, 'command denied');
    if (!$privilege) {
        return null;
    }

    $kind = null;
    if (preg_match('/^\s*CREATE\s+(?:\S+\s+)*?TRIGGER\b/i', $stmt)) {
        $kind = 'trigger';
    } elseif (preg_match('/^\s*CREATE\s+(?:\S+\s+)*?(?:PROCEDURE|FUNCTION)\b/i', $stmt)) {
        $kind = 'stored routine';
    } elseif (preg_match('/^\s*CREATE\s+(?:\S+\s+)*?EVENT\b/i', $stmt)) {
        $kind = 'scheduled event';
    } elseif (preg_match('/^\s*CREATE\s+(?:\S+\s+)*?VIEW\b/i', $stmt)) {
        $kind = 'view';
    }
    if ($kind === null) {
        return null;
    }

    $name = '';
    if (preg_match('/\b(?:TRIGGER|PROCEDURE|FUNCTION|EVENT|VIEW)\s+`?([A-Za-z0-9_$]+)`?/i', $stmt, $m)) {
        $name = ' "' . $m[1] . '"';
    }

    $ask = fl_str_contains($e, 'binary logging') || fl_str_contains($e, 'log_bin_trust')
        ? 'Ask the host to set log_bin_trust_function_creators = 1, or to grant SUPER for the import.'
        : 'Ask the host to grant the missing privilege, or to run this one statement for you.';

    return 'The host will not let this database user create the ' . $kind . $name
        . '. The site and the dashboard work without it, because the pages read the tables '
        . 'directly. ' . $ask;
}

/**
 * Statements in a dump whose failure does not matter.
 *
 * @param string $stmt
 * @param string $error
 * @return bool
 */
function fl_sql_error_is_harmless($stmt, $error)
{
    $e = strtolower($error);
    $s = strtolower(ltrim($stmt));

    // A dump that carries CREATE DATABASE / USE for a differently named
    // database is normal: the host names the database for you.
    if (strpos($s, 'create database') === 0 || strpos($s, 'use ') === 0
        || strpos($s, 'create schema') === 0) {
        return true;
    }
    // Session knobs the dump sets for its own benefit.
    if (strpos($s, 'set ') === 0 && (fl_str_contains($e, 'access denied')
            || fl_str_contains($e, 'unknown system variable')
            || fl_str_contains($e, 'variable') || fl_str_contains($e, 'super'))) {
        return true;
    }
    // LOCK/UNLOCK TABLES is advisory for us.
    if (strpos($s, 'lock tables') === 0 || strpos($s, 'unlock tables') === 0) {
        return true;
    }
    return false;
}
