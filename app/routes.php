<?php
// All API routes. Same URLs, inputs and outputs as the Node edition, so the web app works unchanged.

$ROUTES = array();
function route($method, $pattern, $opts, $fn) {
    global $ROUTES;
    $re = '~^' . preg_replace('~:(\w+)~', '(?P<$1>[^/]+)', $pattern) . '$~';
    $ROUTES[] = array($method, $re, $opts, $fn);
}
const AUTH = 1; const ADMIN = 2; const OPEN = 0;

function uid_of($u) { return (int) $u['id']; }

// ---------------------------------------------------------------- public
route('GET', '/api/brand', OPEN, function ($c) { send_json(get_settings()); });
route('POST', '/api/login', OPEN, function ($c) {
    $b = read_json(); $sid = s(isset($b['student_id']) ? $b['student_id'] : '', 60); $pw = (string) (isset($b['password']) ? $b['password'] : '');
    $ip = client_ip(); $key = $ip . '|' . strtolower($sid); $gkey = $ip . '|*';
    q_run('DELETE FROM login_attempts WHERE until < ?', array(time()));
    throttle($key); throttle($gkey);
    $u = $sid !== '' ? q_get('SELECT * FROM users WHERE student_id = ?', array($sid)) : null;
    if (!$u || !$u['active'] || !verify_pw($pw, $u['password_hash'])) {
        if (!$u) verify_pw($pw, '$2y$10$abcdefghijklmnopqrstuuK1m3E8vKkzv0b2q0p0y0y0y0y0y0y0y'); // even out timing
        failed($key); failed($gkey);
        throw new HttpError(401, 'Incorrect Student ID or password.');
    }
    q_run('DELETE FROM login_attempts WHERE k = ?', array($key));
    q_run('DELETE FROM sessions WHERE expires_at < ?', array(now()));
    create_session($u['id']);
    q_run('UPDATE users SET last_login = ?, last_active = ? WHERE id = ?', array(now(), now(), $u['id']));
    send_json(array('user' => public_user($u)));
});
route('POST', '/api/logout', OPEN, function ($c) {
    $t = isset($_COOKIE['dsid']) ? (string) $_COOKIE['dsid'] : '';
    if ($t !== '') q_run('DELETE FROM sessions WHERE token_hash = ?', array(hash('sha256', $t)));
    session_cookie('', 0);
    send_json(array('ok' => true));
});
route('GET', '/api/me', OPEN, function ($c) { $u = $c['user']; send_json(array('user' => $u ? public_user($u) : null)); });

// ---------------------------------------------------------------- student
route('GET', '/api/course', AUTH, function ($c) {
    $ann = q_all('SELECT id, title, body, pinned, created_at FROM announcements ORDER BY pinned DESC, id DESC LIMIT 20');
    foreach ($ann as &$a) { $a['id'] = (int) $a['id']; $a['pinned'] = (int) $a['pinned']; } unset($a);
    send_json(array('modules' => course_for($c['user']), 'announcements' => $ann, 'last_lesson_id' => $c['user']['last_lesson_id'] === null ? null : (int) $c['user']['last_lesson_id']));
});
route('GET', '/api/lessons/:id', AUTH, function ($c) {
    $l = lesson_full((int) $c['params']['id'], $c['user']);
    $ids = array();
    foreach (course_for($c['user']) as $m) { if ($m['locked']) continue; foreach ($m['lessons'] as $x) $ids[] = $x['id']; }
    $i = array_search($l['id'], $ids, true); $i = $i === false ? -1 : $i;
    q_run('UPDATE users SET last_lesson_id = ? WHERE id = ?', array($l['id'], $c['user']['id']));
    $done = (bool) q_get('SELECT 1 AS x FROM progress WHERE user_id = ? AND lesson_id = ?', array($c['user']['id'], $l['id']));
    send_json(array('lesson' => $l, 'done' => $done, 'prev' => $i > 0 ? $ids[$i - 1] : null, 'next' => ($i >= 0 && $i < count($ids) - 1) ? $ids[$i + 1] : null));
});
route('POST', '/api/lessons/:id/complete', AUTH, function ($c) {
    $b = read_json(); $l = lesson_full((int) $c['params']['id'], $c['user']);
    if (array_key_exists('done', $b) && $b['done'] === false) q_run('DELETE FROM progress WHERE user_id = ? AND lesson_id = ?', array($c['user']['id'], $l['id']));
    else q_run('INSERT OR IGNORE INTO progress (user_id, lesson_id) VALUES (?,?)', array($c['user']['id'], $l['id']));
    send_json(array('ok' => true));
});
route('POST', '/api/me/password', AUTH, function ($c) {
    $u = $c['user']; $b = read_json();
    if (!verify_pw(isset($b['current']) ? $b['current'] : '', $u['password_hash'])) throw new HttpError(403, 'Your current password is incorrect.');
    $new = isset($b['new']) ? $b['new'] : ''; valid_password($new);
    if ($new === (isset($b['current']) ? $b['current'] : '')) throw bad('Choose a password different from the current one.');
    q_run('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?', array(hash_pw($new), $u['id']));
    q_run('DELETE FROM sessions WHERE user_id = ? AND token_hash != ?', array($u['id'], hash('sha256', (string) $_COOKIE['dsid']))); // sign out other devices
    audit($u['student_id'], 'password_change');
    send_json(array('ok' => true));
});
route('POST', '/api/me/student-id', AUTH, function ($c) {
    $u = $c['user']; $b = read_json();
    if ($u['role'] !== 'admin' && get_settings()['allow_id_change'] !== '1') throw new HttpError(403, 'Changing your Student ID is turned off. Please contact your coordinator.');
    if (!verify_pw(isset($b['current']) ? $b['current'] : '', $u['password_hash'])) throw new HttpError(403, 'Your current password is incorrect.');
    $id = s(isset($b['student_id']) ? $b['student_id'] : '', 40); valid_student_id($id);
    if (q_get('SELECT 1 AS x FROM users WHERE student_id = ? AND id != ?', array($id, $u['id']))) throw bad('That Student ID is already taken.');
    q_run('UPDATE users SET student_id = ? WHERE id = ?', array($id, $u['id']));
    audit($u['student_id'], 'student_id_change', '-> ' . $id);
    send_json(array('ok' => true, 'student_id' => $id));
});
route('POST', '/api/me/profile', AUTH, function ($c) {
    $b = read_json(); $name = s(isset($b['name']) ? $b['name'] : '', 100);
    if ($name === '') throw bad('Name cannot be empty.');
    q_run('UPDATE users SET name = ?, email = ? WHERE id = ?', array($name, s(isset($b['email']) ? $b['email'] : '', 200), $c['user']['id']));
    send_json(array('ok' => true));
});

// ---------------------------------------------------------------- admin: overview
route('GET', '/api/admin/overview', ADMIN, function ($c) {
    $students = student_rows();
    $weekAgo = gmdate('Y-m-d H:i:s', time() - 7 * 86400); $twoWeeks = gmdate('Y-m-d H:i:s', time() - 14 * 86400);
    $active = array_values(array_filter($students, function ($s) { return $s['active']; }));
    $avg = count($active) ? (int) round(array_sum(array_map(function ($s) { return $s['percent']; }, $active)) / count($active)) : 0;
    $top = $active; usort($top, function ($a, $b) { return $b['percent'] <=> $a['percent']; });
    $recent = q_all('SELECT at, actor, action, detail FROM audit ORDER BY id DESC LIMIT 12');
    send_json(array(
        'students' => count($students),
        'active_week' => count(array_filter($active, function ($s) use ($weekAgo) { return $s['last_active'] && $s['last_active'] > $weekAgo; })),
        'avg_progress' => $avg,
        'completed' => count(array_filter($active, function ($s) { return $s['percent'] === 100 && $s['total'] > 0; })),
        'modules' => (int) q_get('SELECT COUNT(*) AS c FROM modules')['c'], 'lessons' => (int) q_get('SELECT COUNT(*) AS c FROM lessons')['c'],
        'drafts' => (int) q_get('SELECT COUNT(*) AS c FROM lessons WHERE published = 0')['c'],
        'inactive' => array_slice(array_values(array_filter($active, function ($s) use ($twoWeeks) { return !$s['last_active'] || $s['last_active'] < $twoWeeks; })), 0, 8),
        'top' => array_slice($top, 0, 5), 'recent' => $recent,
    ));
});

// ---------------------------------------------------------------- admin: content
route('GET', '/api/admin/course', ADMIN, function ($c) { send_json(array('modules' => course_for($c['user']))); });
route('POST', '/api/admin/modules', ADMIN, function ($c) {
    $b = read_json(); $title = s(isset($b['title']) ? $b['title'] : '', 200); if ($title === '') throw bad('Module title is required.');
    $pos = (int) q_get('SELECT COALESCE(MAX(position),0) + 1 AS p FROM modules')['p'];
    q_run('INSERT INTO modules (title, summary, fact, learn, topics, outputs, position, published, release_date) VALUES (?,?,?,?,?,?,?,?,?)', array(
        $title, s(bval($b, 'summary', ''), 2000), s(bval($b, 'fact', ''), 400), s(bval($b, 'learn', ''), 3000), s(bval($b, 'topics', ''), 6000), s(bval($b, 'outputs', ''), 3000),
        $pos, flag(bval($b, 'published', 1)), date_or_null(bval($b, 'release_date'))));
    $id = q_id(); audit($c['user']['student_id'], 'module_add', $title);
    send_json(array('id' => $id));
});
route('PUT', '/api/admin/modules/:id', ADMIN, function ($c) {
    $b = read_json(); $m = q_get('SELECT * FROM modules WHERE id = ?', array((int) $c['params']['id']));
    if (!$m) throw new HttpError(404, 'Module not found');
    $title = s(bval($b, 'title', $m['title']), 200); if ($title === '') throw bad('Module title is required.');
    q_run('UPDATE modules SET title=?, summary=?, fact=?, learn=?, topics=?, outputs=?, published=?, release_date=? WHERE id=?', array(
        $title,
        array_key_exists('summary', $b) ? s($b['summary'], 2000) : $m['summary'],
        array_key_exists('fact', $b) ? s($b['fact'], 400) : $m['fact'],
        array_key_exists('learn', $b) ? s($b['learn'], 3000) : $m['learn'],
        array_key_exists('topics', $b) ? s($b['topics'], 6000) : $m['topics'],
        array_key_exists('outputs', $b) ? s($b['outputs'], 3000) : $m['outputs'],
        array_key_exists('published', $b) ? flag($b['published']) : $m['published'],
        array_key_exists('release_date', $b) ? date_or_null($b['release_date']) : $m['release_date'],
        $m['id']));
    audit($c['user']['student_id'], 'module_edit', $title);
    send_json(array('ok' => true));
});
route('POST', '/api/admin/modules/:id/move', ADMIN, function ($c) {
    $b = read_json(); move_item('modules', (int) $c['params']['id'], (isset($b['dir']) && $b['dir'] === 'up') ? 'up' : 'down', '', array());
    send_json(array('ok' => true));
});
route('DELETE', '/api/admin/modules/:id', ADMIN, function ($c) {
    $id = (int) $c['params']['id']; $m = q_get('SELECT title FROM modules WHERE id = ?', array($id));
    if (!$m) throw new HttpError(404, 'Module not found');
    foreach (q_all('SELECT id, video_src, video_type FROM lessons WHERE module_id = ?', array($id)) as $l) {
        if ($l['video_type'] === 'upload') remove_file($l['video_src']);
        foreach (q_all('SELECT file FROM attachments WHERE lesson_id = ?', array($l['id'])) as $a) remove_file($a['file']);
    }
    q_run('DELETE FROM modules WHERE id = ?', array($id));
    audit($c['user']['student_id'], 'module_delete', $m['title']);
    send_json(array('ok' => true));
});
route('POST', '/api/admin/lessons', ADMIN, function ($c) {
    $b = read_json(); $title = s(isset($b['title']) ? $b['title'] : '', 200); if ($title === '') throw bad('Lesson title is required.');
    $mid = (int) bval($b, 'module_id', 0); if (!q_get('SELECT 1 AS x FROM modules WHERE id = ?', array($mid))) throw bad('Choose a module.');
    $pos = (int) q_get('SELECT COALESCE(MAX(position),0) + 1 AS p FROM lessons WHERE module_id = ?', array($mid))['p'];
    q_run('INSERT INTO lessons (module_id, title, position, published) VALUES (?,?,?,0)', array($mid, $title, $pos));
    $id = q_id(); audit($c['user']['student_id'], 'lesson_add', $title);
    send_json(array('id' => $id));
});
route('GET', '/api/admin/lessons/:id', ADMIN, function ($c) { send_json(array('lesson' => lesson_full((int) $c['params']['id'], $c['user']))); });
route('PUT', '/api/admin/lessons/:id', ADMIN, function ($c) {
    $b = read_json(4194304); $l = q_get('SELECT * FROM lessons WHERE id = ?', array((int) $c['params']['id']));
    if (!$l) throw new HttpError(404, 'Lesson not found');
    $title = s(isset($b['title']) ? $b['title'] : '', 200); if ($title === '') throw bad('Lesson title is required.');
    $mid = (int) bval($b, 'module_id', $l['module_id']); if (!q_get('SELECT 1 AS x FROM modules WHERE id = ?', array($mid))) throw bad('Choose a module.');
    list($vtype, $vsrc) = parse_video(isset($b['video']) ? $b['video'] : '');
    if ($l['video_type'] === 'upload' && $l['video_src'] !== $vsrc) remove_file($l['video_src']);
    $estRaw = bval($b, 'est_minutes'); $est = ($estRaw === '' || $estRaw === null) ? null : max(0, min(9999, (int) $estRaw));
    tx(function () use ($l, $mid, $title, $b, $vtype, $vsrc, $est) {
        $pos = (int) $l['position'];
        if ($mid !== (int) $l['module_id']) $pos = (int) q_get('SELECT COALESCE(MAX(position),0) + 1 AS p FROM lessons WHERE module_id = ?', array($mid))['p'];
        q_run('UPDATE lessons SET module_id=?, title=?, position=?, body=?, video_type=?, video_src=?, est_minutes=?, published=?, updated_at=? WHERE id=?',
            array($mid, $title, $pos, sanitize_html(bval($b, 'body', '')), $vtype, $vsrc, $est, flag(bval($b, 'published')), now(), $l['id']));
        if ($mid !== (int) $l['module_id']) renumber('lessons', 'WHERE module_id = ?', array($l['module_id']));
    });
    audit($c['user']['student_id'], 'lesson_edit', $title);
    send_json(array('ok' => true));
});
route('POST', '/api/admin/lessons/:id/move', ADMIN, function ($c) {
    $b = read_json(); $l = q_get('SELECT module_id FROM lessons WHERE id = ?', array((int) $c['params']['id']));
    if (!$l) throw new HttpError(404, 'Lesson not found');
    move_item('lessons', (int) $c['params']['id'], (isset($b['dir']) && $b['dir'] === 'up') ? 'up' : 'down', 'WHERE module_id = ?', array($l['module_id']));
    send_json(array('ok' => true));
});
route('DELETE', '/api/admin/lessons/:id', ADMIN, function ($c) {
    $id = (int) $c['params']['id']; $l = q_get('SELECT title, video_src, video_type FROM lessons WHERE id = ?', array($id));
    if (!$l) throw new HttpError(404, 'Lesson not found');
    if ($l['video_type'] === 'upload') remove_file($l['video_src']);
    foreach (q_all('SELECT file FROM attachments WHERE lesson_id = ?', array($id)) as $a) remove_file($a['file']);
    q_run('DELETE FROM lessons WHERE id = ?', array($id));
    audit($c['user']['student_id'], 'lesson_delete', $l['title']);
    send_json(array('ok' => true));
});
route('POST', '/api/admin/lessons/:id/attachments', ADMIN, function ($c) {
    $b = read_json(); $id = (int) $c['params']['id'];
    if (!q_get('SELECT 1 AS x FROM lessons WHERE id = ?', array($id))) throw new HttpError(404, 'Lesson not found');
    if (!preg_match('~^/uploads/[a-f0-9]{16,}\.[a-z0-9]+$~i', (string) bval($b, 'file', ''))) throw bad('Bad file reference.');
    q_run('INSERT INTO attachments (lesson_id, name, file, size) VALUES (?,?,?,?)', array($id, s(bval($b, 'name', ''), 200) ?: 'File', $b['file'], (int) bval($b, 'size', 0)));
    send_json(array('id' => q_id()));
});
route('DELETE', '/api/admin/attachments/:id', ADMIN, function ($c) {
    $a = q_get('SELECT file FROM attachments WHERE id = ?', array((int) $c['params']['id']));
    if ($a) { remove_file($a['file']); q_run('DELETE FROM attachments WHERE id = ?', array((int) $c['params']['id'])); }
    send_json(array('ok' => true));
});

// ---------------------------------------------------------------- admin: uploads (raw body streamed to disk)
route('POST', '/api/admin/upload', ADMIN, function ($c) {
    $kinds = array(
        'video' => array('mp4', 'webm', 'mov', 'm4v'), 'image' => array('png', 'jpg', 'jpeg', 'gif', 'webp'), 'logo' => array('png', 'jpg', 'jpeg', 'webp'),
        'file' => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'png', 'jpg', 'jpeg'));
    $kind = isset($_GET['kind']) ? (string) $_GET['kind'] : 'file'; $orig = s(isset($_GET['name']) ? $_GET['name'] : '', 200) ?: 'file';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset($kinds[$kind]) || !in_array($ext, $kinds[$kind], true)) throw bad('That file type is not allowed here. Allowed: .' . implode(', .', isset($kinds[$kind]) ? $kinds[$kind] : array()));
    $max = (int) cfg('max_upload_mb') * 1048576;
    $len = (int) (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0);
    if ($len <= 0) throw bad('Empty upload.');
    if ($len > $max) throw new HttpError(413, 'File is too large (limit ' . round($max / 1048576) . ' MB). For big videos, upload to YouTube (unlisted) and paste the link instead.');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = ($kind === 'logo' ? media_dir() : upload_dir()) . '/' . $name;
    @set_time_limit(0);
    $in = fopen('php://input', 'rb'); $out = fopen($dest, 'wb'); $size = 0;
    if (!$in || !$out) throw new HttpError(500, 'Could not save the file. Check that the data folder is writable.');
    while (!feof($in)) {
        $chunk = fread($in, 1048576); if ($chunk === false || $chunk === '') break;
        $size += strlen($chunk);
        if ($size > $max) { fclose($out); fclose($in); @unlink($dest); throw new HttpError(413, 'File too large'); }
        fwrite($out, $chunk);
    }
    fclose($in); fclose($out);
    if ($size === 0) { @unlink($dest); throw bad('Empty upload.'); }
    if ($len > 0 && $size < $len) { @unlink($dest); throw new HttpError(413, 'The upload was cut short (the host may limit upload size). For big videos, use a YouTube (unlisted) link instead.'); }
    send_json(array('url' => ($kind === 'logo' ? '/media/' : '/uploads/') . $name, 'name' => $orig, 'size' => $size));
});

// ---------------------------------------------------------------- admin: students
route('GET', '/api/admin/students', ADMIN, function ($c) { send_json(array('students' => student_rows())); });
route('POST', '/api/admin/students', ADMIN, function ($c) {
    $b = read_json(); $sid = s(bval($b, 'student_id', ''), 40); $name = s(bval($b, 'name', ''), 100); valid_student_id($sid);
    if ($name === '') throw bad('Name is required.');
    if (q_get('SELECT 1 AS x FROM users WHERE student_id = ?', array($sid))) throw bad('That Student ID already exists.');
    $pw = !empty($b['password']) ? (string) $b['password'] : random_password(); valid_password($pw);
    q_run('INSERT INTO users (student_id, name, email, password_hash, must_change) VALUES (?,?,?,?,?)', array($sid, $name, s(bval($b, 'email', ''), 200), hash_pw($pw), flag(bval($b, 'must_change', 1))));
    $id = q_id(); audit($c['user']['student_id'], 'student_add', $sid);
    send_json(array('id' => $id, 'student_id' => $sid, 'password' => $pw));
});
route('POST', '/api/admin/students/import', ADMIN, function ($c) {
    $b = read_json(); $results = array(); $rows = isset($b['rows']) && is_array($b['rows']) ? array_slice($b['rows'], 0, 1000) : array();
    foreach ($rows as $row) {
        $sid = s(bval($row, 'student_id', ''), 40); $name = s(bval($row, 'name', ''), 100);
        try {
            valid_student_id($sid); if ($name === '') throw bad('Name is required.');
            if (q_get('SELECT 1 AS x FROM users WHERE student_id = ?', array($sid))) throw bad('Student ID already exists.');
            $pw = !empty($row['password']) ? (string) $row['password'] : random_password(); valid_password($pw);
            q_run('INSERT INTO users (student_id, name, email, password_hash, must_change) VALUES (?,?,?,?,1)', array($sid, $name, s(bval($row, 'email', ''), 200), hash_pw($pw)));
            $results[] = array('student_id' => $sid, 'name' => $name, 'password' => $pw, 'ok' => true);
        } catch (HttpError $e) { $results[] = array('student_id' => $sid, 'name' => $name, 'ok' => false, 'error' => $e->getMessage()); }
    }
    $n = count(array_filter($results, function ($r) { return $r['ok']; }));
    audit($c['user']['student_id'], 'student_import', $n . ' added');
    send_json(array('results' => $results));
});
route('GET', '/api/admin/students/:id', ADMIN, function ($c) {
    $u = q_get("SELECT id, student_id, name, email, active, must_change, created_at, last_login, last_active FROM users WHERE id = ? AND role = 'student'", array((int) $c['params']['id']));
    if (!$u) throw new HttpError(404, 'Student not found');
    $done = array(); foreach (q_all('SELECT lesson_id, completed_at FROM progress WHERE user_id = ?', array($u['id'])) as $r) $done[(int) $r['lesson_id']] = $r['completed_at'];
    $mods = course_for(array('id' => $u['id'], 'role' => 'student'));
    $total = 0; $n = 0;
    foreach ($mods as &$m) { foreach ($m['lessons'] as &$l) { $l['completed_at'] = isset($done[$l['id']]) ? $done[$l['id']] : null; $total++; if ($l['done']) $n++; } unset($l); } unset($m);
    $u['id'] = (int) $u['id']; $u['active'] = (int) $u['active']; $u['must_change'] = (int) $u['must_change'];
    send_json(array('student' => $u, 'modules' => $mods, 'completed' => $n, 'total' => $total, 'percent' => $total ? (int) round($n / $total * 100) : 0));
});
route('PUT', '/api/admin/students/:id', ADMIN, function ($c) {
    $b = read_json(); $u = q_get("SELECT * FROM users WHERE id = ? AND role = 'student'", array((int) $c['params']['id']));
    if (!$u) throw new HttpError(404, 'Student not found');
    $sid = s(bval($b, 'student_id', $u['student_id']), 40); $name = s(bval($b, 'name', $u['name']), 100); valid_student_id($sid);
    if ($name === '') throw bad('Name is required.');
    if (q_get('SELECT 1 AS x FROM users WHERE student_id = ? AND id != ?', array($sid, $u['id']))) throw bad('That Student ID already exists.');
    q_run('UPDATE users SET student_id=?, name=?, email=?, active=? WHERE id=?', array($sid, $name, s(bval($b, 'email', $u['email']), 200), array_key_exists('active', $b) ? flag($b['active']) : $u['active'], $u['id']));
    if (array_key_exists('active', $b) && !$b['active']) q_run('DELETE FROM sessions WHERE user_id = ?', array($u['id']));
    audit($c['user']['student_id'], 'student_edit', $sid);
    send_json(array('ok' => true));
});
route('POST', '/api/admin/students/:id/reset-password', ADMIN, function ($c) {
    $b = read_json(); $u = q_get("SELECT * FROM users WHERE id = ? AND role = 'student'", array((int) $c['params']['id']));
    if (!$u) throw new HttpError(404, 'Student not found');
    $pw = !empty($b['password']) ? (string) $b['password'] : random_password(); valid_password($pw);
    q_run('UPDATE users SET password_hash = ?, must_change = ? WHERE id = ?', array(hash_pw($pw), flag(bval($b, 'must_change', 1)), $u['id']));
    q_run('DELETE FROM sessions WHERE user_id = ?', array($u['id']));
    audit($c['user']['student_id'], 'password_reset', $u['student_id']);
    send_json(array('student_id' => $u['student_id'], 'password' => $pw));
});
route('DELETE', '/api/admin/students/:id', ADMIN, function ($c) {
    $u = q_get("SELECT student_id FROM users WHERE id = ? AND role = 'student'", array((int) $c['params']['id']));
    if (!$u) throw new HttpError(404, 'Student not found');
    q_run('DELETE FROM users WHERE id = ?', array((int) $c['params']['id']));
    audit($c['user']['student_id'], 'student_delete', $u['student_id']);
    send_json(array('ok' => true));
});
route('GET', '/api/admin/export.csv', ADMIN, function ($c) {
    $lines = array('Student ID,Name,Email,Active,Lessons completed,Total lessons,Progress %,Last login,Last active');
    foreach (student_rows() as $s) $lines[] = implode(',', array_map('csv_cell', array($s['student_id'], $s['name'], $s['email'], $s['active'] ? 'yes' : 'no', $s['completed'], $s['total'], $s['percent'], $s['last_login'] ?: '', $s['last_active'] ?: '')));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="student-progress.csv"'); header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines); exit;
});

// ---------------------------------------------------------------- admin: announcements & settings
route('POST', '/api/admin/announcements', ADMIN, function ($c) {
    $b = read_json(); $title = s(bval($b, 'title', ''), 200); if ($title === '') throw bad('Title is required.');
    q_run('INSERT INTO announcements (title, body, pinned) VALUES (?,?,?)', array($title, s(bval($b, 'body', ''), 5000), flag(bval($b, 'pinned'))));
    send_json(array('id' => q_id()));
});
route('PUT', '/api/admin/announcements/:id', ADMIN, function ($c) {
    $b = read_json(); $title = s(bval($b, 'title', ''), 200); if ($title === '') throw bad('Title is required.');
    q_run('UPDATE announcements SET title=?, body=?, pinned=? WHERE id=?', array($title, s(bval($b, 'body', ''), 5000), flag(bval($b, 'pinned')), (int) $c['params']['id']));
    send_json(array('ok' => true));
});
route('DELETE', '/api/admin/announcements/:id', ADMIN, function ($c) { q_run('DELETE FROM announcements WHERE id = ?', array((int) $c['params']['id'])); send_json(array('ok' => true)); });
route('PUT', '/api/admin/settings', ADMIN, function ($c) {
    $b = read_json(); $defaults = default_settings();
    foreach ($defaults as $k => $def) {
        if (!array_key_exists($k, $b)) continue;
        $v = s($b[$k], 500);
        if (strpos($k, 'color_') === 0 && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) throw bad('Colors must be a hex value like #14275C.');
        if ($k === 'logo_url' && $v !== '' && !preg_match('~^(/assets/|/media/)[\w.-]+$~', $v)) throw bad('Bad logo path.');
        if ($k === 'lock_by_date' || $k === 'allow_id_change') $v = truthy($b[$k]) ? '1' : '0';
        set_setting($k, $v !== '' ? $v : $def);
    }
    audit($c['user']['student_id'], 'settings_update');
    send_json(get_settings());
});
