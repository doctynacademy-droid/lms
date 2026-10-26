<?php
// Local testing only (php -S): imitates the .htaccess rules. Not needed on real hosting — do not upload.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$p = rawurldecode($p);
$prefix = getenv('LMS_BASE') ? '/' . trim(getenv('LMS_BASE'), '/') : '';
if ($prefix !== '' && strpos($p, $prefix) === 0) $p = substr($p, strlen($prefix)) ?: '/';
if (preg_match('~^/(api|uploads|media)(/|$)~', $p)) { $_SERVER['SCRIPT_NAME'] = $prefix . '/app.php'; require __DIR__ . '/app.php'; return true; }
if ($p === '/check.php') { $_SERVER['SCRIPT_NAME'] = $prefix . '/check.php'; require __DIR__ . '/check.php'; return true; }
if (preg_match('~^/(app|data)(/|$)|^/config\.php$|\.(db|json|md|zip)$~', $p)) { http_response_code(403); echo 'Forbidden'; return true; }
$f = __DIR__ . $p;
if ($p === '/' || $p === '') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__ . '/index.html'); return true; }
if (is_file($f) && !preg_match('~\.php$~', $f)) {
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $types = array('css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'html' => 'text/html; charset=utf-8');
    header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream')); readfile($f); return true;
}
http_response_code(404); echo 'Not found';
return true;
