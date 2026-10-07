<?php
/**
 * Unit tests for the installer's parsing and rewriting logic.
 *
 * These cover the cases a live run cannot reach: a server that rejects a
 * MySQL 8 collation, an archive with a traversal path, an identifier that
 * looks like a credential but is not.
 *
 * Usage: php tests/unit.php
 */

$dir = dirname(__DIR__) . '/installer';
require $dir . '/lib_sql.php';
require $dir . '/lib_config.php';
require $dir . '/lib_core.php';

$pass = 0;
$fail = 0;
$failures = array();

function t($label, $got, $want)
{
    global $pass, $fail, $failures;
    $ok = ($got === $want);
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        $failures[] = array($label, $got, $want);
    }
    echo($ok ? "  ok   " : "  FAIL ") . $label . "\n";
}

echo "\n-- SQL splitting --\n";

t('plain statements',
    fl_split_sql("SELECT 1; SELECT 2;"),
    array('SELECT 1', 'SELECT 2'));

t('a semicolon inside a string is not a terminator',
    fl_split_sql("INSERT INTO t VALUES ('a;b'); SELECT 2;"),
    array("INSERT INTO t VALUES ('a;b')", 'SELECT 2'));

t('a double dash inside a string is not a comment',
    fl_split_sql("INSERT INTO t VALUES ('not -- a comment');"),
    array("INSERT INTO t VALUES ('not -- a comment')"));

t('a line comment is dropped',
    fl_split_sql("-- header\nSELECT 1;\n# hash comment\nSELECT 2;"),
    array('SELECT 1', 'SELECT 2'));

t('a double dash with no space is NOT a comment (it is an operator)',
    fl_split_sql("SELECT 1--2;"),
    array('SELECT 1--2'));

t('a plain block comment is dropped',
    fl_split_sql("/* notes */ SELECT 1;"),
    array('SELECT 1'));

t('an executable /*! comment is kept',
    fl_split_sql("/*!40101 SET NAMES utf8mb4 */;"),
    array('/*!40101 SET NAMES utf8mb4 */'));

t('an escaped quote does not end the string',
    fl_split_sql("INSERT INTO t VALUES ('it\\'s fine; really');"),
    array("INSERT INTO t VALUES ('it\\'s fine; really')"));

t('a doubled quote does not end the string',
    fl_split_sql("INSERT INTO t VALUES ('it''s fine; really');"),
    array("INSERT INTO t VALUES ('it''s fine; really')"));

t('a trailing backslash in a string keeps the string open',
    fl_split_sql("INSERT INTO t VALUES ('C:\\\\dev\\\\x; y');"),
    array("INSERT INTO t VALUES ('C:\\\\dev\\\\x; y')"));

t('a backtick identifier containing a semicolon survives',
    fl_split_sql("SELECT `odd;name` FROM t;"),
    array('SELECT `odd;name` FROM t'));

$trig = "DELIMITER ;;\nCREATE TRIGGER x AFTER INSERT ON t FOR EACH ROW BEGIN\n"
      . "  INSERT INTO log VALUES (1);\n  INSERT INTO log VALUES (2);\nEND ;;\nDELIMITER ;\nSELECT 9;";
$got = fl_split_sql($trig);
t('DELIMITER keeps a multi statement trigger body in one piece', count($got), 2);
t('  the trigger body is intact',
    (strpos($got[0], 'VALUES (1)') !== false && strpos($got[0], 'VALUES (2)') !== false), true);
t('  the statement after DELIMITER ; still parses', trim($got[1]), 'SELECT 9');

t('a UTF-8 BOM is stripped',
    fl_split_sql("\xEF\xBB\xBFSELECT 1;"),
    array('SELECT 1'));

t('a final statement with no trailing semicolon is kept',
    fl_split_sql("SELECT 1;\nSELECT 2"),
    array('SELECT 1', 'SELECT 2'));

t('an empty dump yields nothing', fl_split_sql("-- only a comment\n\n"), array());

echo "\n-- SQL remediation --\n";

$r = fl_remediate_sql(
    "CREATE TABLE `a` (`x` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
    "Unknown collation: 'utf8mb4_0900_ai_ci'");
t('MySQL 8 collation is mapped', $r !== null && strpos($r[0], 'utf8mb4_unicode_ci') !== false, true);
t('  and 0900 is gone', $r !== null && strpos($r[0], '0900') === false, true);

$r = fl_remediate_sql(
    "CREATE ALGORITHM=UNDEFINED DEFINER=`dev`@`localhost` SQL SECURITY DEFINER VIEW `v` AS SELECT 1",
    "Access denied; you need (at least one of) the SUPER privilege(s) for this operation");
t('a SUPER privilege error strips DEFINER',
    $r !== null && strpos($r[0], 'DEFINER') === false, true);
t('  and SQL SECURITY becomes INVOKER',
    $r !== null && strpos($r[0], 'SQL SECURITY INVOKER') !== false, true);

$r = fl_remediate_sql(
    "CREATE DEFINER=`dev`@`localhost` TRIGGER `t1` AFTER INSERT ON `a` FOR EACH ROW BEGIN SELECT 1; END",
    "You do not have the SUPER privilege and binary logging is enabled");
t('a trigger DEFINER is stripped too',
    $r !== null && strpos($r[0], 'DEFINER') === false, true);

$r = fl_remediate_sql(
    "SET @@SESSION.SQL_MODE='STRICT_TRANS_TABLES,NO_AUTO_CREATE_USER'",
    "Variable 'sql_mode' can't be set to the value of 'NO_AUTO_CREATE_USER'");
t('NO_AUTO_CREATE_USER is removed',
    $r !== null && strpos($r[0], 'NO_AUTO_CREATE_USER') === false, true);

$r = fl_remediate_sql("CREATE TABLE a (x int) ENGINE=MyISAM_X", "Unknown storage engine 'MyISAM_X'");
t('an unknown engine becomes InnoDB',
    $r !== null && strpos($r[0], 'ENGINE=InnoDB') !== false, true);

t('a statement with a genuine syntax error is not "repaired"',
    fl_remediate_sql("SELCT * FROM a", "You have an error in your SQL syntax"), null);

echo "\n-- harmless errors --\n";

t('CREATE DATABASE failing is harmless',
    fl_sql_error_is_harmless("CREATE DATABASE `dev`", "Access denied for user"), true);
t('USE failing is harmless',
    fl_sql_error_is_harmless("USE `dev`", "Unknown database 'dev'"), true);
t('LOCK TABLES failing is harmless',
    fl_sql_error_is_harmless("LOCK TABLES `a` WRITE", "Access denied"), true);
t('a failed INSERT is NOT harmless',
    fl_sql_error_is_harmless("INSERT INTO a VALUES (1)", "Duplicate entry"), false);
t('a failed CREATE TABLE is NOT harmless',
    fl_sql_error_is_harmless("CREATE TABLE a (x int)", "Table 'a' already exists"), false);

echo "\n-- credential classification --\n";

foreach (array('db_host' => 'host', 'DBHOST' => 'host', 'mysql_host' => 'host',
               'servername' => 'host', 'hostname' => 'host',
               'db_name' => 'name', 'dbname' => 'name', 'mysql_database' => 'name',
               'db_user' => 'user', 'username' => 'user', 'DB_USERNAME' => 'user',
               'db_pass' => 'pass', 'password' => 'pass', 'DB_PASSWORD' => 'pass',
               'my_db_hostname' => 'host',
               // A bare $db / $database holding a STRING is the database name.
               // As a connection handle it holds an object, not a literal, so
               // the rewriter never touches it.
               'db' => 'name', 'database' => 'name') as $ident => $want) {
    t("'$ident' is the $want", fl_classify_credential($ident), $want);
}

// The regression that mattered: these must NOT be treated as credentials.
foreach (array('site_name', 'SITE_NAME', 'company_name', 'admin_email', 'site_url',
               'upload_dir', 'page_title', 'first_name', 'last_name', 'user_id',
               'api_host', 'smtp_host', 'smtp_password', 'name', 'user', 'host',
               'pass', 'table_name', 'file_name') as $ident) {
    t("'$ident' is NOT a db credential", fl_classify_credential($ident), null);
}

echo "\n-- config rewriting --\n";

$creds = array('host' => 'localhost', 'name' => 'newdb', 'user' => 'newuser', 'pass' => 'newpass');

list($out, $ch) = fl_rewrite_db_config(
    "<?php\ndefine('DB_NAME', 'olddb');\ndefine('SITE_NAME', 'Harbour & Co');\n", $creds);
t('define(DB_NAME) is rewritten', strpos($out, "'newdb'") !== false, true);
t('define(SITE_NAME) is left alone', strpos($out, 'Harbour & Co') !== false, true);
t('  exactly one change reported', count($ch), 1);

list($out, $ch) = fl_rewrite_db_config(
    "<?php \$link = mysqli_connect(\"localhost\", \"devuser\", \"devpass\", \"olddb\");\n", $creds);
t('mysqli_connect literals are rewritten',
    strpos($out, "'newuser'") !== false && strpos($out, "'newdb'") !== false, true);

list($out, $ch) = fl_rewrite_db_config(
    "<?php \$pdo = new PDO('mysql:host=localhost;dbname=olddb;charset=utf8mb4', 'u', 'p');\n", $creds);
t('a PDO DSN is rewritten', strpos($out, 'dbname=newdb') !== false, true);

list($out, $ch) = fl_rewrite_db_config(
    "<?php \$c = mysqli_connect(\$h, \$u, \$p, \$d);\n", $creds);
t('variable arguments are left alone', count($ch), 0);

list($out, $ch) = fl_rewrite_db_config(
    "<?php define('DB_NAME', 'newdb');\n", $creds);
t('a value that already matches is not reported as a change', count($ch), 0);

list($out, $ch) = fl_rewrite_db_config("<?php \$db_pass = 'old';\n",
    array('host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => "it's \\ odd"));
t('a password with a quote and a backslash is escaped',
    strpos($out, "'it\\'s \\\\ odd'") !== false, true);
$tmp = tempnam(sys_get_temp_dir(), 'flcfg') . '.php';
file_put_contents($tmp, $out);
t('  and the rewritten file still parses', fl_php_lints($tmp), true);
@unlink($tmp);

echo "\n-- dev leftovers --\n";

$src = "<?php\ndefine('UPLOAD_DIR', 'C:\\\\xampp\\\\htdocs\\\\mysite\\\\uploads');\n"
     . "define('SITE_URL', 'http://localhost/mysite');\n"
     . "\$api = 'https://api.example.com/v1';\n"
     . "ini_set('display_errors', 1);\n";
$hits = fl_scan_dev_leftovers('includes/config.php', $src);
$kinds = array();
foreach ($hits as $h) {
    $kinds[] = $h['kind'];
}
t('an escaped Windows path is found', in_array('windows-path', $kinds, true), true);
t('a localhost URL is found', in_array('localhost-url', $kinds, true), true);
t('display_errors is found', in_array('errors-visible', $kinds, true), true);
t('a real external API URL is not flagged', count($hits), 3);

$hits = fl_scan_dev_leftovers('x.php', "<?php \$p = '/var/www/html/mysite/up';\n");
t('a Linux dev path is found', count($hits) === 1 && $hits[0]['kind'] === 'absolute-path', true);

$hits = fl_scan_dev_leftovers('x.php', "<?php \$u = 'http://mysite.test/assets';\n");
t('a .test hostname is found', count($hits) === 1 && $hits[0]['kind'] === 'dev-hostname', true);

echo "\n-- archive safety --\n";

t('a single wrapper folder is detected',
    fl_common_prefix(array('mysite/index.php', 'mysite/admin/index.php')), 'mysite/');
t('two top folders means no wrapper',
    fl_common_prefix(array('mysite/index.php', 'other/x.php')), '');
t('a file at the root means no wrapper',
    fl_common_prefix(array('index.php', 'mysite/x.php')), '');
t('__MACOSX is ignored when deciding',
    fl_common_prefix(array('__MACOSX/._x', 'mysite/index.php')), 'mysite/');

t('a traversal entry is rejected', fl_entry_is_safe('../../etc/passwd'), false);
t('a nested traversal entry is rejected', fl_entry_is_safe('a/../../b'), false);
t('an absolute entry is rejected', fl_entry_is_safe('/etc/passwd'), false);
t('a Windows absolute entry is rejected', fl_entry_is_safe('C:/windows/x'), false);
t('a normal entry is accepted', fl_entry_is_safe('admin/index.php'), true);
t('a dotfile entry is accepted', fl_entry_is_safe('.htaccess'), true);

echo "\n-- helpers --\n";

t('args split on top level commas only',
    fl_split_php_args("'a', foo(1, 2), 'b'"),
    array("'a'", " foo(1, 2)", " 'b'"));
t('a comma inside a string does not split',
    fl_split_php_args("'a,b', 'c'"),
    array("'a,b'", " 'c'"));
t('a single quoted literal is read', fl_php_string_literal("'abc'"), 'abc');
t('an interpolated literal is refused', fl_php_string_literal('"pre$x"'), null);
t('a variable is refused', fl_php_string_literal('$x'), null);
t('a concatenation is refused', fl_php_string_literal("'a' . 'b'"), null);

t('bytes are humanised', fl_human_bytes(1536), '1.5 KB');
t('zero bytes', fl_human_bytes(0), '0 B');

echo "\n";
if ($fail) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  " . $f[0] . "\n    got:  " . var_export($f[1], true)
            . "\n    want: " . var_export($f[2], true) . "\n";
    }
}
echo ($fail ? "FAIL" : "PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

/**
 * Does this file parse? Used to prove a rewritten config is still valid PHP.
 */
function fl_php_lints($path)
{
    $out = array();
    $rc = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return $rc === 0;
}
