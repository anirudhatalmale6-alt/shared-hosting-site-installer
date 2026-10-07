<?php
/**
 * Bundle the installer into ONE drag-and-droppable install.php and stamp a
 * one time key into it.
 *
 * The client's hosting is driven through a browser file manager, so a single
 * file is the whole point: four files to upload is three chances to miss one.
 *
 * Usage: php build.php [--key=<key>] [--out=dist]
 */

$opts = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $opts[$m[1]] = isset($m[2]) ? $m[2] : true;
    }
}

$root = __DIR__;
$outDir = $root . '/' . (isset($opts['out']) ? $opts['out'] : 'dist');
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "could not create $outDir\n");
    exit(1);
}

$key = isset($opts['key']) && is_string($opts['key'])
    ? $opts['key']
    : strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

$main = file_get_contents($root . '/installer/install.php');
if ($main === false) {
    fwrite(STDERR, "cannot read installer/install.php\n");
    exit(1);
}

/**
 * Strip a library down to its body: no opening tag, no file level docblock.
 */
function body($path)
{
    $src = file_get_contents($path);
    $src = preg_replace('/^<\?php\s*/', '', $src, 1);
    // Drop the leading /** ... */ file comment; the bundled file gets its own.
    $src = preg_replace('#^\s*/\*\*.*?\*/\s*#s', '', $src, 1);
    return "\n/* ===== " . basename($path) . " ===== */\n\n" . trim($src) . "\n";
}

$bundle = body($root . '/installer/lib_sql.php')
        . body($root . '/installer/lib_config.php')
        . body($root . '/installer/lib_core.php');

// Replace the require loop with the inlined libraries.
$needle = "foreach (array('lib_sql.php', 'lib_config.php', 'lib_core.php') as \$fl_lib) {\n"
        . "    if (is_file(FL_DIR . '/' . \$fl_lib)) {\n"
        . "        require_once FL_DIR . '/' . \$fl_lib;\n"
        . "    }\n"
        . "}";
if (strpos($main, $needle) === false) {
    fwrite(STDERR, "the require block in install.php changed shape; update build.php\n");
    exit(1);
}
$main = str_replace($needle, trim($bundle), $main);

// Stamp the key hash.
$count = 0;
$main = str_replace('__FL_KEY_HASH__', hash('sha256', $key), $main, $count);
if ($count !== 1) {
    fwrite(STDERR, "expected exactly one key placeholder, found $count\n");
    exit(1);
}

$outPath = $outDir . '/install.php';
file_put_contents($outPath, $main);

// A bundle that does not parse is worse than no bundle.
$lint = array();
$rc = 0;
exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($outPath) . ' 2>&1', $lint, $rc);
if ($rc !== 0) {
    fwrite(STDERR, implode("\n", $lint) . "\n");
    exit(1);
}

// And one that still carries the placeholder would accept any key.
if (strpos(file_get_contents($outPath), '__FL_KEY_HASH__') !== false) {
    fwrite(STDERR, "the key placeholder survived; refusing to ship\n");
    exit(1);
}

printf("built  %s  (%s)\n", $outPath, number_format(filesize($outPath)) . ' bytes');
printf("key    %s\n", $key);
printf("open   https://<domain>/install.php   and paste that key\n");
