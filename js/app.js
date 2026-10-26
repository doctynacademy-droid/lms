/* Doctyn Academy FCCM LMS — single-page app (student portal + admin console). No build step. */
'use strict';
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const S = { me: null, brand: null, course: null };
// Mount point: '' at a domain root, '/lms' when served at yourdomain.com/lms/
const BASE = location.pathname.replace(/\/[^/]*$/, '');
const U = (p) => (p && p[0] === '/' && p[1] !== '/' ? BASE + p : p);
const fixIn = (h) => String(h || '').replace(/(src|href)="\/(uploads|media|assets)\//g, '$1="' + BASE + '/$2/');
const fixOut = (h) => (BASE ? String(h || '').split('="' + BASE + '/uploads/').join('="/uploads/').split('="' + BASE + '/media/').join('="/media/') : String(h || ''));

/* ------------------------------------------------------------ utilities */
async function api(method, url, body) {
  // Some hosts block PUT/DELETE; send them as POST with an override header that both servers understand.
  const wire = method === 'PUT' || method === 'DELETE' ? 'POST' : method;
  const hdrs = { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }; if (wire !== method) hdrs['X-HTTP-Method-Override'] = method;
  const res = await fetch(BASE + url, { method: wire, headers: hdrs, body: body === undefined ? undefined : JSON.stringify(body) });
  let data = {}; try { data = await res.json(); } catch { /* not json */ }
  if (res.status === 401 && S.me && !url.includes('/api/login')) { S.me = null; go('#/'); render(); throw new Error('Your session expired. Please sign in again.'); }
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}
function toast(msg, err) {
  const t = document.createElement('div'); t.className = 'toast' + (err ? ' err' : ''); t.textContent = msg;
  $('#toasts').append(t); setTimeout(() => t.remove(), err ? 6000 : 3200);
}
const fail = (e) => toast(e.message || String(e), true);
const go = (h) => { if (location.hash === h) render(); else location.hash = h; };
function fmtDate(d, withTime) {
  if (!d) return '—';
  const dt = new Date(d.length <= 10 ? d + 'T00:00:00' : d.replace(' ', 'T') + 'Z');
  return dt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) + (withTime ? ' ' + dt.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '');
}
const ago = (d) => {
  if (!d) return 'Never';
  const s = (Date.now() - new Date(d.replace(' ', 'T') + 'Z')) / 1000;
  if (s < 90) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago';
};
const bar = (p) => `<div class="bar-track"><div class="bar-fill ${p >= 100 ? 'full' : ''}" style="width:${Math.min(100, p)}%"></div></div>`;
const initials = (n) => n.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
async function copyText(t) { try { await navigator.clipboard.writeText(t); toast('Copied to clipboard'); } catch { toast('Copy failed — select the text and copy manually', true); } }

function modal(title, html, { wide = false, onMount } = {}) {
  const bg = document.createElement('div'); bg.className = 'modal-bg';
  bg.innerHTML = `<div class="modal ${wide ? 'wide' : ''}" role="dialog" aria-modal="true"><h2>${esc(title)}</h2>${html}</div>`;
  const close = () => bg.remove();
  bg.addEventListener('mousedown', (e) => { if (e.target === bg) close(); });
  $('#modal-root').append(bg);
  const f = $('input, textarea, select', bg); if (f) f.focus();
  if (onMount) onMount($('.modal', bg), close);
  return { el: bg, close };
}
function confirmBox(message, okLabel = 'Delete') {
  return new Promise((resolve) => {
    const m = modal('Are you sure?', `<p>${esc(message)}</p><div class="foot"><button class="btn ghost" data-x="0">Cancel</button><button class="btn danger" data-x="1">${esc(okLabel)}</button></div>`);
    m.el.addEventListener('click', (e) => { const b = e.target.closest('[data-x]'); if (b) { m.close(); resolve(b.dataset.x === '1'); } });
  });
}
function upload(file, kind, onProgress) {
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();
    x.open('POST', BASE + `/api/admin/upload?kind=${kind}&name=${encodeURIComponent(file.name)}`);
    x.setRequestHeader('X-Requested-With', 'fetch');
    x.upload.onprogress = (e) => e.lengthComputable && onProgress && onProgress(e.loaded / e.total);
    x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch { try { d = JSON.parse((x.responseText.match(/\{[\s\S]*\}\s*$/) || ['{}'])[0]); } catch { /* */ } } x.status === 200 ? resolve(d) : reject(new Error(d.error || 'Upload failed')); };
    x.onerror = () => reject(new Error('Upload failed — check your connection.'));
    x.send(file);
  });
}
function pickFile(accept) {
  return new Promise((resolve) => {
    const i = document.createElement('input'); i.type = 'file'; i.accept = accept || '';
    i.onchange = () => resolve(i.files[0] || null); i.click();
  });
}
function applyBrand(b) {
  S.brand = b;
  document.documentElement.style.setProperty('--primary', b.color_primary);
  document.documentElement.style.setProperty('--accent', b.color_accent);
  document.title = `${b.brand_name} — Learning Portal`;
  const ic = $('link[rel=icon]'); if (ic) ic.href = U(b.logo_url);
}
function videoHtml(l) {
  if (!l.video_src || l.video_type === 'none') return '';
  const inner = ['upload', 'file'].includes(l.video_type)
    ? `<video controls controlsList="nodownload" preload="metadata" src="${esc(U(l.video_src))}"></video>`
    : `<iframe src="${esc(U(l.video_src))}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" title="Lesson video"></iframe>`;
  return `<div class="video">${inner}</div>`;
}
const fmtSize = (n) => (n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');
// The certificate needs EVERY published module finished; a module with no lessons yet counts as unfinished.
function certStatus() {
  const mods = (S.course || []).filter((m) => m.published);
  const finished = mods.filter((m) => { const pl = m.lessons.filter((l) => l.published); return pl.length && pl.every((l) => l.done); }).length;
  return { finished, total: mods.length, ok: mods.length > 0 && finished === mods.length };
}
const lines = (s) => String(s || '').split('\n').map((x) => x.trim()).filter(Boolean);
// "Available on October 3" for modules whose release date is still in the future
const todayStr = () => new Date().toISOString().slice(0, 10);
const availableOn = (m) => (m.release_date && m.release_date > todayStr()
  ? '📅 Available on ' + new Date(m.release_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric' }) : null);
const splitTitle = (t, i) => { const p = t.split(/\s[–-]\s/); return p.length > 1 ? { label: p[0], name: p.slice(1).join(' – ') } : { label: 'Module ' + (i + 1), name: t }; };
const allLessons = () => (S.course || []).flatMap((m) => m.lessons.map((l) => ({ ...l, module: m })));

/* ------------------------------------------------------------ shell */
function shell(active, content, sub) {
  const admin = S.me.role === 'admin';
  const nav = [['#/', 'My Programs', 'home'], ['#/account', 'My Account', 'account']];
  if (admin) nav.push(['#/admin', 'Admin', 'admin']);
  $('#app').innerHTML = `
    <header class="topbar"><div class="in">
      <a class="brand" href="#/"><img src="${esc(U(S.brand.logo_url))}" alt=""><span>${esc(S.brand.brand_name)}</span></a>
      <nav class="nav">${nav.map(([h, t, k]) => `<a href="${h}" class="${active === k ? 'on' : ''}">${t}</a>`).join('')}</nav>
      <div class="who"><div class="avatar">${esc(initials(S.me.name))}</div><span class="nm">${esc(S.me.name)}</span><button class="btn ghost sm" id="logout">Sign out</button></div>
    </div></header>
    <main class="wrap">${sub || ''}${content}</main>`;
  $('#logout').onclick = async () => { await api('POST', '/api/logout'); S.me = null; S.course = null; go('#/'); render(); };
}
const adminTabs = (a) => `<div class="subnav">${[['#/admin', 'Overview', 'ov'], ['#/admin/content', 'Content', 'content'], ['#/admin/students', 'Students', 'students'], ['#/admin/announcements', 'Announcements', 'ann'], ['#/admin/brand', 'Branding & Settings', 'brand']]
  .map(([h, t, k]) => `<a href="${h}" class="${a === k ? 'on' : ''}">${t}</a>`).join('')}</div>`;

/* ------------------------------------------------------------ login & forced password change */
function loginView() {
  const b = S.brand;
  $('#app').innerHTML = `<div class="login">
    <section class="side"><div class="logo"><img src="${esc(U(b.logo_url))}" alt="${esc(b.brand_name)}"></div>
      <h1>${esc(b.brand_name)}</h1><div class="bar"></div><p>${esc(b.tagline)}</p></section>
    <section class="form"><form id="f" autocomplete="on">
      <h2>Sign in to your portal</h2><p class="muted small">Use the Student ID and password given to you by the Academy.</p>
      <div id="err" class="error hidden" style="margin-bottom:1rem"></div>
      <div class="field"><label for="sid">Student ID</label><input id="sid" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
      <div class="field"><label for="pw">Password</label><div class="pw"><input id="pw" type="password" autocomplete="current-password" required><button type="button" id="show">Show</button></div></div>
      <button class="btn" style="width:100%;justify-content:center;padding:.8rem" id="go">Sign in</button>
      <p class="hint" style="margin-top:1rem">${esc(b.support_text)}</p>
    </form></section></div>`;
  $('#show').onclick = () => { const p = $('#pw'); p.type = p.type === 'password' ? 'text' : 'password'; $('#show').textContent = p.type === 'password' ? 'Show' : 'Hide'; };
  $('#f').onsubmit = async (e) => {
    e.preventDefault(); const btn = $('#go'); btn.disabled = true; $('#err').classList.add('hidden');
    try { const r = await api('POST', '/api/login', { student_id: $('#sid').value, password: $('#pw').value }); S.me = r.user; go('#/'); render(); }
    catch (x) { $('#err').textContent = x.message; $('#err').classList.remove('hidden'); btn.disabled = false; }
  };
}
function forcePasswordView() {
  $('#app').innerHTML = `<div class="login"><section class="side"><div class="logo"><img src="${esc(U(S.brand.logo_url))}" alt=""></div><h1>One quick step</h1><div class="bar"></div><p>For your security, please replace the temporary password you were given with one only you know.</p></section>
  <section class="form"><form id="f"><h2>Set a new password</h2>
    <div id="err" class="error hidden" style="margin-bottom:1rem"></div>
    <div class="field"><label>Temporary password</label><input id="cur" type="password" autocomplete="current-password" required></div>
    <div class="field"><label>New password</label><input id="n1" type="password" autocomplete="new-password" minlength="8" required><div class="hint">At least 8 characters.</div></div>
    <div class="field"><label>Confirm new password</label><input id="n2" type="password" autocomplete="new-password" required></div>
    <button class="btn" style="width:100%;justify-content:center;padding:.8rem">Save and continue</button>
    <p style="text-align:center"><a href="#" id="lo">Sign out</a></p></form></section></div>`;
  $('#lo').onclick = async (e) => { e.preventDefault(); await api('POST', '/api/logout'); S.me = null; render(); };
  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    if ($('#n1').value !== $('#n2').value) { $('#err').textContent = 'The new passwords do not match.'; $('#err').classList.remove('hidden'); return; }
    try { await api('POST', '/api/me/password', { current: $('#cur').value, new: $('#n1').value }); S.me.must_change = false; toast('Password updated'); go('#/'); render(); }
    catch (x) { $('#err').textContent = x.message; $('#err').classList.remove('hidden'); }
  };
}

/* ------------------------------------------------------------ student: dashboard */
async function dashboardView() {
  const d = await api('GET', '/api/course'); S.course = d.modules;
  const ls = allLessons().filter((l) => !l.module.locked);
  const total = allLessons().filter((l) => l.module.published !== false && l.published).length;
  const done = allLessons().filter((l) => l.done && l.published).length;
  const cs = certStatus(), pct = cs.total ? Math.round((cs.finished / cs.total) * 100) : 0;
  const next = ls.find((l) => !l.done && l.published) || null;
  const modsDone = cs.finished;
  const cta = cs.ok ? `<a class="btn accent" href="#/certificate">🎓 Get your certificate</a>`
    : (next ? `<a class="btn accent" href="#/lesson/${next.id}">${done ? 'Continue learning →' : 'Start learning →'}</a>` : '')
      + `<div class="small" style="margin-top:.8rem;opacity:.85">🎓 Certificate unlocks when all ${cs.total} modules are complete (${cs.finished}/${cs.total} done)</div>`;
  shell('home', `<div class="crumbs"><a href="#/">My Programs</a> › ${esc(S.brand.program_name)}</div>
    <section class="hero"><div><h1>Hi ${esc(S.me.name.split(' ')[0])} 👋</h1><p>${esc(S.brand.welcome_text)}</p><div class="hero-prog"><div class="mprog-top"><span>Program progress</span><b>${pct}%</b></div>${bar(pct)}</div>${cta}</div>
      <div class="ring" style="--p:${pct}"><div><span>${pct}%<small>COMPLETE</small></span></div></div></section>
    <div class="stats"><div class="stat"><i class="si a">📘</i><div><b>${done}<small>/${total}</small></b><span>Lessons completed</span></div></div>
      <div class="stat"><i class="si b">✅</i><div><b>${modsDone}<small>/${S.course.filter((m) => m.published).length}</small></b><span>Modules finished</span></div></div>
      <div class="stat"><i class="si c">➜</i><div><b>${next ? esc(next.module.title.split('–')[0].trim()) : '—'}</b><span>${next ? esc(next.title) : 'Nothing pending'}</span></div></div></div>
    ${d.announcements.length ? `<div class="ann-card"><div class="ann-head">📣 Announcements</div>${d.announcements.map((a) => `<div class="ann"><div class="row between"><b>${esc(a.title)}</b><span class="muted small">${fmtDate(a.created_at)}</span></div><div class="ann-body">${esc(a.body).replace(/\n/g, '<br>')}</div></div>`).join('')}</div>` : ''}
    <div class="sec-head"><div><h2>All modules</h2><div class="muted">${S.course.filter((m) => m.published).length} modules in this program</div></div>
      <div style="position:relative"><input class="search" id="q" type="text" placeholder="Search lessons…" autocomplete="off"><div class="results hidden" id="res"></div></div></div>
    <div class="mlist">${S.course.map((m, i) => {
      const pl = m.lessons.filter((l) => l.published), n = pl.length, c = pl.filter((l) => l.done).length, p = n ? Math.round((c / n) * 100) : 0;
      const status = m.locked ? '<span class="pill warn">🔒 Locked</span>' : !n ? '<span class="pill off">Coming soon</span>' : p === 100 ? '<span class="pill ok">✓ Completed</span>' : c ? '<span class="pill">In progress</span>' : (availableOn(m) ? '<span class="pill warn">' + availableOn(m) + '</span>' : '<span class="pill off">Not started</span>');
      const parts = m.title.split(/\s[–-]\s/), label = parts.length > 1 ? parts[0] : 'Module ' + (i + 1), name = parts.length > 1 ? parts.slice(1).join(' – ') : m.title;
      const numTxt = (label.match(/\d+/) || [String(i + 1).padStart(2, '0')])[0];
      const meta = [m.locked && m.release_date ? '🔒 Opens ' + fmtDate(m.release_date) : '', n ? n + (n === 1 ? ' lesson' : ' lessons') : '', !m.published ? 'hidden draft' : ''].filter(Boolean).join('  ·  ');
      return '<a class="mrow ' + (m.locked ? 'locked' : '') + (p === 100 && n ? ' done' : '') + '" href="' + (m.locked ? '#/program' : '#/module/' + m.id) + '">'
        + '<div class="mnum">' + esc(numTxt) + '</div>'
        + '<div class="mbody"><div class="meyebrow">' + esc(label) + '</div><h3>' + esc(name) + '</h3>' + (m.summary ? '<p>' + esc(m.summary) + '</p>' : '') + '<div class="mmeta">' + esc(meta) + '</div></div>'
        + '<div class="mprog">' + (n ? '<div class="mprog-top"><span>' + c + ' of ' + n + ' done</span><b>' + p + '%</b></div>' + bar(p) : '<div class="muted small">' + ((m.topics || '').split('\n').filter(Boolean).length ? '📚 ' + (m.topics || '').split('\n').filter(Boolean).length + ' topics covered' : 'Lessons coming soon') + '</div>') + '</div>'
        + '<div class="mstat">' + status + '</div><div class="mgo">›</div>' + '</a>';
    }).join('')}</div>`);
  const qi = $('#q'), res = $('#res');
  qi.oninput = () => {
    const t = qi.value.trim().toLowerCase(); if (t.length < 2) return res.classList.add('hidden');
    const hits = allLessons().filter((l) => !l.module.locked && l.title.toLowerCase().includes(t)).slice(0, 10);
    res.innerHTML = hits.length ? hits.map((l) => `<a href="#/lesson/${l.id}"><b>${esc(l.title)}</b><div class="muted small">${esc(l.module.title)}</div></a>`).join('') : '<a>No lessons match</a>';
    res.classList.remove('hidden');
  };
}
async function programsView() {
  const d = await api('GET', '/api/course'); S.course = d.modules;
  const ls = allLessons().filter((l) => l.published), total = ls.length, done = ls.filter((l) => l.done).length, cs = certStatus(), pct = cs.total ? Math.round((cs.finished / cs.total) * 100) : 0;
  const mods = S.course.filter((m) => m.published).length;
  shell('home', `<h1>Welcome, ${esc(S.me.name.split(' ')[0])} 👋</h1><p class="muted" style="margin-bottom:1.5rem">Select your program to see its modules.</p>
    <a class="card mod program" href="#/program" style="max-width:560px;padding:1.5rem;border-top:6px solid var(--accent)">
      <span class="num">Your program</span><h2 style="margin:0">${esc(S.brand.program_name)}</h2><div class="muted">${esc(S.brand.program_desc)}</div>
      ${bar(pct)}<div class="row between"><span class="muted small">${cs.finished} of ${cs.total} modules complete · ${pct}%</span><span class="btn accent sm">Open program →</span></div></a>`);
}
async function moduleView(id) {
  if (!S.course) S.course = (await api('GET', '/api/course')).modules;
  const mi = S.course.findIndex((x) => x.id === Number(id)), m = S.course[mi];
  if (!m || m.locked) return go('#/program');
  const ls = m.lessons.filter((l) => l.published || S.me.role === 'admin'), c = ls.filter((l) => l.done).length, p = ls.length ? Math.round((c / ls.length) * 100) : 0;
  const t = splitTitle(m.title, mi), nextL = ls.find((l) => !l.done), first = ls[0];
  const L = lines(m.learn), T = lines(m.topics), O = lines(m.outputs);
  const nextMod = S.course.slice(mi + 1).find((x) => x.published && !x.locked);
  const state = !ls.length ? '<span class="pill off">Opens soon</span>' : p === 100 ? '<span class="pill ok">✓ Completed</span>' : c ? '<span class="pill">In progress</span>' : (availableOn(m) ? '<span class="pill warn">' + availableOn(m) + '</span>' : '<span class="pill off">Not started</span>');
  const cta = !ls.length ? '<button class="btn" disabled>Lessons open soon</button>'
    : nextL ? `<a class="btn accent" href="#/lesson/${nextL.id}">${c ? 'Continue module →' : 'Start module →'}</a>` : `<a class="btn accent" href="#/lesson/${first.id}">Review module →</a>`;
  const sec = (eyebrow, title, body) => `<section class="msec"><div class="msec-head"><span class="eyebrow-s">${eyebrow}</span><h2>${title}</h2></div>${body}</section>`;
  shell('home', `<div class="crumbs"><a href="#/">My Programs</a> › <a href="#/program">Modules</a> › ${esc(t.label)}</div>
    <section class="mhero2"><div class="meyebrow">${esc(t.label)}</div><h1>${esc(t.name)}</h1>${m.summary ? `<p>${esc(m.summary)}</p>` : ''}
      ${m.fact ? `<div class="hero-fact"><b>💡 Did you know?</b> ${esc(m.fact)}</div>` : ''}
      <div class="mhero-foot"><div class="mhero-prog"><div class="mprog-top"><span>${c} of ${ls.length} lessons completed</span><b>${p}%</b></div>${bar(p)}</div>${cta}</div></section>
    <div class="mod-layout">
      <div class="mod-main">
        ${L.length ? sec('Outcomes', 'What you\'ll learn', '<div class="outcomes">' + L.map((x) => '<div class="outcome"><span class="ok-dot">✓</span><span>' + esc(x) + '</span></div>').join('') + '</div>') : ''}
        ${T.length ? sec('Syllabus', 'Topics covered in depth', '<ol class="syllabus">' + T.map((x, i) => { const [ti, de] = x.split('::').map((s) => s.trim()); return '<li><span class="sy-num">' + (i + 1) + '</span><span class="sy-text"><b>' + esc(ti) + '</b>' + (de ? '<span class="sy-desc">' + esc(de) + '</span>' : '') + '</span></li>'; }).join('') + '</ol>') : ''}
        ${O.length ? sec('Practical work', 'What you\'ll create', '<div class="outputs">' + O.map((x) => '<div class="output"><span class="out-ico">📄</span><span>' + esc(x) + '</span></div>').join('') + '</div>') : ''}
        ${sec('Lessons', ls.length ? 'Your lessons' : 'Lessons', ls.length
          ? '<div class="mlist">' + ls.map((l, i) => `<a class="lrow ${l.done ? 'done' : ''}" href="#/lesson/${l.id}">
              <div class="lnum">${l.done ? '✓' : i + 1}</div>
              <div class="mbody"><h3>${esc(l.title)} ${!l.published ? '<span class="pill off">draft</span>' : ''}</h3>
                <div class="chips">${l.video ? '<span class="chip">▶ Video</span>' : ''}${l.has_text ? '<span class="chip">📖 Reading</span>' : ''}${l.est_minutes ? `<span class="chip">⏱ ${l.est_minutes} min</span>` : ''}</div></div>
              <div class="mstat">${l.done ? '<span class="pill ok">✓ Completed</span>' : '<span class="pill off">To do</span>'}</div><div class="mgo">›</div></a>`).join('') + '</div>'
          : '<div class="empty-state"><div class="es-icon">🎬</div><div><b>Lessons open soon</b><p>Video lessons, notes and downloads for this module will appear here as soon as it opens. Use the topics above to see what is coming.</p></div></div>')}
      </div>
      <aside class="mod-side"><div class="side-card">
        <div class="row between"><b>Module overview</b>${state}</div>
        <ul class="facts-list">
          <li><span>📖 Lessons</span><b>${ls.length || '—'}</b></li>
          <li><span>📚 Topics</span><b>${T.length || '—'}</b></li>
          <li><span>🎯 Outcomes</span><b>${L.length || '—'}</b></li>
          <li><span>📄 Logbook targets</span><b>${O.length || '—'}</b></li>
        </ul>
        ${nextMod ? `<a class="side-link" href="#/module/${nextMod.id}"><small>Next module</small><span>${esc(splitTitle(nextMod.title, mi + 1).name)} →</span></a>` : ''}
        <a class="side-link plain" href="#/program">← All modules</a>
      </div></aside>
    </div>`);
}
async function lessonView(id) {
  if (!S.course) S.course = (await api('GET', '/api/course')).modules;
  const d = await api('GET', `/api/lessons/${id}`); const l = d.lesson;
  const m = S.course.find((x) => x.id === l.module_id);
  shell('home', `<div class="crumbs"><a href="#/">My Programs</a> › <a href="#/program">Modules</a> › <a href="#/module/${l.module_id}">${esc(splitTitle(l.module_title, 0).label)}</a></div>
    <div class="lesson-layout"><article>
      <div class="card">
        <div class="meyebrow">${esc(splitTitle(l.module_title, 0).label)}</div><h1 class="ltitle">${esc(l.title)}</h1>
        <div class="chips" style="margin:.5rem 0 1.5rem">${l.video_src ? '<span class="chip">▶ Video</span>' : ''}${l.body ? '<span class="chip">📖 Reading</span>' : ''}${l.est_minutes ? `<span class="chip">⏱ ${l.est_minutes} min</span>` : ''}${!l.published ? '<span class="pill off">draft — students cannot see this</span>' : ''}</div>
        ${videoHtml(l)}<div class="prose">${fixIn(l.body) || (l.video_src ? '' : '<p class="muted">Content coming soon.</p>')}</div>
        ${l.attachments.length ? `<div class="files" style="margin-top:1.25rem"><h3>Downloads</h3>${l.attachments.map((a) => `<a href="${esc(U(a.file))}" ${/\.pdf$/i.test(a.file) ? 'target="_blank" rel="noopener"' : 'download'}>📎 ${esc(a.name)} <span class="muted small">${fmtSize(a.size)}</span></a>`).join('')}</div>` : ''}
        <div class="complete-bar"><button class="btn ${d.done ? 'ghost' : 'accent'}" id="cmp">${d.done ? '✓ Completed — undo' : 'Mark as complete'}</button>
          <span class="muted small">${d.done ? 'Nice work! This lesson counts towards your progress.' : 'Tick this off when you have finished the lesson.'}</span></div>
      </div>
      <div class="pager">${d.prev ? `<a class="btn ghost" href="#/lesson/${d.prev}">← Previous</a>` : '<span></span>'}${d.next ? `<a class="btn" href="#/lesson/${d.next}">Next lesson →</a>` : '<a class="btn ghost" href="#/program">Back to modules</a>'}</div>
    </article>
    <aside class="card outline"><div class="meyebrow">In this module</div><h3>${esc(m ? splitTitle(m.title, 0).name : 'Lessons')}</h3>${(m ? m.lessons : []).filter((x) => x.published || S.me.role === 'admin').map((x) => `<a href="#/lesson/${x.id}" class="${x.id === l.id ? 'on' : ''}"><span class="tick ${x.done ? 'done' : ''}">${x.done ? '✓' : ''}</span><span>${esc(x.title)}</span></a>`).join('')}</aside></div>`);
  $('#cmp').onclick = async () => {
    try { await api('POST', `/api/lessons/${id}/complete`, { done: !d.done }); S.course = null; await lessonView(id); if (!d.done) toast('Lesson marked complete ✓'); } catch (e) { fail(e); }
  };
}

/* ------------------------------------------------------------ student: account & certificate */
function accountView() {
  const m = S.me, canId = S.brand.allow_id_change === '1' || m.role === 'admin';
  shell('account', `<h1>My account</h1><div class="grid2" style="align-items:start">
    <div class="card stack"><h2>Profile</h2><form id="pf">
      <div class="field"><label>Full name</label><input id="pn" type="text" value="${esc(m.name)}" required></div>
      <div class="field"><label>Email (optional)</label><input id="pe" type="email" value="${esc(m.email)}"></div>
      <button class="btn">Save profile</button></form></div>
    <div class="stack"><div class="card"><h2>Change password</h2><form id="pwf">
      <div class="field"><label>Current password</label><input id="c1" type="password" autocomplete="current-password" required></div>
      <div class="field"><label>New password</label><input id="c2" type="password" autocomplete="new-password" minlength="8" required><div class="hint">At least 8 characters. You'll stay signed in here; other devices are signed out.</div></div>
      <div class="field"><label>Confirm new password</label><input id="c3" type="password" autocomplete="new-password" required></div>
      <button class="btn">Update password</button></form></div>
    <div class="card"><h2>Change Student ID</h2>${canId ? `<p class="muted small">Your current ID is <b>${esc(m.student_id)}</b>. This is what you type to sign in.</p><form id="idf">
      <div class="field"><label>New Student ID</label><input id="i1" type="text" autocapitalize="none" required></div>
      <div class="field"><label>Confirm with your password</label><input id="i2" type="password" autocomplete="current-password" required></div>
      <button class="btn">Change ID</button></form>` : `<p class="muted">Your ID is <b>${esc(m.student_id)}</b>. Changing IDs is turned off — contact your coordinator if you need a change.</p>`}</div></div></div>`);
  $('#pf').onsubmit = async (e) => { e.preventDefault(); try { await api('POST', '/api/me/profile', { name: $('#pn').value, email: $('#pe').value }); S.me.name = $('#pn').value.trim(); S.me.email = $('#pe').value.trim(); toast('Profile saved'); accountView(); } catch (x) { fail(x); } };
  $('#pwf').onsubmit = async (e) => {
    e.preventDefault(); if ($('#c2').value !== $('#c3').value) return fail(new Error('The new passwords do not match.'));
    try { await api('POST', '/api/me/password', { current: $('#c1').value, new: $('#c2').value }); toast('Password updated'); e.target.reset(); } catch (x) { fail(x); }
  };
  if (canId) $('#idf').onsubmit = async (e) => { e.preventDefault(); try { const r = await api('POST', '/api/me/student-id', { student_id: $('#i1').value, current: $('#i2').value }); S.me.student_id = r.student_id; toast('Student ID changed — use it next time you sign in'); accountView(); } catch (x) { fail(x); } };
}
async function certificateView() {
  const d = await api('GET', '/api/course'); S.course = d.modules;
  const cs = certStatus();
  if (!cs.ok) { toast(`Complete all modules to unlock your certificate (${cs.finished}/${cs.total} done).`, true); return go('#/program'); }
  shell('home', `<div class="row between no-print" style="margin-bottom:1rem"><a href="#/program">← Modules</a><button class="btn" onclick="window.print()">🖨 Print / Save as PDF</button></div>
    <div class="cert"><img src="${esc(U(S.brand.logo_url))}" alt=""><div class="muted">CERTIFICATE OF COMPLETION</div><h1>${esc(S.brand.brand_name)}</h1>
    <p>This certifies that</p><div class="nm">${esc(S.me.name)}</div><p>has successfully completed all ${S.course.filter((m) => m.published).length} modules of the ${esc(S.brand.program_name || "program")}</p>
    <p class="muted">Issued ${fmtDate(new Date().toISOString().slice(0, 10))}</p></div>`);
}

/* ------------------------------------------------------------ admin: overview */
async function adminOverview() {
  const o = await api('GET', '/api/admin/overview');
  shell('admin', `<div class="stats">
    <div class="stat"><b>${o.students}</b><span>Students</span></div><div class="stat"><b>${o.active_week}</b><span>Active this week</span></div>
    <div class="stat"><b>${o.avg_progress}%</b><span>Average progress</span></div><div class="stat"><b>${o.completed}</b><span>Completed the program</span></div>
    <div class="stat"><b>${o.modules}</b><span>Modules</span></div><div class="stat"><b>${o.lessons}</b><span>Lessons${o.drafts ? ` (${o.drafts} draft)` : ''}</span></div></div>
    <div class="grid2" style="align-items:start"><div class="card"><h2>Needs a nudge</h2><p class="muted small">Not seen in 14+ days</p>${o.inactive.length ? `<div class="tbl-wrap"><table class="tbl"><tbody>${o.inactive.map((s) => `<tr class="click" data-href="#/admin/student/${s.id}"><td><b>${esc(s.name)}</b><div class="muted small">${esc(s.student_id)}</div></td><td>${s.percent}%</td><td class="muted small">${ago(s.last_active)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Everyone has been active recently 🎉</p>'}</div>
      <div class="card"><h2>Top progress</h2>${o.top.length ? `<table class="tbl"><tbody>${o.top.map((s) => `<tr class="click" data-href="#/admin/student/${s.id}"><td><b>${esc(s.name)}</b></td><td class="mini-bar">${bar(s.percent)}</td><td>${s.percent}%</td></tr>`).join('')}</tbody></table>` : '<p class="muted">No students yet.</p>'}</div></div>
    <div class="card" style="margin-top:1rem"><h2>Recent admin activity</h2>${o.recent.length ? `<table class="tbl"><tbody>${o.recent.map((r) => `<tr><td class="muted small" style="white-space:nowrap">${fmtDate(r.at, true)}</td><td>${esc(r.actor || '')}</td><td>${esc(r.action.replace(/_/g, ' '))}</td><td class="muted">${esc(r.detail || '')}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Nothing yet.</p>'}</div>`, adminTabs('ov'));
  $$('tr.click').forEach((r) => r.onclick = () => go(r.dataset.href));
}

/* ------------------------------------------------------------ admin: content manager */
async function adminContent() {
  const d = await api('GET', '/api/admin/course'); const mods = d.modules;
  shell('admin', `<div class="row between" style="margin-bottom:1rem"><div><h2 style="margin:0">Modules & lessons</h2><div class="muted small">Add, rename, reorder, hide or delete anything. Students only see published items.</div></div><button class="btn accent" id="addm">＋ Add module</button></div>
  ${mods.map((m, mi) => `<section class="mgr-mod" data-m="${m.id}"><header>
      <h3>${esc(m.title)}</h3>
      <span class="pill ${m.published ? 'ok' : 'off'}">${m.published ? 'Published' : 'Hidden'}</span>
      <div class="actions"><button class="btn ghost icon" data-a="mup" ${mi === 0 ? 'disabled' : ''} title="Move up">▲</button><button class="btn ghost icon" data-a="mdn" ${mi === mods.length - 1 ? 'disabled' : ''} title="Move down">▼</button>
      <button class="btn ghost sm" data-a="medit">Edit</button><button class="btn sm" data-a="ladd">＋ Lesson</button><button class="btn danger sm" data-a="mdel">Delete</button></div></header>
    ${m.lessons.length ? m.lessons.map((l, li) => `<div class="mgr-les" data-l="${l.id}"><span class="t">${esc(l.title)}</span>
      ${l.video ? '<span class="pill">▶ video</span>' : ''}${l.has_text ? '<span class="pill">text</span>' : ''}<span class="pill ${l.published ? 'ok' : 'off'}">${l.published ? 'live' : 'draft'}</span>
      <div class="actions"><button class="btn ghost icon" data-a="lup" ${li === 0 ? 'disabled' : ''}>▲</button><button class="btn ghost icon" data-a="ldn" ${li === m.lessons.length - 1 ? 'disabled' : ''}>▼</button>
      <a class="btn ghost sm" href="#/admin/lesson/${l.id}">Edit content</a><button class="btn danger sm" data-a="ldel">Delete</button></div></div>`).join('')
      : '<div class="mgr-les muted">No lessons yet — click “＋ Lesson”.</div>'}</section>`).join('') || '<div class="card">No modules yet.</div>'}`, adminTabs('content'));

  const modForm = (m = {}) => `<form id="mf"><div class="field"><label>Module title</label><input id="mt" type="text" value="${esc(m.title || '')}" required></div>
    <div class="field"><label>Short description (optional)</label><textarea id="ms">${esc(m.summary || '')}</textarea></div>
    <div class="field"><label>🎯 What students will learn (one per line)</label><textarea id="mlearn" style="min-height:110px">${esc(m.learn || '')}</textarea></div>
    <div class="field"><label>📚 Topics covered (one per line)</label><textarea id="mtopics" style="min-height:200px">${esc(m.topics || '')}</textarea><div class="hint">Format: <b>Topic title :: short explanation</b> — the explanation is optional.</div></div>
    <div class="field"><label>📄 What students will create (one per line)</label><textarea id="moutputs" style="min-height:90px">${esc(m.outputs || '')}</textarea></div>
    <div class="field"><label>💡 Fun fact / key takeaway (shown under the module)</label><textarea id="mfact" style="min-height:70px">${esc(m.fact || '')}</textarea><div class="hint">One short, interesting sentence about the topic.</div></div>
    <div class="grid2"><div class="field"><label>Release date (optional)</label><input id="md" type="date" value="${esc(m.release_date || '')}"><div class="hint">Not shown to students. Only used if you turn on “Lock modules until release date” in Settings.</div></div>
    <div class="field"><label>&nbsp;</label><label class="check"><input id="mp" type="checkbox" ${m.published !== false ? 'checked' : ''}> Published (visible to students)</label></div></div>
    <div class="foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn">Save</button></div></form>`;
  const modOpen = (title, m, onSave) => modal(title, modForm(m), { onMount: (el, close) => {
    $('[data-close]', el).onclick = close;
    $('#mf', el).onsubmit = async (e) => { e.preventDefault(); try { await onSave({ title: $('#mt', el).value, summary: $('#ms', el).value, fact: $('#mfact', el).value, learn: $('#mlearn', el).value, topics: $('#mtopics', el).value, outputs: $('#moutputs', el).value, release_date: $('#md', el).value || null, published: $('#mp', el).checked }); close(); adminContent(); } catch (x) { fail(x); } };
  } });
  $('#addm').onclick = () => modOpen('Add module', {}, (b) => api('POST', '/api/admin/modules', b));
  $('#app').onclick = async (e) => {
    const btn = e.target.closest('[data-a]'); if (!btn) return;
    const a = btn.dataset.a, mid = Number(btn.closest('[data-m]')?.dataset.m), lid = Number(btn.closest('[data-l]')?.dataset.l);
    const m = mods.find((x) => x.id === mid);
    try {
      if (a === 'mup' || a === 'mdn') { await api('POST', `/api/admin/modules/${mid}/move`, { dir: a === 'mup' ? 'up' : 'down' }); adminContent(); }
      else if (a === 'lup' || a === 'ldn') { await api('POST', `/api/admin/lessons/${lid}/move`, { dir: a === 'lup' ? 'up' : 'down' }); adminContent(); }
      else if (a === 'medit') modOpen('Edit module', m, (b) => api('PUT', `/api/admin/modules/${mid}`, b));
      else if (a === 'mdel' && await confirmBox(`Delete “${m.title}” and all ${m.lessons.length} lesson(s) inside it, including uploaded files and student progress on them? This cannot be undone.`)) { await api('DELETE', `/api/admin/modules/${mid}`); toast('Module deleted'); adminContent(); }
      else if (a === 'ldel') { const l = m.lessons.find((x) => x.id === lid); if (await confirmBox(`Delete lesson “${l.title}”? This cannot be undone.`)) { await api('DELETE', `/api/admin/lessons/${lid}`); toast('Lesson deleted'); adminContent(); } }
      else if (a === 'ladd') {
        modal('Add lesson', `<form id="lf"><div class="field"><label>Lesson title</label><input id="lt" type="text" required></div><div class="hint">You'll add the video and text on the next screen. It starts as a draft.</div><div class="foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn">Create & edit</button></div></form>`, { onMount: (el, close) => {
          $('[data-close]', el).onclick = close;
          $('#lf', el).onsubmit = async (ev) => { ev.preventDefault(); try { const r = await api('POST', '/api/admin/lessons', { module_id: mid, title: $('#lt', el).value }); close(); go(`#/admin/lesson/${r.id}`); } catch (x) { fail(x); } };
        } });
      }
    } catch (x) { fail(x); }
  };
}

/* ------------------------------------------------------------ admin: lesson editor */
async function adminLesson(id) {
  const [ld, cd] = await Promise.all([api('GET', `/api/admin/lessons/${id}`), api('GET', '/api/admin/course')]);
  const l = ld.lesson; let video = l.video_src; let atts = [...l.attachments]; let srcMode = false;
  shell('admin', `<div class="crumbs"><a href="#/admin/content">Content</a> › ${esc(l.module_title)} › Edit lesson</div>
  <div class="grid2" style="grid-template-columns:2fr 1fr;align-items:start">
   <div class="stack"><div class="card"><div class="field"><label>Lesson title</label><input id="t" type="text" value="${esc(l.title)}"></div>
     <label>Lesson text</label>
     <div class="editor-tb" id="tb">
       <button data-c="bold" title="Bold"><b>B</b></button><button data-c="italic" title="Italic"><i>I</i></button><button data-c="underline" title="Underline"><u>U</u></button><span class="sep"></span>
       <button data-c="h2" title="Heading">H2</button><button data-c="h3" title="Subheading">H3</button><button data-c="p" title="Paragraph">¶</button><button data-c="quote" title="Quote">❝</button><span class="sep"></span>
       <button data-c="ul" title="Bullets">• List</button><button data-c="ol" title="Numbers">1. List</button><span class="sep"></span>
       <button data-c="link" title="Link">🔗</button><button data-c="img" title="Insert image">🖼</button><button data-c="hr" title="Divider">―</button><button data-c="clear" title="Clear formatting">✕</button><span class="sep"></span><button data-c="src" title="Edit HTML">&lt;/&gt;</button></div>
     <div class="editor-area prose" id="ed" contenteditable="true">${fixIn(l.body)}</div><textarea class="editor-src hidden" id="src"></textarea></div>
    <div class="card"><h2>Downloads & resources</h2><div id="atts"></div><button class="btn ghost sm" id="addatt">＋ Attach a file</button><div class="hint">PDF, Word, Excel, PowerPoint, ZIP, images — students can download them from the lesson.</div><div id="attp"></div></div></div>
   <div class="stack"><div class="card"><h2>Publishing</h2>
     <div class="field"><label>Module</label><select id="mod">${cd.modules.map((m) => `<option value="${m.id}" ${m.id === l.module_id ? 'selected' : ''}>${esc(m.title)}</option>`).join('')}</select></div>
     <div class="field"><label>Estimated time (minutes)</label><input id="est" type="number" min="0" value="${l.est_minutes ?? ''}"></div>
     <label class="check"><input id="pub" type="checkbox" ${l.published ? 'checked' : ''}> Published — visible to students</label><div class="hint">Untick to keep it as a draft while you work.</div></div>
    <div class="card"><h2>Video</h2><div class="field"><label>Paste a link or embed code</label><textarea id="vurl" placeholder="YouTube, Vimeo, Google Drive, Loom link — or an &lt;iframe&gt; embed code" style="min-height:70px">${['upload'].includes(l.video_type) ? '' : esc(l.video_src)}</textarea></div>
      <div class="row"><button class="btn ghost sm" id="upv">⬆ Upload a video file</button><button class="btn danger sm" id="rmv">Remove video</button></div>
      <div class="hint">Uploads: MP4, WebM, MOV. Large files play more smoothly from YouTube (set to “Unlisted”) — just paste the link above.</div><div id="vp"></div><div class="video-preview" id="vprev"></div></div></div></div>
  <div class="savebar"><button class="btn" id="save">Save changes</button><a class="btn ghost" href="#/lesson/${l.id}" target="_blank">Preview as student ↗</a><a href="#/admin/content" class="muted">Back to content</a><span class="muted small" id="saved"></span></div>`);

  const ed = $('#ed'), srcEl = $('#src'), vurl = $('#vurl');
  const drawVideo = () => {
    const raw = video.startsWith('/uploads/') ? video : vurl.value.trim();
    $('#vprev').innerHTML = raw.startsWith('/uploads/') ? `<div class="notice">✅ Uploaded file attached</div><div class="video">${'<video controls src="' + esc(U(raw)) + '"></video>'}</div>` : '';
  };
  const drawAtts = () => { $('#atts').innerHTML = atts.length ? atts.map((a) => `<div class="row between" style="padding:.4rem 0;border-bottom:1px solid var(--line)"><span>📎 ${esc(a.name)} <span class="muted small">${fmtSize(a.size)}</span></span><button class="btn danger sm" data-att="${a.id}">Remove</button></div>`).join('') : '<p class="muted small">No files attached.</p>'; };
  drawVideo(); drawAtts();
  vurl.oninput = () => { if (video.startsWith('/uploads/')) video = ''; drawVideo(); };
  ed.addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain')); });
  $('#tb').addEventListener('mousedown', (e) => e.preventDefault());
  $('#tb').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-c]'); if (!b) return; const c = b.dataset.c;
    if (c === 'src') {
      srcMode = !srcMode; if (srcMode) { srcEl.value = ed.innerHTML; } else { ed.innerHTML = srcEl.value; }
      ed.classList.toggle('hidden', srcMode); srcEl.classList.toggle('hidden', !srcMode); return;
    }
    if (srcMode) return; ed.focus();
    const x = document.execCommand.bind(document);
    if (c === 'bold' || c === 'italic' || c === 'underline') x(c);
    else if (c === 'h2' || c === 'h3' || c === 'p') x('formatBlock', false, c);
    else if (c === 'quote') x('formatBlock', false, 'blockquote');
    else if (c === 'ul') x('insertUnorderedList'); else if (c === 'ol') x('insertOrderedList');
    else if (c === 'hr') x('insertHorizontalRule'); else if (c === 'clear') { x('removeFormat'); x('formatBlock', false, 'p'); }
    else if (c === 'link') { const u = prompt('Link address (https://…)'); if (u) x('createLink', false, u); }
    else if (c === 'img') {
      const f = await pickFile('image/*'); if (!f) return;
      try { toast('Uploading image…'); const r = await upload(f, 'image'); ed.focus(); x('insertImage', false, U(r.url)); } catch (err) { fail(err); }
    }
  });
  $('#upv').onclick = async () => {
    const f = await pickFile('video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov,.m4v'); if (!f) return;
    $('#vp').innerHTML = `<div class="muted small">Uploading ${esc(f.name)} (${fmtSize(f.size)})…</div><div class="progress-up"><i></i></div>`;
    try { const r = await upload(f, 'video', (p) => { $('#vp .progress-up i').style.width = Math.round(p * 100) + '%'; }); video = r.url; vurl.value = ''; $('#vp').innerHTML = ''; drawVideo(); toast('Video uploaded — press Save to attach it'); }
    catch (x) { $('#vp').innerHTML = ''; fail(x); }
  };
  $('#rmv').onclick = () => { video = ''; vurl.value = ''; drawVideo(); toast('Video removed — press Save to apply'); };
  $('#addatt').onclick = async () => {
    const f = await pickFile('.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.zip,.png,.jpg,.jpeg'); if (!f) return;
    $('#attp').innerHTML = `<div class="progress-up"><i></i></div>`;
    try { const r = await upload(f, 'file', (p) => { $('#attp i').style.width = Math.round(p * 100) + '%'; }); const a = await api('POST', `/api/admin/lessons/${id}/attachments`, { name: f.name, file: r.url, size: r.size }); atts.push({ id: a.id, name: f.name, file: r.url, size: r.size }); drawAtts(); toast('File attached'); }
    catch (x) { fail(x); } $('#attp').innerHTML = '';
  };
  $('#atts').onclick = async (e) => { const b = e.target.closest('[data-att]'); if (!b) return; if (!await confirmBox('Remove this file?', 'Remove')) return; try { await api('DELETE', `/api/admin/attachments/${b.dataset.att}`); atts = atts.filter((a) => a.id !== Number(b.dataset.att)); drawAtts(); } catch (x) { fail(x); } };
  $('#save').onclick = async () => {
    const btn = $('#save'); btn.disabled = true;
    try {
      await api('PUT', `/api/admin/lessons/${id}`, { title: $('#t').value, module_id: $('#mod').value, est_minutes: $('#est').value, published: $('#pub').checked,
        body: fixOut(srcMode ? srcEl.value : ed.innerHTML), video: video.startsWith('/uploads/') ? video : vurl.value });
      $('#saved').textContent = 'Saved ' + new Date().toLocaleTimeString(); toast('Lesson saved'); S.course = null;
      const r = await api('GET', `/api/admin/lessons/${id}`); video = r.lesson.video_src; if (!video.startsWith('/uploads/')) { vurl.value = r.lesson.video_type === 'none' ? '' : video; } drawVideo();
    } catch (x) { fail(x); } btn.disabled = false;
  };
}

/* ------------------------------------------------------------ admin: students */
const credsText = (id, pw) => `${S.brand.brand_name} — Learning Portal\nSign in: ${location.origin}\nStudent ID: ${id}\nPassword: ${pw}\n(You'll be asked to choose your own password the first time you sign in.)`;
function showCreds(title, id, pw) {
  const t = credsText(id, pw);
  modal(title, `<p class="muted small">Copy and send this to the student now — the password is not stored anywhere readable and can't be shown again.</p><div class="creds">${esc(t)}</div><div class="foot"><button class="btn ghost" data-close>Close</button><button class="btn" data-copy>Copy details</button></div>`, { onMount: (el, close) => { $('[data-close]', el).onclick = close; $('[data-copy]', el).onclick = () => copyText(t); } });
}
const genPw = () => { const a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; const r = crypto.getRandomValues(new Uint32Array(10)); return [...r].map((n) => a[n % a.length]).join(''); };
async function adminStudents() {
  const { students } = await api('GET', '/api/admin/students');
  shell('admin', `<div class="row between" style="margin-bottom:1rem"><input class="search" id="flt" type="text" placeholder="Search name or ID…"><div class="row"><a class="btn ghost" href="${BASE}/api/admin/export.csv">⬇ Export progress (CSV)</a><button class="btn ghost" id="imp">Import CSV</button><button class="btn accent" id="add">＋ Add student</button></div></div>
  <div class="card flat" style="padding:0"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Student</th><th>ID</th><th>Progress</th><th>Last active</th><th>Status</th></tr></thead><tbody id="tb">
  ${students.map((s) => `<tr class="click" data-href="#/admin/student/${s.id}" data-k="${esc((s.name + ' ' + s.student_id + ' ' + s.email).toLowerCase())}"><td><b>${esc(s.name)}</b><div class="muted small">${esc(s.email)}</div></td><td>${esc(s.student_id)}</td>
   <td><div class="row"><div class="mini-bar">${bar(s.percent)}</div><span class="small">${s.percent}% <span class="muted">(${s.completed}/${s.total})</span></span></div></td><td class="muted small">${ago(s.last_active)}</td>
   <td>${!s.active ? '<span class="pill danger">Disabled</span>' : s.must_change ? '<span class="pill warn">Not signed in yet</span>' : '<span class="pill ok">Active</span>'}</td></tr>`).join('') || '<tr><td colspan="5" class="muted" style="padding:2rem;text-align:center">No students yet — add your first one.</td></tr>'}</tbody></table></div></div>`, adminTabs('students'));
  $$('tr.click').forEach((r) => r.onclick = () => go(r.dataset.href));
  $('#flt').oninput = (e) => { const t = e.target.value.toLowerCase(); $$('#tb tr[data-k]').forEach((r) => r.classList.toggle('hidden', !r.dataset.k.includes(t))); };
  $('#add').onclick = () => {
    const nums = students.map((s) => (/^TA(\d+)$/i.exec(s.student_id) || [])[1]).filter(Boolean).map(Number); const nid = 'TA' + String((nums.length ? Math.max(...nums) : 0) + 1).padStart(4, '0');
    modal('Add student', `<form id="sf"><div class="grid2"><div class="field"><label>Student ID</label><input id="a1" type="text" value="${nid}" required></div><div class="field"><label>Full name</label><input id="a2" type="text" required></div></div>
      <div class="field"><label>Email (optional)</label><input id="a3" type="email"></div>
      <div class="field"><label>Password</label><div class="row"><input id="a4" type="text" value="${genPw()}" class="grow" style="width:auto;font-family:ui-monospace,monospace"><button type="button" class="btn ghost sm" id="rg">Generate</button></div><div class="hint">The student must change it on first sign-in.</div></div>
      <div class="foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn">Create student</button></div></form>`, { onMount: (el, close) => {
      $('[data-close]', el).onclick = close; $('#rg', el).onclick = () => { $('#a4', el).value = genPw(); };
      $('#sf', el).onsubmit = async (e) => { e.preventDefault(); try { const r = await api('POST', '/api/admin/students', { student_id: $('#a1', el).value, name: $('#a2', el).value, email: $('#a3', el).value, password: $('#a4', el).value }); close(); showCreds('Student created', r.student_id, r.password); adminStudents(); } catch (x) { fail(x); } };
    } });
  };
  $('#imp').onclick = () => modal('Import students', `<p class="muted small">One student per line: <b>student_id, name, email, password</b>. Email and password are optional (missing passwords are generated). A header row is fine.</p>
    <textarea id="csv" style="min-height:180px;font-family:ui-monospace,monospace" placeholder="TA0101, Priya Sharma, priya@example.com&#10;TA0102, Rahul Verma"></textarea><div id="ires"></div>
    <div class="foot"><button class="btn ghost" data-close>Close</button><button class="btn" id="run">Import</button></div>`, { wide: true, onMount: (el, close) => {
    $('[data-close]', el).onclick = close;
    $('#run', el).onclick = async () => {
      const rows = $('#csv', el).value.split(/\r?\n/).map((ln) => ln.split(',').map((c) => c.trim())).filter((c) => c[0] && !/^student[_ ]?id$/i.test(c[0])).map((c) => ({ student_id: c[0], name: c[1] || '', email: c[2] || '', password: c[3] || '' }));
      if (!rows.length) return fail(new Error('Paste at least one line.'));
      try {
        const { results } = await api('POST', '/api/admin/students/import', { rows }); const okn = results.filter((r) => r.ok);
        const csv = 'Student ID,Name,Password\n' + okn.map((r) => `${r.student_id},"${r.name}",${r.password}`).join('\n');
        $('#ires', el).innerHTML = `<div class="notice" style="margin-top:1rem"><b>${okn.length} added</b>, ${results.length - okn.length} skipped.${okn.length ? ' <a href="#" id="dl">Download login list (CSV)</a>' : ''}</div>${results.filter((r) => !r.ok).map((r) => `<div class="error" style="margin-top:.4rem">${esc(r.student_id)}: ${esc(r.error)}</div>`).join('')}`;
        if (okn.length) $('#dl', el).onclick = (e) => { e.preventDefault(); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'new-student-logins.csv'; a.click(); };
        $('#run', el).classList.add('hidden'); adminStudents();
      } catch (x) { fail(x); }
    };
  } });
}
async function adminStudent(id) {
  const d = await api('GET', `/api/admin/students/${id}`); const s = d.student;
  shell('admin', `<div class="crumbs"><a href="#/admin/students">Students</a> › ${esc(s.name)}</div>
  <div class="grid2" style="grid-template-columns:1fr 1.4fr;align-items:start">
   <div class="stack"><div class="card"><h2>Details</h2><form id="ef">
     <div class="field"><label>Full name</label><input id="e2" type="text" value="${esc(s.name)}" required></div>
     <div class="field"><label>Student ID (login)</label><input id="e1" type="text" value="${esc(s.student_id)}" required></div>
     <div class="field"><label>Email</label><input id="e3" type="email" value="${esc(s.email)}"></div>
     <label class="check" style="margin-bottom:1rem"><input id="e4" type="checkbox" ${s.active ? 'checked' : ''}> Account active (can sign in)</label>
     <button class="btn">Save details</button></form>
     <p class="muted small" style="margin-top:1rem">Created ${fmtDate(s.created_at)} · Last sign-in ${s.last_login ? ago(s.last_login) : 'never'}</p></div>
    <div class="card"><h2>Password</h2><p class="muted small">Forgotten password? Generate a new one and send it to the student. They'll be asked to change it at next sign-in.</p><div class="row"><button class="btn" id="rp">Reset password</button><button class="btn ghost" id="rpc">Set custom…</button></div></div>
    <div class="card"><h2>Danger zone</h2><p class="muted small">Deleting removes the student and all their progress permanently. To pause access instead, untick “Account active”.</p><button class="btn danger" id="del">Delete student</button></div></div>
   <div class="card"><div class="row between"><h2 style="margin:0">Progress</h2><b style="font-size:1.4rem;color:var(--primary)">${d.percent}%</b></div><div class="muted small" style="margin:.2rem 0 .8rem">${d.completed} of ${d.total} lessons completed</div>${bar(d.percent)}
     <div style="margin-top:1rem">${d.modules.filter((m) => m.published).map((m) => { const pl = m.lessons.filter((l) => l.published), c = pl.filter((l) => l.done).length;
       return `<details style="border-top:1px solid var(--line);padding:.55rem 0"><summary style="cursor:pointer;font-weight:700">${esc(m.title)} <span class="pill ${pl.length && c === pl.length ? 'ok' : ''}" style="margin-left:.4rem">${c}/${pl.length}</span></summary>
       ${pl.map((l) => `<div class="row" style="padding:.25rem 0 .25rem 1rem"><span class="tick ${l.done ? 'done' : ''}">${l.done ? '✓' : ''}</span><span class="grow">${esc(l.title)}</span><span class="muted small">${l.completed_at ? fmtDate(l.completed_at) : ''}</span></div>`).join('') || '<div class="muted small" style="padding-left:1rem">No lessons</div>'}</details>`; }).join('')}</div></div></div>`, adminTabs('students'));
  $('#ef').onsubmit = async (e) => { e.preventDefault(); try { await api('PUT', `/api/admin/students/${id}`, { student_id: $('#e1').value, name: $('#e2').value, email: $('#e3').value, active: $('#e4').checked }); toast('Saved'); adminStudent(id); } catch (x) { fail(x); } };
  $('#rp').onclick = async () => { if (!await confirmBox(`Reset the password for ${s.name}? Their current password will stop working immediately.`, 'Reset')) return; try { const r = await api('POST', `/api/admin/students/${id}/reset-password`, {}); showCreds('Password reset', r.student_id, r.password); } catch (x) { fail(x); } };
  $('#rpc').onclick = () => modal('Set a custom password', `<form id="cf"><div class="field"><label>New password</label><input id="cp" type="text" minlength="8" required><div class="hint">At least 8 characters.</div></div><div class="foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn">Set password</button></div></form>`, { onMount: (el, close) => {
    $('[data-close]', el).onclick = close; $('#cf', el).onsubmit = async (e) => { e.preventDefault(); try { const r = await api('POST', `/api/admin/students/${id}/reset-password`, { password: $('#cp', el).value }); close(); showCreds('Password set', r.student_id, r.password); } catch (x) { fail(x); } }; } });
  $('#del').onclick = async () => { if (await confirmBox(`Permanently delete ${s.name} (${s.student_id}) and all their progress?`)) { try { await api('DELETE', `/api/admin/students/${id}`); toast('Student deleted'); go('#/admin/students'); } catch (x) { fail(x); } } };
}

/* ------------------------------------------------------------ admin: announcements & branding */
async function adminAnnouncements() {
  const { announcements: list } = await api('GET', '/api/course');
  shell('admin', `<div class="grid2" style="align-items:start"><div class="card"><h2>New announcement</h2><form id="af"><div class="field"><label>Title</label><input id="at" type="text" required></div><div class="field"><label>Message</label><textarea id="ab"></textarea></div>
    <label class="check" style="margin-bottom:1rem"><input id="ap" type="checkbox"> Pin to the top</label><button class="btn">Post announcement</button></form></div>
    <div class="card"><h2>Posted</h2>${list.length ? list.map((a) => `<div class="ann" data-id="${a.id}"><div class="row between"><b>${esc(a.title)} ${a.pinned ? '<span class="pill">pinned</span>' : ''}</b><button class="btn danger sm" data-del>Delete</button></div><div class="muted small">${fmtDate(a.created_at)}</div><div>${esc(a.body).replace(/\n/g, '<br>')}</div></div>`).join('') : '<p class="muted">None yet.</p>'}</div></div>`, adminTabs('ann'));
  $('#af').onsubmit = async (e) => { e.preventDefault(); try { await api('POST', '/api/admin/announcements', { title: $('#at').value, body: $('#ab').value, pinned: $('#ap').checked }); toast('Posted'); adminAnnouncements(); } catch (x) { fail(x); } };
  $$('[data-del]').forEach((b) => b.onclick = async () => { if (await confirmBox('Delete this announcement?')) { await api('DELETE', `/api/admin/announcements/${b.closest('.ann').dataset.id}`); adminAnnouncements(); } });
}
async function adminBrand() {
  const b = S.brand;
  shell('admin', `<form id="bf" class="grid2" style="align-items:start"><div class="stack"><div class="card"><h2>Branding</h2>
    <div class="field"><label>Academy name</label><input id="b1" type="text" value="${esc(b.brand_name)}" required></div>
    <div class="field"><label>Program name (shown on the program card)</label><input id="b9" type="text" value="${esc(b.program_name)}"></div><div class="field"><label>Program description</label><textarea id="b10">${esc(b.program_desc)}</textarea></div>
    <div class="field"><label>Tagline (login page)</label><input id="b2" type="text" value="${esc(b.tagline)}"></div>
    <div class="field"><label>Logo</label><div class="row"><img id="lp" src="${esc(U(b.logo_url))}" alt="" style="height:48px;border:1px solid var(--line);border-radius:8px;padding:4px;background:#fff"><button type="button" class="btn ghost sm" id="ul">Upload new logo</button><button type="button" class="btn ghost sm" id="rl">Reset to default</button></div><div class="hint">PNG, JPG or WebP. A transparent PNG about 400px wide works best.</div></div>
    <div class="grid2"><div class="field"><label>Primary colour</label><input id="b3" type="color" value="${esc(b.color_primary)}"></div><div class="field"><label>Accent colour</label><input id="b4" type="color" value="${esc(b.color_accent)}"></div></div></div>
    <div class="card"><h2>Messages</h2><div class="field"><label>Dashboard welcome text</label><textarea id="b5">${esc(b.welcome_text)}</textarea></div>
    <div class="field"><label>Login page help text</label><textarea id="b6">${esc(b.support_text)}</textarea><div class="hint">e.g. “Forgot your password? WhatsApp us at +91…”</div></div></div></div>
    <div class="stack"><div class="card"><h2>Rules</h2><label class="check" style="margin-bottom:.4rem"><input id="b7" type="checkbox" ${b.lock_by_date === '1' ? 'checked' : ''}> Lock each module until its release date</label><div class="hint" style="margin-bottom:1rem">Uses the date set on each module. Admins always see everything.</div>
    <label class="check"><input id="b8" type="checkbox" ${b.allow_id_change === '1' ? 'checked' : ''}> Let students change their own Student ID</label></div>
    <div class="card"><h2>Your admin password</h2><p class="muted small">Change your own sign-in details under <a href="#/account">My Account</a>.</p></div>
    <button class="btn" style="align-self:flex-start">Save settings</button></div></form>`, adminTabs('brand'));
  let logo = b.logo_url;
  $('#ul').onclick = async () => { const f = await pickFile('image/png,image/jpeg,image/webp'); if (!f) return; try { const r = await upload(f, 'logo'); logo = r.url; $('#lp').src = U(logo); toast('Logo uploaded — press Save settings'); } catch (x) { fail(x); } };
  $('#rl').onclick = () => { logo = '/assets/logo.png'; $('#lp').src = U(logo); };
  $('#bf').onsubmit = async (e) => {
    e.preventDefault();
    try { const nb = await api('PUT', '/api/admin/settings', { brand_name: $('#b1').value, program_name: $('#b9').value, program_desc: $('#b10').value, tagline: $('#b2').value, logo_url: logo, color_primary: $('#b3').value, color_accent: $('#b4').value, welcome_text: $('#b5').value, support_text: $('#b6').value, lock_by_date: $('#b7').checked, allow_id_change: $('#b8').checked }); applyBrand(nb); toast('Settings saved'); adminBrand(); }
    catch (x) { fail(x); }
  };
}

/* ------------------------------------------------------------ router */
async function render() {
  try {
    if (!S.brand) applyBrand(await api('GET', '/api/brand'));
    if (S.me === null || S.me === undefined) { S.me = (await api('GET', '/api/me')).user; }
    if (!S.me) return loginView();
    if (S.me.must_change) return forcePasswordView();
    const h = location.hash || '#/'; const [, a, b, c] = h.split('/');
    const isAdmin = S.me.role === 'admin';
    if (a === 'admin') {
      if (!isAdmin) return go('#/');
      if (!b) return await adminOverview();
      if (b === 'content') return await adminContent();
      if (b === 'lesson') return await adminLesson(c);
      if (b === 'students') return await adminStudents();
      if (b === 'student') return await adminStudent(c);
      if (b === 'announcements') return await adminAnnouncements();
      if (b === 'brand') return await adminBrand();
    }
    if (a === 'module') return await moduleView(b);
    if (a === 'lesson') return await lessonView(b);
    if (a === 'account') return accountView();
    if (a === 'certificate') return await certificateView();
    if (a === 'program') await dashboardView(); else await programsView();
    window.scrollTo(0, 0);
  } catch (e) {
    if (S.me) { shell('home', `<div class="card"><h2>Something went wrong</h2><p>${esc(e.message)}</p><a class="btn" href="#/program">Back to modules</a></div>`); }
    else $('#app').innerHTML = `<div class="boot">${esc(e.message)}</div>`;
  }
}
window.addEventListener('hashchange', () => { $('#app').onclick = null; render(); });
render();
