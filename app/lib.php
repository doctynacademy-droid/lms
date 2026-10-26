<?php
// Doctyn Academy FCCM LMS — PHP edition. Core: config, database, helpers, auth.
// Works on ordinary shared hosting (PHP 7.4+ with the PDO SQLite extension). No Node.js needed.

if (PHP_VERSION_ID < 70400) { http_response_code(500); exit('Doctyn Academy FCCM LMS needs PHP 7.4 or newer.'); }
if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null) { return $len === null ? substr($s, $start) : substr($s, $start, $len); } }
if (!function_exists('str_contains')) { function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; } }

class HttpError extends Exception {
    public $status;
    public function __construct($status, $msg) { parent::__construct($msg); $this->status = $status; }
}
class SetupError extends Exception {}

function bad($m) { return new HttpError(400, $m); }

// ---------------------------------------------------------------- config
$CFG = array(
    'admin_id' => 'admin', 'admin_password' => '', 'data_dir' => '',
    'max_upload_mb' => 512, 'trust_proxy' => false,
);
$cfgFile = dirname(__DIR__) . '/config.php';
if (is_file($cfgFile)) { $user = require $cfgFile; if (is_array($user)) $CFG = array_merge($CFG, $user); }
function cfg($k) { global $CFG; return $CFG[$k]; }

// Mount point: '' at a domain root, '/lms' when installed in a folder called lms.
function base_path() {
    $b = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $b === '/' || $b === '.' ? '' : rtrim($b, '/');
}
function is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return cfg('trust_proxy') && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function client_ip() {
    if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '?';
}

// ---------------------------------------------------------------- storage
// Where the database and uploads live. Left blank in config.php, we prefer a folder OUTSIDE the website
// (next to public_html, e.g. /home/you/doctyn-fccm-data) and only fall back to ./data (protected by .htaccess).
function data_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $set = trim((string) cfg('data_dir'));
    if ($set !== '') return $dir = rtrim(str_replace('\\', '/', $set), '/');
    $cands = array();
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/') : '';
    if ($doc !== '' && dirname($doc) !== $doc) $cands[] = dirname($doc) . '/doctyn-fccm-data';
    $cands[] = str_replace('\\', '/', dirname(__DIR__)) . '/data';
    foreach ($cands as $c) {
        if (is_dir($c) ? is_writable($c) : @mkdir($c, 0755, true)) return $dir = $c;
    }
    return $dir = end($cands);
}
function upload_dir() { return data_dir() . '/uploads'; }
function media_dir() { return data_dir() . '/media'; }

function ensure_storage() {
    foreach (array(data_dir(), upload_dir(), media_dir()) as $d) {
        if (!is_dir($d) && !@mkdir($d, 0755, true)) throw new SetupError("The data folder cannot be created: $d. Create it in File Manager and make it writable (permissions 755), or change data_dir in config.php.");
    }
    if (!is_writable(data_dir())) throw new SetupError('The data folder is not writable: ' . data_dir() . '. Set its permissions to 755 (or 775) in File Manager.');
    // Belt and braces: block web access to the data folder if it happens to live inside the website.
    $ht = data_dir() . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $ix = data_dir() . '/index.html';
    if (!is_file($ix)) @file_put_contents($ix, '');
}

// ---------------------------------------------------------------- database
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!extension_loaded('pdo_sqlite')) throw new SetupError('Your hosting needs the "pdo_sqlite" PHP extension. In cPanel open "Select PHP Version" (or "PHP Extensions") and tick pdo_sqlite and sqlite3.');
    ensure_storage();
    $fresh = !is_file(data_dir() . '/lms.db');
    $pdo = new PDO('sqlite:' . data_dir() . '/lms.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 8000;');
    try { $pdo->exec('PRAGMA journal_mode = WAL;'); } catch (Exception $e) { /* some filesystems refuse WAL */ }
    install_schema($pdo);
    seed($pdo);
    return $pdo;
}
function q_all($sql, $p = array()) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function q_get($sql, $p = array()) { $s = db()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r === false ? null : $r; }
function q_run($sql, $p = array()) { $s = db()->prepare($sql); $s->execute($p); return $s->rowCount(); }
function q_id() { return (int) db()->lastInsertId(); }
function tx(callable $fn) {
    $pdo = db(); $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; } catch (Exception $e) { $pdo->rollBack(); throw $e; }
}
function now() { return gmdate('Y-m-d H:i:s'); }
function audit($actor, $action, $detail = '') { q_run('INSERT INTO audit (actor, action, detail) VALUES (?,?,?)', array($actor, $action, $detail)); }

function install_schema($pdo) {
    $pdo->exec("
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY, student_id TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT NOT NULL, email TEXT NOT NULL DEFAULT '',
  password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'student', must_change INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')), last_login TEXT, last_active TEXT, last_lesson_id INTEGER
);
CREATE TABLE IF NOT EXISTS sessions (
  token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')), expires_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS modules (
  id INTEGER PRIMARY KEY, title TEXT NOT NULL, summary TEXT NOT NULL DEFAULT '', fact TEXT NOT NULL DEFAULT '',
  learn TEXT NOT NULL DEFAULT '', topics TEXT NOT NULL DEFAULT '', outputs TEXT NOT NULL DEFAULT '',
  position INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 1, release_date TEXT
);
CREATE TABLE IF NOT EXISTS lessons (
  id INTEGER PRIMARY KEY, module_id INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE, title TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0, body TEXT NOT NULL DEFAULT '', video_type TEXT NOT NULL DEFAULT 'none',
  video_src TEXT NOT NULL DEFAULT '', est_minutes INTEGER, published INTEGER NOT NULL DEFAULT 1,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS attachments (
  id INTEGER PRIMARY KEY, lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  name TEXT NOT NULL, file TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS progress (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  completed_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (user_id, lesson_id)
);
CREATE TABLE IF NOT EXISTS announcements (
  id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '', pinned INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY, at TEXT NOT NULL DEFAULT (datetime('now')), actor TEXT, action TEXT NOT NULL, detail TEXT
);
CREATE TABLE IF NOT EXISTS login_attempts (k TEXT PRIMARY KEY, n INTEGER NOT NULL, until INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_lessons_module ON lessons(module_id, position);
CREATE INDEX IF NOT EXISTS idx_progress_lesson ON progress(lesson_id);
");
}

// ---------------------------------------------------------------- settings
function default_settings() {
    return array(
        'brand_name' => 'Doctyn Academy', 'tagline' => 'Fellowship in Critical Care Medicine.', 'logo_url' => '/assets/logo.png',
        'color_primary' => '#14275C', 'color_accent' => '#3FA34D',
        'program_name' => 'Fellowship in Critical Care Medicine (FCCM)',
        'program_desc' => '3-month clinical training in critical care: 16 modules covering the core ICU procedures in your logbook.',
        'welcome_text' => 'Welcome back. Pick up where you left off, or explore any module below.',
        'support_text' => 'Forgot your ID or password? Contact your program coordinator to have it reset.',
        'lock_by_date' => '0', 'allow_id_change' => '1',
    );
}
function get_settings() {
    $out = default_settings();
    foreach (q_all('SELECT key, value FROM settings') as $r) if (array_key_exists($r['key'], $out)) $out[$r['key']] = $r['value'];
    return $out;
}
function set_setting($k, $v) { q_run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', array($k, (string) $v)); }

// ---------------------------------------------------------------- passwords
function hash_pw($pw) { return password_hash($pw, PASSWORD_DEFAULT); }
function verify_pw($pw, $hash) { return password_verify((string) $pw, (string) $hash); }
function random_password($len = 10) {
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

// ---------------------------------------------------------------- first-run seed
function seed($pdo) {
    if (!q_get("SELECT 1 AS x FROM users WHERE role = 'admin'")) {
        $id = trim((string) cfg('admin_id')) ?: 'admin'; $pw = (string) cfg('admin_password');
        if ($pw === '' || $pw === 'CHANGE-ME-TO-A-LONG-PASSWORD') throw new SetupError("First-time setup: open config.php in File Manager (right-click, Edit) and set admin_password to a long password of your own. Then reload this page.");
        if (strlen($pw) < 8) throw new SetupError('admin_password in config.php must be at least 8 characters.');
        q_run('INSERT INTO users (student_id, name, role, password_hash, must_change) VALUES (?,?,?,?,0)', array($id, 'Administrator', 'admin', hash_pw($pw)));
    }
    if (!q_get('SELECT 1 AS x FROM modules LIMIT 1')) {
        $titles = array(
            'Module 01 – Orientation & Your Clinical Logbook',
            'Module 02 – Central Venous Catheter (CVC) Insertion',
            'Module 03 – Arterial Line Insertion & Blood Gas Interpretation',
            'Module 04 – Endotracheal Intubation & Supraglottic Airway (SGA)',
            'Module 05 – Mechanical Ventilation',
            'Module 06 – Chest Tube Insertion & Management',
            'Module 07 – Cardiopulmonary Resuscitation (CPR) & ECG',
            'Module 08 – Nasogastric & Orogastric Tube Insertion',
            'Module 09 – Urinary Catheterization',
            'Module 10 – Point-of-Care Ultrasound (POCUS)',
            'Module 11 – Tracheostomy Care',
            'Module 12 – Bronchoscopy Assistance',
            'Module 13 – Ventilator-associated Procedures',
            'Module 14 – Bedside Procedures & Neurologic Assessment',
            'Module 15 – Pacemaker & Defibrillator Management',
            'Module 16 – ICU Drugs, Scores & Supportive Care',
        );
        $content = json_decode((string) @file_get_contents(__DIR__ . '/module-content.json'), true) ?: array();
        tx(function () use ($titles, $content) {
            foreach ($titles as $i => $t) {
                $c = isset($content[$i + 1]) ? $content[$i + 1] : array('fact' => '', 'learn' => array(), 'topics' => array(), 'outputs' => array());
                q_run('INSERT INTO modules (title, fact, learn, topics, outputs, position, release_date) VALUES (?,?,?,?,?,?,?)', array(
                    $t, $c['fact'], implode("\n", $c['learn']), implode("\n", $c['topics']), implode("\n", $c['outputs']), $i + 1, null));
            }
            $m1 = (int) q_get('SELECT id FROM modules ORDER BY position LIMIT 1')['id'];
            q_run('INSERT INTO lessons (module_id, title, position, body, est_minutes) VALUES (?,?,?,?,?)', array($m1, 'Sample lesson — delete me', 1,
                '<h2>How lessons look</h2><p>This is a sample lesson so you can see the layout. In <b>Admin → Content</b> you can edit this text, add a video (YouTube link, embed code or an uploaded file), attach files, and reorder everything.</p><ul><li>Bold, headings, lists and links work in the editor</li><li>Students tick <i>Mark as complete</i> to track progress</li></ul>', 5));
            q_run('INSERT INTO announcements (title, body, pinned) VALUES (?,?,1)', array('Welcome to the Academy', 'Complete each lesson, record your procedures in your logbook, and tick lessons off to track your progress.'));
        });
    }
}

// ---------------------------------------------------------------- http helpers
function send_security_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
function send_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function read_json($limit = 2097152) {
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || $raw === '') return array();
    if (strlen($raw) > $limit) throw new HttpError(413, 'Request too large');
    $d = json_decode($raw, true);
    if (!is_array($d)) throw bad('Invalid JSON');
    return $d;
}
function s($v, $max = 500) { return mb_substr(trim((string) ($v === null ? '' : $v)), 0, $max); }
function flag($v) { return $v ? 1 : 0; }
function date_or_null($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null; }
function bval($b, $k, $default = null) { return array_key_exists($k, $b) ? $b[$k] : $default; }
function truthy($v) { return $v === true || $v === 1 || $v === '1' || $v === 'true'; }

// Strip active content from lesson HTML (authoring is admin-only; the page's CSP is the second layer).
function sanitize_html($html) {
    $h = (string) $html;
    $h = preg_replace('~<(script|style|iframe|object|embed|link|meta|base|form)\b[\s\S]*?(</\1\s*>|$)~i', '', $h);
    $h = preg_replace('~</?(script|style|iframe|object|embed|link|meta|base|form)\b[^>]*>~i', '', $h);
    $h = preg_replace('~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $h);
    $h = preg_replace('~(href|src)\s*=\s*("|\')\s*(javascript|data|vbscript):[^"\']*\2~i', '$1=$2#$2', $h);
    return mb_substr((string) $h, 0, 500000);
}

// Turn whatever an admin pastes (YouTube/Vimeo/Drive link, <iframe> code, direct file) into a safe source.
function parse_video($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return array('none', '');
    if (preg_match('~^/uploads/[a-f0-9]{16,}\.[a-z0-9]+$~i', $raw)) return array('upload', $raw);
    $url = $raw;
    if (preg_match('~<iframe[^>]+src\s*=\s*["\']([^"\']+)["\']~i', $raw, $m)) $url = $m[1];
    if (strpos($url, '//') === 0) $url = 'https:' . $url;
    $u = parse_url($url);
    if (!$u || empty($u['host']) || empty($u['scheme'])) throw bad('That does not look like a valid video link or embed code.');
    if (strtolower($u['scheme']) !== 'https') throw bad('Video links must start with https://');
    $host = preg_replace('/^www\./', '', strtolower($u['host']));
    $path = isset($u['path']) ? $u['path'] : '/';
    parse_str(isset($u['query']) ? $u['query'] : '', $qs);
    $yt = function ($id) { return preg_match('/^[\w-]{6,15}$/', (string) $id) ? array('youtube', 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1') : null; };
    if (in_array($host, array('youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'music.youtube.com'), true)) {
        $id = isset($qs['v']) ? $qs['v'] : (preg_match('~^/(?:embed|shorts|live|v)/([\w-]+)~', $path, $mm) ? $mm[1] : null);
        $r = $id ? $yt($id) : null; if ($r) return $r;
        throw bad('Could not find a video ID in that YouTube link.');
    }
    if ($host === 'youtu.be') { $r = $yt(ltrim($path, '/')); if ($r) return $r; throw bad('Could not find a video ID in that YouTube link.'); }
    if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
        if (!preg_match('~(\d{5,})(?:/([a-f0-9]+))?~', $path, $mm)) throw bad('Could not find a video ID in that Vimeo link.');
        $hh = isset($mm[2]) && $mm[2] !== '' ? $mm[2] : (isset($qs['h']) ? $qs['h'] : '');
        return array('vimeo', 'https://player.vimeo.com/video/' . $mm[1] . ($hh ? '?h=' . $hh : ''));
    }
    if ($host === 'drive.google.com') {
        $id = preg_match('~/file/d/([\w-]+)~', $path, $mm) ? $mm[1] : (isset($qs['id']) ? $qs['id'] : null);
        if ($id) return array('embed', 'https://drive.google.com/file/d/' . $id . '/preview');
    }
    if (preg_match('~\.(mp4|webm|m4v|mov)$~i', $path)) return array('file', $url);
    return array('embed', $url);
}

// ---------------------------------------------------------------- auth
function session_cookie($token, $maxAge) {
    $c = 'dsid=' . $token . '; Path=' . (base_path() ?: '/') . '; HttpOnly; SameSite=Lax; Max-Age=' . $maxAge . (is_https() ? '; Secure' : '');
    header('Set-Cookie: ' . $c, false);
}
function current_user() {
    static $cached = false;
    if ($cached !== false) return $cached;
    $t = isset($_COOKIE['dsid']) ? (string) $_COOKIE['dsid'] : '';
    if ($t === '' || !preg_match('/^[a-f0-9]{64}$/', $t)) return $cached = null;
    $row = q_get('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ? AND u.active = 1', array(hash('sha256', $t), now()));
    if ($row && (!$row['last_active'] || time() - strtotime($row['last_active'] . ' UTC') > 60)) q_run('UPDATE users SET last_active = ? WHERE id = ?', array(now(), $row['id']));
    return $cached = $row;
}
function create_session($userId) {
    $token = bin2hex(random_bytes(32));
    q_run('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?,?,?)', array(hash('sha256', $token), $userId, gmdate('Y-m-d H:i:s', time() + 14 * 86400)));
    session_cookie($token, 14 * 86400);
}
function public_user($u) { return array('id' => (int) $u['id'], 'student_id' => $u['student_id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'must_change' => (bool) $u['must_change']); }

function throttle($key) {
    $a = q_get('SELECT n, until FROM login_attempts WHERE k = ?', array($key));
    if ($a && (int) $a['n'] >= 8 && (int) $a['until'] > time()) throw new HttpError(429, 'Too many failed attempts. Please wait 15 minutes and try again.');
}
function failed($key) {
    $a = q_get('SELECT n, until FROM login_attempts WHERE k = ?', array($key));
    $n = $a && (int) $a['until'] > time() ? (int) $a['n'] + 1 : 1;
    q_run('INSERT INTO login_attempts (k, n, until) VALUES (?,?,?) ON CONFLICT(k) DO UPDATE SET n = excluded.n, until = excluded.until', array($key, $n, time() + 900));
}
function valid_password($pw) {
    if (!is_string($pw) || strlen($pw) < 8) throw bad('Password must be at least 8 characters.');
    if (strlen($pw) > 128) throw bad('Password is too long.');
}
function valid_student_id($id) {
    if (!preg_match('/^[A-Za-z0-9._@-]{3,40}$/', (string) $id)) throw bad('Student ID must be 3–40 characters: letters, numbers, dot, dash, underscore.');
}

// ---------------------------------------------------------------- course data
function is_locked($mod, $settings, $user) {
    return $user['role'] !== 'admin' && $settings['lock_by_date'] === '1' && !empty($mod['release_date']) && $mod['release_date'] > gmdate('Y-m-d');
}
function course_for($user) {
    $admin = $user['role'] === 'admin';
    $settings = get_settings();
    $mods = array_values(array_filter(q_all('SELECT * FROM modules ORDER BY position, id'), function ($m) use ($admin) { return $admin || $m['published']; }));
    $lessons = q_all('SELECT id, module_id, title, position, video_type, est_minutes, published, LENGTH(body) AS body_len FROM lessons ORDER BY position, id');
    $done = array();
    foreach (q_all('SELECT lesson_id FROM progress WHERE user_id = ?', array($user['id'])) as $r) $done[(int) $r['lesson_id']] = true;
    $out = array();
    foreach ($mods as $m) {
        $ls = array();
        foreach ($lessons as $l) {
            if ((int) $l['module_id'] !== (int) $m['id'] || !($admin || $l['published'])) continue;
            $ls[] = array('id' => (int) $l['id'], 'title' => $l['title'], 'position' => (int) $l['position'], 'published' => (bool) $l['published'],
                'video' => $l['video_type'] !== 'none', 'has_text' => (int) $l['body_len'] > 0,
                'est_minutes' => $l['est_minutes'] === null ? null : (int) $l['est_minutes'], 'done' => isset($done[(int) $l['id']]));
        }
        $out[] = array('id' => (int) $m['id'], 'title' => $m['title'], 'summary' => $m['summary'], 'fact' => $m['fact'], 'learn' => $m['learn'],
            'topics' => $m['topics'], 'outputs' => $m['outputs'], 'position' => (int) $m['position'], 'published' => (bool) $m['published'],
            'release_date' => $m['release_date'], 'locked' => (bool) is_locked($m, $settings, $user), 'lessons' => $ls);
    }
    return $out;
}
function lesson_full($id, $user) {
    $l = q_get('SELECT * FROM lessons WHERE id = ?', array($id));
    if (!$l) throw new HttpError(404, 'Lesson not found');
    $m = q_get('SELECT * FROM modules WHERE id = ?', array($l['module_id']));
    $admin = $user['role'] === 'admin';
    if (!$admin && (!$l['published'] || !$m['published'])) throw new HttpError(404, 'Lesson not found');
    if (is_locked($m, get_settings(), $user)) throw new HttpError(403, 'This module unlocks on ' . $m['release_date'] . '.');
    $atts = q_all('SELECT id, name, file, size FROM attachments WHERE lesson_id = ? ORDER BY id', array($id));
    foreach ($atts as &$a) { $a['id'] = (int) $a['id']; $a['size'] = (int) $a['size']; } unset($a);
    return array('id' => (int) $l['id'], 'module_id' => (int) $l['module_id'], 'title' => $l['title'], 'position' => (int) $l['position'], 'body' => $l['body'],
        'video_type' => $l['video_type'], 'video_src' => $l['video_src'], 'est_minutes' => $l['est_minutes'] === null ? null : (int) $l['est_minutes'],
        'published' => (bool) $l['published'], 'updated_at' => $l['updated_at'], 'module_title' => $m['title'], 'attachments' => $atts);
}
function renumber($table, $where, $params) {
    $rows = q_all("SELECT id FROM $table $where ORDER BY position, id", $params);
    foreach ($rows as $i => $r) q_run("UPDATE $table SET position = ? WHERE id = ?", array($i + 1, $r['id']));
}
function move_item($table, $id, $dir, $where, $params) {
    renumber($table, $where, $params);
    $rows = q_all("SELECT id FROM $table $where ORDER BY position", $params);
    $i = -1; foreach ($rows as $k => $r) if ((int) $r['id'] === (int) $id) $i = $k;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i < 0 || $j < 0 || $j >= count($rows)) return;
    q_run("UPDATE $table SET position = ? WHERE id = ?", array($j + 1, $rows[$i]['id']));
    q_run("UPDATE $table SET position = ? WHERE id = ?", array($i + 1, $rows[$j]['id']));
}
function total_published_lessons() {
    return (int) q_get('SELECT COUNT(*) AS c FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.published = 1 AND m.published = 1')['c'];
}
function student_rows() {
    $total = total_published_lessons();
    $done = array();
    foreach (q_all('SELECT p.user_id, COUNT(*) AS c, MAX(p.completed_at) AS last FROM progress p JOIN lessons l ON l.id = p.lesson_id AND l.published = 1 JOIN modules m ON m.id = l.module_id AND m.published = 1 GROUP BY p.user_id') as $r) $done[(int) $r['user_id']] = $r;
    $out = array();
    foreach (q_all("SELECT id, student_id, name, email, active, must_change, created_at, last_login, last_active FROM users WHERE role = 'student' ORDER BY name COLLATE NOCASE") as $u) {
        $d = isset($done[(int) $u['id']]) ? $done[(int) $u['id']] : null; $c = $d ? (int) $d['c'] : 0;
        $out[] = array('id' => (int) $u['id'], 'student_id' => $u['student_id'], 'name' => $u['name'], 'email' => $u['email'], 'active' => (int) $u['active'],
            'must_change' => (int) $u['must_change'], 'created_at' => $u['created_at'], 'last_login' => $u['last_login'], 'last_active' => $u['last_active'],
            'completed' => $c, 'total' => $total, 'percent' => $total ? (int) round($c / $total * 100) : 0, 'last_completed' => $d ? $d['last'] : null);
    }
    return $out;
}
function remove_file($rel) {
    if (preg_match('~^/uploads/([a-f0-9]{16,}\.[a-z0-9]+)$~i', (string) $rel, $m)) @unlink(upload_dir() . '/' . $m[1]);
}
function csv_cell($v) { $v = (string) $v; return preg_match('/[",\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v; }

// ---------------------------------------------------------------- file serving (with Range support for video seeking)
function mime_for($ext) {
    $m = array('html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm', 'mov' => 'video/quicktime', 'pdf' => 'application/pdf');
    return isset($m[$ext]) ? $m[$ext] : null;
}
function serve_file($file, $download = null, $cache = 'no-cache') {
    if (!is_file($file)) throw new HttpError(404, 'Not found');
    $size = filesize($file); $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)); $mime = mime_for($ext);
    while (ob_get_level()) ob_end_clean();
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Accept-Ranges: bytes'); header('Cache-Control: ' . $cache); header('X-Content-Type-Options: nosniff');
    if ($download || !$mime) header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($download ?: basename($file)));
    $start = 0; $end = $size - 1; $status = 200;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] !== '') { $start = (int) $m[1]; if ($m[2] !== '') $end = min((int) $m[2], $end); } else { $start = max(0, $size - (int) $m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        $status = 206; header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    http_response_code($status);
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    @set_time_limit(0);
    $fh = fopen($file, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) { $chunk = fread($fh, min(1048576, $left)); if ($chunk === false) break; echo $chunk; $left -= strlen($chunk); flush(); }
    fclose($fh); exit;
}

// ---------------------------------------------------------------- plain-text error page for setup problems
function setup_page($msg) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup needed</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:0 1.25rem;line-height:1.6;color:#101B33">'
        . '<h1 style="color:#14275C">Almost there — one setup step</h1><p style="background:#E3ECFF;padding:1rem 1.25rem;border-radius:12px">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></body>';
    exit;
}
