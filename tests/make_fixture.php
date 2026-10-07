<?php
/**
 * Builds a fake "client handover": a small custom HTML/CSS site with a PHP
 * admin dashboard, zipped with a wrapper folder, plus a mysqldump taken on a
 * MySQL 8 dev box.
 *
 * Every awkward thing in here is deliberate, because each one is a real way a
 * migration like this fails:
 *   - the zip wraps everything in mysite/, so a naive unpack buries the site
 *   - the dump is MySQL 8: utf8mb4_0900_ai_ci collation, NO_AUTO_CREATE_USER
 *   - a view and a trigger carry DEFINER=`devuser`@`localhost`
 *   - a trigger needs DELIMITER handling to survive splitting
 *   - row data contains quotes, backslashes, semicolons, emoji and a -- that
 *     is NOT a comment because it is inside a string
 *   - the dump carries its own CREATE DATABASE / USE for the dev db name
 *   - config.php holds dev credentials via define(), plus a second file using
 *     mysqli_connect() with inline literals and a third using a PDO DSN
 *   - the site has a hardcoded C:\xampp path and an http://localhost asset
 *
 * Usage: php make_fixture.php <outdir>
 */

$out = isset($argv[1]) ? rtrim($argv[1], '/') : __DIR__ . '/../fixture';
$site = $out . '/_site/mysite';

function rrmdir($d)
{
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d), array('.', '..')) as $i) {
        is_dir("$d/$i") ? rrmdir("$d/$i") : unlink("$d/$i");
    }
    rmdir($d);
}
rrmdir($out);
foreach (array($site, $site . '/admin', $site . '/assets', $site . '/includes', $site . '/uploads') as $d) {
    mkdir($d, 0755, true);
}

/* ------------------------------------------------------------- site files */

file_put_contents($site . '/index.php', <<<'PHP'
<?php
require_once __DIR__ . '/includes/config.php';
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    http_response_code(500);
    exit('Database connection failed: ' . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');
$res = mysqli_query($conn, "SELECT title, body FROM articles WHERE published = 1 ORDER BY id DESC");
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<header><h1><?php echo SITE_NAME; ?></h1><nav><a href="admin/">Admin</a></nav></header>
<main>
<?php while ($row = mysqli_fetch_assoc($res)): ?>
  <article>
    <h2><?php echo htmlspecialchars($row['title']); ?></h2>
    <p><?php echo htmlspecialchars($row['body']); ?></p>
  </article>
<?php endwhile; ?>
</main>
<footer><p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?></p></footer>
</body></html>
PHP
);

// Dev credentials via define(), the single most common shape.
file_put_contents($site . '/includes/config.php', <<<'PHP'
<?php
// Local development settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'mysite_dev');
define('DB_USER', 'devuser');
define('DB_PASS', 'devpass123');
define('SITE_NAME', 'Harbour & Co');
define('UPLOAD_DIR', 'C:\\xampp\\htdocs\\mysite\\uploads');
define('SITE_URL', 'http://localhost/mysite');
error_reporting(E_ALL);
ini_set('display_errors', 1);
PHP
);

// A second connection shape: inline literals in mysqli_connect().
file_put_contents($site . '/includes/db_legacy.php', <<<'PHP'
<?php
// Older part of the site, never refactored
$link = mysqli_connect("localhost", "devuser", "devpass123", "mysite_dev");
if (!$link) { die("could not connect"); }
$db_host = 'localhost';
$db_name = "mysite_dev";
PHP
);

// A third: a PDO DSN.
file_put_contents($site . '/includes/report.php', <<<'PHP'
<?php
$pdo = new PDO('mysql:host=localhost;dbname=mysite_dev;charset=utf8mb4', 'devuser', 'devpass123');
$stats = $pdo->query('SELECT COUNT(*) AS c FROM articles')->fetch();
PHP
);

file_put_contents($site . '/assets/style.css', <<<'CSS'
:root{--ink:#16202b;--acc:#1d5fa8}
*{box-sizing:border-box}
body{margin:0;font:16px/1.6 Georgia,serif;color:var(--ink)}
header{display:flex;justify-content:space-between;align-items:center;
padding:20px 32px;border-bottom:1px solid #e2e6ea}
main{max-width:720px;margin:0 auto;padding:32px}
article{padding:20px 0;border-bottom:1px solid #eef1f4}
h2{margin:0 0 8px;font-size:21px}
a{color:var(--acc)}
footer{padding:28px 32px;color:#6b7785;font-size:14px}
CSS
);

file_put_contents($site . '/admin/index.php', <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    http_response_code(500);
    exit('Database connection failed: ' . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');

$error = '';
if (isset($_POST['username'])) {
    $u = mysqli_real_escape_string($conn, $_POST['username']);
    $p = md5($_POST['password']);
    $r = mysqli_query($conn, "SELECT id, username FROM admin_users WHERE username='$u' AND password='$p'");
    if ($row = mysqli_fetch_assoc($r)) {
        $_SESSION['admin'] = $row['username'];
        mysqli_query($conn, "UPDATE admin_users SET last_login = NOW() WHERE id = " . (int)$row['id']);
    } else {
        $error = 'Wrong username or password';
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

$loggedIn = !empty($_SESSION['admin']);
if ($loggedIn && isset($_POST['title'])) {
    $t = mysqli_real_escape_string($conn, $_POST['title']);
    $b = mysqli_real_escape_string($conn, $_POST['body']);
    mysqli_query($conn, "INSERT INTO articles (title, body, published) VALUES ('$t', '$b', 1)");
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin</title><link rel="stylesheet" href="../assets/style.css"></head><body>
<header><h1>Dashboard</h1><nav><?php if ($loggedIn): ?>
<a href="?logout=1">Log out</a><?php endif; ?></nav></header><main>
<?php if (!$loggedIn): ?>
  <?php if ($error): ?><p id="err" style="color:#b3261e"><?php echo $error; ?></p><?php endif; ?>
  <form method="post">
    <p><label>Username<br><input name="username" id="username"></label></p>
    <p><label>Password<br><input type="password" name="password" id="password"></label></p>
    <p><button type="submit" id="login">Log in</button></p>
  </form>
<?php else: ?>
  <p id="welcome">Signed in as <?php echo htmlspecialchars($_SESSION['admin']); ?></p>
  <form method="post">
    <p><label>Title<br><input name="title" id="title"></label></p>
    <p><label>Body<br><textarea name="body" id="body"></textarea></label></p>
    <p><button type="submit" id="save">Save article</button></p>
  </form>
  <h2>Articles</h2>
  <table id="articles"><?php
    $all = mysqli_query($conn, "SELECT id, title, published FROM articles ORDER BY id DESC");
    while ($a = mysqli_fetch_assoc($all)) {
        echo '<tr><td>' . (int)$a['id'] . '</td><td>' . htmlspecialchars($a['title'])
           . '</td><td>' . ($a['published'] ? 'live' : 'draft') . '</td></tr>';
    }
  ?></table>
<?php endif; ?>
</main></body></html>
PHP
);

file_put_contents($site . '/uploads/.gitkeep', '');

/* ------------------------------------------------------------------- dump */

$dump = <<<'SQL'
-- MySQL dump 10.13  Distrib 8.0.36, for Win64 (x86_64)
--
-- Host: localhost    Database: mysite_dev
-- ------------------------------------------------------
-- Server version	8.0.36

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
SET @@SESSION.SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_AUTO_CREATE_USER';

CREATE DATABASE IF NOT EXISTS `mysite_dev` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `mysite_dev`;

DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(64) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `admin_users` VALUES
 (1,'admin','21232f297a57a5a743894a0e4a801fc3','admin@example.com',NULL),
 (2,'editor','5f4dcc3b5aa765d61d8327deb882cf99','editor@example.com',NULL);

DROP TABLE IF EXISTS `articles`;
CREATE TABLE `articles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `body` text COLLATE utf8mb4_0900_ai_ci,
  `published` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `published` (`published`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `articles` VALUES
 (1,'Welcome to Harbour & Co','We opened in 1994; we have been here ever since.',1,'2026-01-04 09:12:00'),
 (2,'A quote inside a quote','She said \'this is fine\' and then added "really, it is".',1,'2026-02-11 14:30:00'),
 (3,'Not a comment -- this is body text; with a semicolon','Backslash: C:\\Users\\dev\\file.txt and an emoji 🌊 for charset proof.',1,'2026-03-02 08:00:00'),
 (4,'Still a draft','Unpublished, so the public page must not show it.',0,'2026-03-20 17:45:00');

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `k` varchar(64) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `v` text COLLATE utf8mb4_0900_ai_ci,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `settings` VALUES ('site_url','http://localhost/mysite'),('tagline','Chandlery since 1994');

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `what` varchar(190) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

/*!50001 DROP VIEW IF EXISTS `published_articles`*/;
CREATE ALGORITHM=UNDEFINED DEFINER=`devuser`@`localhost` SQL SECURITY DEFINER VIEW `published_articles` AS
 SELECT `articles`.`id` AS `id`, `articles`.`title` AS `title` FROM `articles` WHERE (`articles`.`published` = 1);

DELIMITER ;;
CREATE DEFINER=`devuser`@`localhost` TRIGGER `articles_after_insert` AFTER INSERT ON `articles`
FOR EACH ROW BEGIN
  INSERT INTO audit_log (what) VALUES (CONCAT('article created: ', NEW.title));
END ;;
DELIMITER ;

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
-- Dump completed
SQL;

file_put_contents($out . '/mysite_dev.sql', $dump);

/* -------------------------------------------------------------------- zip */

$zipPath = $out . '/mysite.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "could not create zip\n");
    exit(1);
}
$base = $out . '/_site';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
    if ($f->isDir()) {
        $zip->addEmptyDir($rel);
    } else {
        $zip->addFile($f->getPathname(), $rel);
        $n++;
    }
}
$zip->close();

echo "fixture written to $out\n";
echo "  mysite.zip      $n files, wrapped in mysite/\n";
echo "  mysite_dev.sql  " . strlen($dump) . " bytes\n";
