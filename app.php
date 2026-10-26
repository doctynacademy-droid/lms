<?php
// Doctyn Academy FCCM LMS — front controller. Receives every /api/*, /uploads/* and /media/* request.
// Static files (the web app itself) are served directly by the web server.

@ini_set('display_errors', '0');
require __DIR__ . '/app/lib.php';
require __DIR__ . '/app/routes.php';

send_security_headers();

$path = '/';
$isApi = false;
try {
    $base = base_path();
    $path = rawurldecode((string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH));
    if ($base !== '' && strpos($path, $base . '/') === 0) $path = substr($path, strlen($base));
    $isApi = strpos($path, '/api/') === 0;
    $method = $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'GET' : $_SERVER['REQUEST_METHOD'];

    db();                       // opens the database (creates it on first run); may raise a SetupError
    $user = current_user();

    if ($isApi) {
        if (!in_array($method, array('GET'), true) && (isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '') !== 'fetch') throw new HttpError(403, 'Bad request origin');
        $ov = strtoupper(isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) ? (string) $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] : '');
        if ($method === 'POST' && ($ov === 'PUT' || $ov === 'DELETE')) $method = $ov;   // for hosts that block PUT/DELETE
        foreach ($ROUTES as $r) {
            list($m, $re, $opts, $fn) = $r;
            if ($m !== $method || !preg_match($re, $path, $mm)) continue;
            if ($opts >= AUTH && !$user) throw new HttpError(401, 'Please sign in.');
            if ($opts === ADMIN && $user['role'] !== 'admin') throw new HttpError(403, 'Administrators only.');
            if ($opts >= AUTH && $user['must_change'] && strpos($path, '/api/me/password') !== 0 && $path !== '/api/me') throw new HttpError(403, 'Please set a new password first.');
            $params = array(); foreach ($mm as $k => $v) if (!is_int($k)) $params[$k] = $v;
            $fn(array('params' => $params, 'user' => $user));
            exit;
        }
        throw new HttpError(404, 'Not found');
    }
    if ($method !== 'GET') throw new HttpError(405, 'Method not allowed');

    if (strpos($path, '/uploads/') === 0) {                     // any signed-in user
        if (!$user || $user['must_change']) throw new HttpError(401, 'Please sign in.');
        $name = basename($path);
        if (!preg_match('~^[a-f0-9]{16,}\.[a-z0-9]+$~i', $name)) throw new HttpError(404, 'Not found');
        $att = q_get('SELECT name FROM attachments WHERE file = ?', array('/uploads/' . $name));
        serve_file(upload_dir() . '/' . $name, ($att && !preg_match('~\.pdf$~i', $name)) ? $att['name'] : null, 'private, max-age=3600');
    }
    if (strpos($path, '/media/') === 0) {                       // public (logo)
        $name = basename($path);
        if (!preg_match('~^[a-f0-9]{16,}\.[a-z0-9]+$~i', $name)) throw new HttpError(404, 'Not found');
        serve_file(media_dir() . '/' . $name, null, 'public, max-age=3600');
    }
    throw new HttpError(404, 'Not found');
} catch (SetupError $e) {
    if ($isApi) send_json(array('error' => $e->getMessage()), 500);
    setup_page($e->getMessage());
} catch (HttpError $e) {
    if ($isApi) send_json(array('error' => $e->getMessage()), $e->status);
    http_response_code($e->status); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage();
} catch (Throwable $e) {
    error_log('LMS error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($isApi) send_json(array('error' => 'Something went wrong on the server.'), 500);
    http_response_code(500); echo 'Something went wrong on the server.';
}
