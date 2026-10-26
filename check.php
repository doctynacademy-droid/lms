<?php
// Doctyn Academy FCCM LMS — setup check. Open  https://yoursite.com/lms/check.php  to see what is wrong, then DELETE this file.
@ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$rows = array();
function add_row(&$rows, $ok, $label, $detail = '') { $rows[] = array($ok, $label, $detail); }
$dir = __DIR__;
$base = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$home = $scheme . '://' . $host . $base . '/';

// 1. PHP
add_row($rows, PHP_VERSION_ID >= 70400, 'PHP version is 7.4 or newer', 'This server runs PHP ' . PHP_VERSION . '. If it is older, choose a newer PHP in cPanel → Select PHP Version.');
add_row($rows, extension_loaded('pdo_sqlite'), 'SQLite extension (pdo_sqlite) is enabled', 'If not: cPanel → Select PHP Version → tick pdo_sqlite and sqlite3 → Save.');

// 2. files uploaded to the right place
$need = array('index.html', 'css/style.css', 'js/app.js', 'js/ping.js', 'assets/logo.png', 'app.php', 'config.php', '.htaccess', 'app/lib.php', 'app/routes.php', 'app/module-content.json');
$missing = array();
foreach ($need as $f) if (!is_file($dir . '/' . $f)) $missing[] = $f;
add_row($rows, !$missing, 'All app files are in this folder', $missing
    ? 'MISSING: ' . implode(', ', $missing) . '. Re-extract the zip so these sit directly inside this folder (not inside a sub-folder). Turn on "Show Hidden Files" in File Manager to see .htaccess.'
    : 'Folder: ' . $dir);

// 3. config + data folder
$cfg = array('admin_password' => '', 'data_dir' => '');
if (is_file($dir . '/config.php')) { $c = @include $dir . '/config.php'; if (is_array($c)) $cfg = array_merge($cfg, $c); }
$pwOk = $cfg['admin_password'] !== '' && $cfg['admin_password'] !== 'CHANGE-ME-TO-A-LONG-PASSWORD';
$hasAdmin = false;
add_row($rows, true, 'Install URL', 'Your LMS address should be:  ' . $home);
$dataDir = '';
$dbMsg = '';
try {
    require_once $dir . '/app/lib.php';
    $dataDir = data_dir();
    ensure_storage();
    $row = q_get("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
    $hasAdmin = $row && (int) $row['c'] > 0;
    $mods = q_get('SELECT COUNT(*) AS c FROM modules');
    add_row($rows, true, 'Data folder is writable and the database opens', $dataDir . '  (' . (int) $mods['c'] . ' modules in the database)');
} catch (SetupError $e) {
    add_row($rows, false, 'Data folder / database', $e->getMessage());
} catch (Throwable $e) {
    add_row($rows, false, 'Data folder / database', 'Error: ' . $e->getMessage());
}
add_row($rows, $pwOk || $hasAdmin, 'Admin password is set', $hasAdmin ? 'An admin account already exists.' : 'Open config.php in File Manager (right-click → Edit) and replace CHANGE-ME-TO-A-LONG-PASSWORD with your own password.');

// 4. server can reach the API (needs mod_rewrite + AllowOverride)
$apiNote = 'Could not test from the server side (your host blocks it). Use the link below instead.';
$apiOk = null;
$ctx = stream_context_create(array('http' => array('timeout' => 6, 'ignore_errors' => true), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
$body = @file_get_contents($home . 'api/brand', false, $ctx);
if ($body !== false) {
    $status = 0; if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) $status = (int) $m[1];
    $json = json_decode($body, true);
    $apiOk = is_array($json) && isset($json['brand_name']);
    $apiNote = $apiOk ? 'The API answers correctly.' : 'The API address returned HTTP ' . $status . ' and not the expected data. This usually means .htaccess is missing or mod_rewrite is off — ask your host to enable mod_rewrite / "AllowOverride All" for this folder.';
}
add_row($rows, $apiOk === null ? true : $apiOk, 'The web server routes /api to the app (.htaccess works)', $apiNote);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LMS setup check</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:760px;margin:2rem auto;padding:0 1.1rem;color:#101B33;line-height:1.55}
h1{color:#0A2E7A} .r{display:flex;gap:.8rem;padding:.8rem 1rem;border:1px solid #D7E3FA;border-radius:12px;margin:.6rem 0;background:#fff}
.ok{border-left:6px solid #128a55}.bad{border-left:6px solid #c0392b;background:#fdf3f2}.i{font-size:1.3rem}.d{color:#4A5578;font-size:.92rem;word-break:break-word}
a{color:#0A2E7A;font-weight:700} code{background:#E3ECFF;padding:.1rem .35rem;border-radius:5px}
</style></head><body>
<h1>Doctyn Academy FCCM LMS — setup check</h1>
<p>Each line should show a green tick. Fix any red line using the hint under it, then reload this page.</p>
<?php foreach ($rows as $r): ?>
<div class="r <?php echo $r[0] ? 'ok' : 'bad'; ?>"><div class="i"><?php echo $r[0] ? '✅' : '❌'; ?></div><div><b><?php echo htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8'); ?></b><div class="d"><?php echo htmlspecialchars($r[2], ENT_QUOTES, 'UTF-8'); ?></div></div></div>
<?php endforeach; ?>
<div class="r bad" id="jsrow"><div class="i" id="jsicon">❌</div><div><b id="jstxt">Your browser has not loaded the app's JavaScript file yet</b>
<div class="d">If this stays red, <code>js/ping.js</code> cannot be loaded from this folder (wrong folder, blocked file, or a security rule). Try opening <a href="js/app.js">js/app.js</a> — you should see program code, not an error page.</div></div></div>
<p><a href="<?php echo htmlspecialchars($home, ENT_QUOTES, 'UTF-8'); ?>">→ Open the LMS</a> &nbsp;|&nbsp; <a href="api/brand">Test the API address</a> (should show text starting with <code>{"brand_name"</code>)</p>
<p class="d"><b>When everything is green, delete <code>check.php</code> in File Manager</b> (it is only for setup).</p>
<script src="js/ping.js"></script>
</body></html>
