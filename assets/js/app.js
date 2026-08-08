/* Apiary-Journal - application shell, views and forms. */

/* ------------------------------------------------------------------ utils */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function esc(v) {
  if (v === null || v === undefined) return '';
  return String(v).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function toast(message, bad = false) {
  const host = $('#toasts');
  const node = document.createElement('div');
  node.className = 'toast' + (bad ? ' toast--bad' : '');
  node.textContent = message;
  host.appendChild(node);
  setTimeout(() => node.remove(), 4200);
}

function showError(e) {
  const key = (e && e.message) || 'err.server_error';
  toast(t(key) + (e && e.detail ? ` (${e.detail})` : ''), true);
  if (key === 'err.auth_required' || key === 'err.csrf_invalid') {
    session.user = null;
    renderLogin();
  }
}

function localeTag() { return getLocale() === 'de' ? 'de-DE' : 'en-GB'; }

/** Parse a stored value as local time; a bare date keeps its calendar day. */
function parseLocal(v) {
  const s = String(v);
  // new Date("2025-05-01") means UTC midnight, which renders as 30 April in
  // any timezone west of Greenwich. A bare date must stay a calendar date.
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
  return m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(s.replace(' ', 'T'));
}

function fmtDate(v) {
  if (!v) return '';
  const d = parseLocal(v);
  if (isNaN(d)) return String(v).slice(0, 10);
  return d.toLocaleDateString(localeTag());
}

/**
 * Timestamps the server generates - last sign-in, audit log, backup times -
 * are UTC, because api/index.php pins PHP and MariaDB to UTC. Values the user
 * typed into a form are stored as entered and must not be shifted.
 */
function fmtServerDateTime(v) {
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T') + 'Z');
  if (isNaN(d)) return String(v);
  return d.toLocaleDateString(localeTag()) + ' '
       + d.toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
}

function fmtTime(v) {
  if (!v) return '';
  const d = parseLocal(v);
  if (isNaN(d)) return '';
  return d.toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
}

function fmtDateTime(v) {
  if (!v) return '';
  const d = parseLocal(v);
  if (isNaN(d)) return String(v);
  return d.toLocaleDateString(localeTag()) + ' '
       + d.toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
}

function toInputValue(v, type) {
  if (!v) return '';
  const s = String(v).replace(' ', 'T');
  return type === 'datetime' ? s.slice(0, 16) : s.slice(0, 10);
}

function nowLocal() {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}

function todayLocal() {
  return nowLocal().slice(0, 10);
}

function optLabel(group, value) {
  if (value === null || value === undefined || value === '') return '';
  if (group === 'locale_opts') return LOCALES[value] || value;
  return t(`opt.${group}.${value}`);
}

const state = {
  apiaries: [],
  colonies: [],
  users: [],
  route: '',
  reportFilter: null,
  reportView: 'detail',
  reportRows: [],
  groups: []
};

const canWrite = () => session.user && (session.user.role === 'admin' || session.user.role === 'beekeeper');
const isAdmin = () => session.user && session.user.role === 'admin';

/* ------------------------------------------------------------------- boot */

document.addEventListener('DOMContentLoaded', boot);

async function boot() {
  setLocale(getLocale());
  try {
    const me = await api('auth/me');
    session.weatherEnabled = !!me.weather;
    session.map = me.map || null;   // null when map tiles are switched off
    session.mail = !!me.mail;       // no mail configured, no reset link
    session.mode = me.mode || 'private';
    session.canRegister = !!me.can_register;
    session.legal = me.legal || {};

    // A reset link has to work before anyone is signed in, and takes
    // precedence over whatever session may still exist.
    const reset = /^#\/reset\/([a-f0-9]{64})$/.exec(location.hash);
    if (reset) {
      setLocale(me.locale || getLocale());
      renderReset(reset[1]);
      return;
    }

    // Confirming an address happens before the account can sign in, so this
    // has to run whether or not a session exists.
    const verify = /^#\/verify\/([a-f0-9]{64})$/.exec(location.hash);
    if (verify) {
      setLocale(me.locale || getLocale());
      await renderVerify(verify[1]);
      return;
    }
    // An invitation link works the same way before signing in.
    const invite = /^#\/invite\/([a-f0-9]{64})$/.exec(location.hash);
    if (invite && !me.user) {
      setLocale(me.locale || getLocale());
      renderInvite(invite[1]);
      return;
    }
    if (me.user) {
      session.user = me.user;
      session.csrf = me.csrf;
      setLocale(me.user.locale || getLocale());
      await startApp();
    } else {
      setLocale(me.locale || getLocale());
      renderLogin();
    }
  } catch (e) {
    renderLogin();
    showError(e);
  }
}

/* ------------------------------------------------------------------ login */

function renderLogin() {
  document.body.className = 'login-page';
  document.body.innerHTML = `
    <main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <form class="card" id="login-form" autocomplete="on">
        <h2>${esc(t('login.title'))}</h2>
        <p class="muted">${esc(t('login.hint'))}</p>
        <label>${esc(t('common.username'))}
          <input name="username" autocomplete="username" required autofocus>
        </label>
        <label style="margin-top:.7rem">${esc(t('common.password'))}
          <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <div class="form-actions" style="margin-top:1rem">
          ${session.mail ? `<button class="btn btn--ghost btn--sm" type="button" id="forgot">${esc(t('login.forgot'))}</button>` : ''}
          ${session.canRegister ? `<button class="btn btn--ghost btn--sm" type="button" id="register">${esc(t('register.link'))}</button>` : ''}
          <div style="flex:1"></div>
          <button class="btn btn--primary" type="submit">${esc(t('common.login'))}</button>
        </div>
      </form>
      <div class="lang-switch">${langButtons()}</div>
    </main>
    <div class="toast-host" id="toasts"></div>`;

  $('#forgot')?.addEventListener('click', renderForgot);
  $('#register')?.addEventListener('click', renderRegister);

  $('#login-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    try {
      const data = await api('auth/login', {
        username: fd.get('username'),
        password: fd.get('password')
      });
      session.user = data.user;
      session.csrf = data.csrf;
      setLocale(data.user.locale || getLocale());
      await startApp();
    } catch (e) {
      showError(e);
    }
  });

  bindLangSwitch(() => renderLogin());
}

/**
 * Registration, shown only where the server says it is open. The reply is the
 * same whether or not the name was free, so the form cannot be used to find
 * out who already has an account here.
 */
function renderRegister() {
  const legal = session.legal || {};
  document.body.className = 'login-page';
  document.body.innerHTML = `
    <main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <form class="card" id="register-form">
        <h2>${esc(t('register.title'))}</h2>
        <p class="muted">${esc(t('register.hint'))}</p>
        <label>${esc(t('common.username'))}
          <input name="username" required autofocus autocomplete="username"
                 pattern="[A-Za-z0-9._-]{3,60}" title="${esc(t('register.username_rule'))}">
        </label>
        <label style="margin-top:.7rem">${esc(t('field.full_name'))}
          <input name="full_name" autocomplete="name">
        </label>
        <label style="margin-top:.7rem">${esc(t('field.email'))}
          <input name="email" type="email" required autocomplete="email">
        </label>
        <label style="margin-top:.7rem">${esc(t('common.password'))}
          <input name="password" type="password" minlength="8" required autocomplete="new-password">
        </label>
        <p class="muted" style="margin:.2rem 0 0">${esc(t('users.password_hint_new'))}</p>

        <!-- Left out of sight on purpose: a person never fills this in, a
             script that submits every field it finds does. -->
        <div class="hp" aria-hidden="true">
          <label>Website<input name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <label class="check" style="margin-top:.9rem">
          <input type="checkbox" name="terms" required>
          <span>${t('register.terms_label', {
            terms: `<a href="${esc(legal.terms || 'legal/terms.html')}" target="_blank" rel="noopener">${esc(t('register.terms'))}</a>`,
            privacy: `<a href="${esc(legal.privacy || 'legal/privacy.html')}" target="_blank" rel="noopener">${esc(t('register.privacy'))}</a>`
          })}</span>
        </label>

        <div class="form-actions" style="margin-top:1rem">
          <button class="btn btn--ghost btn--sm" type="button" id="back">${esc(t('common.back'))}</button>
          <div style="flex:1"></div>
          <button class="btn btn--primary" type="submit">${esc(t('register.submit'))}</button>
        </div>
      </form>
    </main>
    <div class="toast-host" id="toasts"></div>`;

  $('#back').addEventListener('click', renderLogin);
  $('#register-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    try {
      await api('auth/register', {
        username: fd.get('username'),
        full_name: fd.get('full_name'),
        email: fd.get('email'),
        password: fd.get('password'),
        terms: fd.get('terms') ? 1 : 0,
        website: fd.get('website'),
        locale: getLocale()
      });
      renderLoginNotice(t('register.sent'));
    } catch (e) { showError(e); }
  });
}

/** Landing page of the confirmation link. */
async function renderVerify(token) {
  document.body.className = 'login-page';
  document.body.innerHTML = `
    <main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <div class="card"><p id="verify-msg">${esc(t('common.loading'))}</p></div>
    </main>
    <div class="toast-host" id="toasts"></div>`;
  try {
    await api('auth/verify', { token });
    history.replaceState(null, '', location.pathname + location.search);
    renderLoginNotice(t('register.verified'));
  } catch (e) {
    $('#verify-msg').textContent = t(e.message || 'err.verify_token_invalid');
  }
}

/** Ask for a reset link. The answer never says whether the account exists. */
function renderForgot() {
  document.body.className = 'login-page';
  document.body.innerHTML = `
    <main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <form class="card" id="forgot-form">
        <h2>${esc(t('login.forgot'))}</h2>
        <p class="muted">${esc(t('login.forgot_hint'))}</p>
        <label>${esc(t('login.forgot_login'))}
          <input name="login" required autofocus autocomplete="username">
        </label>
        <div class="form-actions" style="margin-top:1rem">
          <button class="btn btn--ghost btn--sm" type="button" id="back">${esc(t('common.back'))}</button>
          <div style="flex:1"></div>
          <button class="btn btn--primary" type="submit">${esc(t('login.forgot_send'))}</button>
        </div>
      </form>
    </main>
    <div class="toast-host" id="toasts"></div>`;

  $('#back').addEventListener('click', renderLogin);
  $('#forgot-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const login = new FormData(ev.target).get('login');
    try {
      await api('auth/forgot', { login });
      renderLoginNotice(t('login.forgot_sent'));
    } catch (e) { showError(e); }
  });
}

/** Set a new password from a link. Reached as #/reset/<token>. */
function renderReset(token) {
  document.body.className = 'login-page';
  document.body.innerHTML = `
    <main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <form class="card" id="reset-form">
        <h2>${esc(t('login.reset_title'))}</h2>
        <p class="muted">${esc(t('users.password_hint_new'))}</p>
        <label>${esc(t('profile.new_password'))}
          <input name="password" type="password" minlength="8" required autofocus autocomplete="new-password">
        </label>
        <label style="margin-top:.7rem">${esc(t('login.reset_repeat'))}
          <input name="password2" type="password" minlength="8" required autocomplete="new-password">
        </label>
        <div class="form-actions" style="margin-top:1rem">
          <button class="btn btn--ghost btn--sm" type="button" id="back">${esc(t('common.back'))}</button>
          <div style="flex:1"></div>
          <button class="btn btn--primary" type="submit">${esc(t('common.save'))}</button>
        </div>
      </form>
    </main>
    <div class="toast-host" id="toasts"></div>`;

  $('#back').addEventListener('click', () => { location.hash = ''; renderLogin(); });
  $('#reset-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    if (fd.get('password') !== fd.get('password2')) {
      toast(t('login.reset_mismatch'), true);
      return;
    }
    try {
      await api('auth/reset', { token, password: fd.get('password') });
      // Drop the token from the address bar before showing the login screen.
      history.replaceState(null, '', location.pathname + location.search);
      renderLoginNotice(t('login.reset_done'));
    } catch (e) { showError(e); }
  });
}

/** The login screen with a one-off message above the form. */
function renderLoginNotice(message) {
  renderLogin();
  const form = $('#login-form');
  const note = document.createElement('div');
  note.className = 'alert alert--ok';
  note.textContent = message;
  form.parentNode.insertBefore(note, form);
}

function langButtons() {
  return Object.entries(LOCALES).map(([code, label]) =>
    `<button type="button" data-lang="${code}" class="${code === getLocale() ? 'is-active' : ''}">${esc(label)}</button>`
  ).join('');
}

function bindLangSwitch(after) {
  $$('[data-lang]').forEach(b => b.addEventListener('click', async () => {
    setLocale(b.dataset.lang);
    if (session.user) {
      session.user.locale = getLocale();
      try {
        await api('profile/save', { record: { locale: getLocale(), full_name: session.user.full_name, email: session.user.email } });
      } catch (e) { /* language still switches locally */ }
    }
    after();
  }));
}

/* -------------------------------------------------------------- app shell */

async function startApp() {
  renderShell();
  await refreshLookups();
  window.addEventListener('hashchange', route);
  route();
}

/**
 * The frame around the view. It carries translated text of its own - the
 * subtitle under the app name, the role, the logout and menu buttons - so a
 * language switch has to redraw it, not just the navigation and the view.
 */
function renderShell() {
  document.body.className = '';
  document.body.innerHTML = `
    <div class="app" id="app">
      <aside class="sidebar">
        <div class="sidebar__brand">
          <span class="brand__mark"></span>
          <div><h1>${esc(t('app.title'))}</h1><small>${esc(t('app.subtitle'))}</small></div>
        </div>
        <nav class="nav" id="nav"></nav>
        <div class="sidebar__foot">
          <div>${esc(session.user.full_name || session.user.username)} · ${esc(optLabel('role', session.user.role))}</div>
          <div class="lang-switch" style="margin:.5rem 0">${langButtons()}</div>
          <button class="btn btn--sm" id="logout">${esc(t('common.logout'))}</button>
        </div>
      </aside>
      <main class="main">
        <button class="btn menu-toggle" id="menu-toggle" aria-controls="nav">☰ ${esc(t('common.menu'))}</button>
        <div id="view"></div>
      </main>
    </div>
    <div class="toast-host" id="toasts"></div>
    <dialog id="dialog"></dialog>`;

  renderNav();
  bindLangSwitch(() => { renderShell(); route(); });
  $('#menu-toggle').addEventListener('click', () => $('#app').classList.toggle('is-open'));
  $('#nav').addEventListener('click', () => $('#app').classList.remove('is-open'));
  $('#logout').addEventListener('click', async () => {
    try { await api('auth/logout'); } catch (e) { /* ignore */ }
    session.user = null; session.csrf = null;
    renderLogin();
  });
}

function renderNav() {
  const items = [
    ['journal', null],
    ['#/dashboard', 'nav.dashboard'],
    ['#/apiaries', 'nav.apiaries'],
    ['#/colonies', 'nav.colonies'],
    ['#/records/inspections', 'nav.inspections'],
    ['#/records/feedings', 'nav.feedings'],
    ['#/records/treatments', 'nav.treatments'],
    ['#/records/harvests', 'nav.harvests'],
    ['#/records/events', 'nav.events'],
    ['#/tasks', 'nav.tasks'],
    ['#/reports', 'nav.reports'],
    ['manage', null],
    ['#/groups', 'nav.groups'],
    ['#/profile', 'nav.profile']
  ];
  if (isAdmin()) {
    // Full snapshots do not exist in open mode, so the entry would lead
    // to a page that only reports a refusal.
    items.push(['#/users', 'nav.users']);
    if (session.mode !== 'open') items.push(['#/backup', 'nav.backup']);
    items.push(['#/log', 'nav.log']);
  }
  const current = location.hash || '#/dashboard';
  $('#nav').innerHTML = items.map(([href, key]) => {
    if (key === null) return `<div class="nav__section">${esc(t('nav.' + href))}</div>`;
    const active = current === href || (href !== '#/dashboard' && current.startsWith(href)) ? ' class="is-active"' : '';
    return `<a href="${href}"${active}>${esc(t(key))}</a>`;
  }).join('');
}

async function refreshLookups() {
  const [apiaries, colonies, users, groups] = await Promise.all([
    api('apiaries/list'),
    api('colonies/list', { limit: 2000 }),
    api('users/list'),
    api('groups/list')
  ]);
  state.apiaries = apiaries;
  state.colonies = colonies;
  state.users = users;
  state.groups = groups;
}

/** Groups the user may put records into - viewers cannot. */
function writableGroups() {
  return (state.groups || []).filter(g => g.my_role === 'owner' || g.my_role === 'member');
}

function colonyById(id) { return state.colonies.find(c => Number(c.id) === Number(id)); }
function apiaryById(id) { return state.apiaries.find(a => Number(a.id) === Number(id)); }

/* ----------------------------------------------------------------- router */

const ROUTES = [
  [/^#\/dashboard$/, viewDashboard],
  [/^#\/apiaries$/, viewApiaries],
  [/^#\/colonies$/, viewColonies],
  [/^#\/colony\/(\d+)$/, viewColony],
  [/^#\/records\/(\w+)$/, viewRecords],
  [/^#\/tasks$/, viewTasks],
  [/^#\/reports$/, viewReports],
  [/^#\/users$/, viewUsers],
  [/^#\/backup$/, viewBackup],
  [/^#\/log$/, viewLog],
  [/^#\/profile$/, viewProfile],
  [/^#\/groups$/, viewGroups],
  [/^#\/groups\/(\d+)$/, viewGroup],
  [/^#\/invite\/([a-f0-9]{64})$/, viewInvite]
];

async function route() {
  const hash = location.hash || '#/dashboard';
  state.route = hash;
  renderNav();
  $('#view').innerHTML = `<p class="muted">${esc(t('common.loading'))}</p>`;
  for (const [re, fn] of ROUTES) {
    const m = hash.match(re);
    if (m) {
      try {
        await fn(...m.slice(1));
      } catch (e) {
        showError(e);
        $('#view').innerHTML = `<div class="alert alert--bad">${esc(t(e.message || 'err.server_error'))}</div>`;
      }
      return;
    }
  }
  location.hash = '#/dashboard';
}

function topbar(title, actionsHtml = '') {
  return `<div class="topbar"><h1>${esc(title)}</h1><div class="topbar__spacer"></div>${actionsHtml}</div>`;
}

/* -------------------------------------------------------------- dashboard */

async function viewDashboard() {
  const [stats, recent, tasks] = await Promise.all([
    api('stats/summary'),
    api('stats/recent'),
    api('tasks/list', { status: 'open', limit: 8 })
  ]);

  // Each figure links to the page it was counted from.
  const stat = (value, label, { href = null, warn = false } = {}) => {
    const inner = `<div class="stat__value">${esc(value)}</div>
                   <div class="stat__label">${esc(label)}</div>`;
    const cls = `stat${warn ? ' stat--warn' : ''}${href ? ' stat--link' : ''}`;
    return href
      ? `<a class="${cls}" href="${esc(href)}">${inner}</a>`
      : `<div class="${cls}">${inner}</div>`;
  };

  $('#view').innerHTML =
    topbar(t('dashboard.title'),
      canWrite() ? `<button class="btn btn--primary" data-new="inspections">${esc(t('inspections.new'))}</button>` : '') +
    `<div class="grid grid--stats">
       ${stat(stats.colonies_active, t('dashboard.active_colonies'), { href: '#/colonies' })}
       ${stat(stats.apiaries, t('dashboard.apiaries'), { href: '#/apiaries' })}
       ${stat(stats.inspections_year, t('dashboard.inspections_year', { year: stats.year }), { href: '#/records/inspections' })}
       ${stat(stats.harvest_year_kg, t('dashboard.harvest_year', { year: stats.year }), { href: '#/records/harvests' })}
       ${feedStats(stats, stat)}
       ${stat(stats.tasks_open, t('dashboard.tasks_open'), { href: '#/tasks' })}
       ${stats.tasks_overdue ? stat(stats.tasks_overdue, t('dashboard.tasks_overdue'), { href: '#/tasks', warn: true }) : ''}
     </div>

     <div class="card" style="margin-top:1rem">
       <h2>${esc(t('dashboard.open_tasks'))}</h2>
       ${tasks.length ? `<ul class="timeline">${tasks.map(taskLine).join('')}</ul>`
                      : `<p class="muted">${esc(t('common.no_records'))}</p>`}
     </div>

     <div class="card">
       <h2>${esc(t('dashboard.recent'))}</h2>
       ${recent.length ? `<ul class="timeline">${recent.map(recentLine).join('')}</ul>`
                       : `<p class="muted">${esc(t('common.no_records'))}</p>`}
     </div>`;

  bindNewButtons();
}

function taskLine(task) {
  const overdue = task.due_date && task.status === 'open' && task.due_date < todayLocal();
  return `<li>
    <time>${esc(task.due_date ? fmtDate(task.due_date) : '—')}</time>
    <span class="what"><b>${esc(task.title)}</b>
      ${task.colony_name ? ` · ${esc(task.colony_name)}` : ''}
      ${overdue ? ` <span class="pill pill--dead">${esc(t('tasks.overdue'))}</span>` : ''}
    </span></li>`;
}

/* Syrup is fed by the litre, fondant by the kilo. Show whichever units were
   actually used, and fall back to a single kg tile when nothing was fed. */
function feedStats(stats, stat) {
  const kg = Number(stats.feed_year_kg) || 0;
  const l = Number(stats.feed_year_l) || 0;
  const out = [];
  const link = { href: '#/records/feedings' };
  if (kg > 0 || l === 0) out.push(stat(kg, t('dashboard.feed_year_kg', { year: stats.year }), link));
  if (l > 0) out.push(stat(l, t('dashboard.feed_year_l', { year: stats.year }), link));
  return out.join('');
}

/* A one-line summary of a record, built here rather than in SQL: only the
   browser knows that "syrup_3_2" is called "Zuckersirup 3:2". Values that
   speak for themselves are shown bare; ambiguous numbers get their label. */
function recordSummary(row) {
  const val = n => {
    const v = row[n];
    return v === null || v === undefined || v === '' ? null : v;
  };
  const num = n => {
    const v = val(n);
    return v === null ? null : String(v).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
  };
  const opt = (group, n) => (val(n) === null ? null : optLabel(group, row[n]));
  const labelled = (n, text) => (text === null ? null : `${t('field.' + n)}: ${text}`);
  const amount = (n, unitOpt) => {
    const a = num(n);
    if (a === null) return null;
    const u = unitOpt ? opt('unit', unitOpt) : null;
    return u ? `${a} ${u}` : a;
  };

  let parts;
  switch (row.record_type) {
    case 'inspections':
      parts = [
        labelled('strength_frames', num('strength_frames')),
        labelled('brood_frames', num('brood_frames')),
        val('queen_seen') && Number(row.queen_seen) ? t('field.queen_seen') : null,
        row.queen_cell_type && row.queen_cell_type !== 'none'
          ? labelled('queen_cell_type', opt('queen_cell_type', 'queen_cell_type')) : null,
        labelled('varroa_count', num('varroa_count')),
        opt('health_status', 'health_status')
      ];
      break;
    case 'feedings':
      parts = [opt('feed_type', 'feed_type'), amount('amount', 'unit')];
      break;
    case 'treatments':
      parts = [opt('treat_target', 'target'), val('product'),
               amount('dose', 'unit'), opt('treat_method', 'method')];
      break;
    case 'harvests':
      parts = [val('honey_type'),
               num('net_kg') === null ? null : `${num('net_kg')} kg`,
               num('water_content') === null ? null : `${num('water_content')} %`];
      break;
    case 'events':
      parts = [opt('event_type', 'event_type'), val('title')];
      break;
    case 'tasks':
      parts = [opt('task_status', 'status'), val('title')];
      break;
    default:
      parts = [];
  }
  // An event titled like its own type would otherwise be printed twice.
  return [...new Set(parts.filter(p => p !== null && p !== ''))].join(' · ');
}

function recentLine(row) {
  const summary = recordSummary(row);
  return `<li>
    <time>${esc(fmtDate(row.record_date))}</time>
    <span class="what">
      <span class="pill pill--type">${esc(t('type.' + row.record_type))}</span>
      ${row.colony_name ? ` <b>${esc(row.colony_name)}</b>` : ''}
      ${summary ? ` · ${esc(summary)}` : ''}
    </span></li>`;
}

/* --------------------------------------------------------------- apiaries */

async function viewApiaries() {
  const rows = await api('apiaries/list');
  state.apiaries = rows;

  $('#view').innerHTML =
    topbar(t('apiaries.title'),
      canWrite() ? `<button class="btn btn--primary" data-new="apiaries">${esc(t('apiaries.new'))}</button>` : '') +
    (rows.length ? `<div class="grid grid--cards">${rows.map(apiaryCard).join('')}</div>`
                 : emptyState(t('apiaries.title'), t('apiaries.empty')));

  bindNewButtons();
  $$('[data-edit-apiary]').forEach(b => b.addEventListener('click', async () => {
    const row = rows.find(r => String(r.id) === b.dataset.editApiary);
    await openForm('apiaries', row);
  }));
  $$('[data-del-apiary]').forEach(b => b.addEventListener('click', () => remove('apiaries', b.dataset.delApiary)));
}

function apiaryCard(a) {
  return `<div class="card">
    <h3>${esc(a.name)} ${a.code ? `<span class="colony__tag">${esc(a.code)}</span>` : ''}</h3>
    <div class="muted">${esc(a.address || '')}</div>
    <div class="colony__meta">
      <span>${esc(t('apiaries.colonies_here', { n: a.colony_count }))}</span>
      ${a.latitude !== null && a.longitude !== null
        ? `<span class="mono">${Number(a.latitude).toFixed(4)}, ${Number(a.longitude).toFixed(4)}</span>`
        : `<span class="pill pill--dead">${esc(t('apiaries.no_coords'))}</span>`}
      ${a.altitude ? `<span>${esc(a.altitude)} m</span>` : ''}
    </div>
    ${a.forage_notes ? `<p class="muted">${esc(a.forage_notes)}</p>` : ''}
    ${canWrite() ? `<div class="row-actions" style="margin-top:.6rem">
        <button class="btn btn--sm" data-edit-apiary="${a.id}">${esc(t('common.edit'))}</button>
        <button class="btn btn--sm btn--danger" data-del-apiary="${a.id}">${esc(t('common.delete'))}</button>
      </div>` : ''}
  </div>`;
}

/* --------------------------------------------------------------- colonies */

async function viewColonies() {
  const filters = state.colonyFilter || { apiary_id: '', status: 'active' };
  const rows = await api('colonies/list', { ...filters, limit: 2000 });
  state.colonies = await api('colonies/list', { limit: 2000 });

  $('#view').innerHTML =
    topbar(t('colonies.title'),
      canWrite() ? `<button class="btn btn--primary" data-new="colonies">${esc(t('colonies.new'))}</button>` : '') +
    `<div class="filters">
       <label>${esc(t('colonies.filter_apiary'))}
         <select id="f-apiary">${optionList(state.apiaries.map(a => [a.id, a.name]), filters.apiary_id, t('common.all'))}</select>
       </label>
       <label>${esc(t('colonies.filter_status'))}
         <select id="f-status">${optionList(OPTS.colony_status.map(s => [s, optLabel('colony_status', s)]), filters.status, t('common.all'))}</select>
       </label>
     </div>` +
    (rows.length ? `<div class="grid grid--cards">${rows.map(colonyCard).join('')}</div>`
                 : emptyState(t('colonies.title'), t('colonies.empty')));

  bindNewButtons();
  $('#f-apiary').addEventListener('change', e => {
    state.colonyFilter = { ...filters, apiary_id: e.target.value };
    viewColonies();
  });
  $('#f-status').addEventListener('change', e => {
    state.colonyFilter = { ...filters, status: e.target.value };
    viewColonies();
  });
  $$('[data-colony]').forEach(card => card.addEventListener('click', () => {
    location.hash = `#/colony/${card.dataset.colony}`;
  }));
}

function colonyCard(c) {
  const color = c.queen_color && c.queen_color !== 'unmarked' ? c.queen_color : queenColorForYear(c.queen_year);
  return `<article class="colony ${color ? 'colony--' + esc(color) : ''}" data-colony="${c.id}" tabindex="0">
    <div class="colony__head">
      <div style="flex:1">
        <div class="colony__name">${esc(c.name)}</div>
        <div class="muted">${esc(c.apiary_name || '')}</div>
      </div>
      ${c.tag_number ? `<span class="colony__tag">${esc(c.tag_number)}</span>` : ''}
    </div>
    <div class="colony__meta">
      <span class="pill pill--${esc(c.status)}">${esc(optLabel('colony_status', c.status))}</span>
      ${c.race ? `<span>${esc(optLabel('race', c.race))}</span>` : ''}
      ${c.queen_year ? `<span><span class="queen-dot queen-dot--${esc(color || 'white')}"></span> ${esc(t('colonies.queen'))} ${esc(c.queen_year)}</span>` : ''}
      <span>${esc(t('colonies.last_inspection'))}: ${c.last_inspection ? esc(fmtDate(c.last_inspection)) : esc(t('colonies.never'))}</span>
    </div>
  </article>`;
}

/** One label/value pair of a fact grid; empty values drop out entirely. */
function fact(label, valueHtml) {
  if (valueHtml === null || valueHtml === undefined || valueHtml === '') return '';
  return `<div><dt>${esc(label)}</dt><dd>${valueHtml}</dd></div>`;
}

async function viewColony(id) {
  const [colony] = await api('colonies/list', { id });
  if (!colony) { location.hash = '#/colonies'; return; }
  const tab = state.colonyTab || 'inspections';

  const color = colony.queen_color && colony.queen_color !== 'unmarked'
    ? colony.queen_color : queenColorForYear(colony.queen_year);

  $('#view').innerHTML =
    `<div class="topbar">
       <a class="btn btn--ghost btn--sm" href="#/colonies">← ${esc(t('common.back'))}</a>
       <h1>${esc(colony.name)}</h1>
       ${colony.tag_number ? `<span class="colony__tag">${esc(colony.tag_number)}</span>` : ''}
       <div class="topbar__spacer"></div>
       ${canWrite() ? `<button class="btn" id="edit-colony">${esc(t('common.edit'))}</button>` : ''}
     </div>
     <div class="card">
       <dl class="facts">
         ${fact(t('field.status'), `<span class="pill pill--${esc(colony.status)}">${esc(optLabel('colony_status', colony.status))}</span>`)}
         ${fact(t('field.apiary_id'), esc(colony.apiary_name))}
         ${fact(t('field.race'), esc(optLabel('race', colony.race)))}
         ${fact(t('field.origin'), esc(optLabel('origin', colony.origin)))}
         ${fact(t('field.hive_type'), esc(optLabel('hive_type', colony.hive_type)))}
         ${fact(t('field.frame_size'), esc(optLabel('frame_size', colony.frame_size)))}
         ${fact(t('field.box_count'), esc(colony.box_count))}
         ${fact(t('field.established_on'), colony.established_on ? esc(fmtDate(colony.established_on)) : '')}
         ${fact(t('colonies.queen'), colony.queen_id
            ? `<span class="queen-dot queen-dot--${esc(color || 'white')}"></span> ${esc(colony.queen_year || '')} ${esc(optLabel('race', colony.queen_race) || '')}`
            : `<span class="muted">${esc(t('colonies.no_queen'))}</span>`)}
       </dl>
       ${colony.notes ? `<p class="facts__notes">${esc(colony.notes)}</p>` : ''}
     </div>

     <div class="tabs" id="colony-tabs">
       ${['inspections', 'feedings', 'treatments', 'harvests', 'events', 'queens', 'tasks']
         .map(k => `<button data-tab="${k}" class="${k === tab ? 'is-active' : ''}">${esc(tabLabel(k))}</button>`).join('')}
     </div>
     <div id="colony-tab-body"></div>`;

  $('#edit-colony')?.addEventListener('click', async () => {
    if (await openForm('colonies', colony)) viewColony(id);
  });
  $$('#colony-tabs button').forEach(b => b.addEventListener('click', () => {
    state.colonyTab = b.dataset.tab;
    viewColony(id);
  }));

  await renderColonyTab(tab, Number(id));
}

async function renderColonyTab(entity, colonyId) {
  const rows = await api(`${entity}/list`, { colony_id: colonyId, limit: 500 });
  const host = $('#colony-tab-body');
  host.innerHTML =
    `<div class="topbar">
       <div class="topbar__spacer"></div>
       ${canWrite() ? `<button class="btn btn--primary btn--sm" data-new="${entity}" data-colony-id="${colonyId}">${esc(t(entity + '.new'))}</button>` : ''}
     </div>` +
    // On a colony page every row belongs to that colony already.
    recordTable(entity, rows, ['colony_name']);
  bindNewButtons(() => renderColonyTab(entity, colonyId));
  bindRowActions(entity, rows, () => renderColonyTab(entity, colonyId));
}

function tabLabel(key) {
  return key === 'queens' ? t('queens.title') : t('nav.' + key);
}

/* ---------------------------------------------------------- record tables */

/** `skip` drops columns that would repeat the same value on every row. */
function recordTable(entity, rows, skip = []) {
  const cols = (COLUMNS[entity] || []).filter(c => !skip.includes(c.n));
  if (!rows.length) return `<div class="card"><p class="muted">${esc(t('common.no_records'))}</p></div>`;

  // Date and time share one column and stack, in the header as well as in
  // the cells - it keeps the table narrow enough to avoid sideways scrolling.
  const head = cols.map(c => {
    const label = c.kind === 'datetime'
      ? `${esc(t('common.date'))}<br>${esc(t('common.time'))}`
      : esc(t(c.label || ('field.' + c.n)));
    // The span is what caps the width: a table cell ignores max-width.
    return `<th class="${cellClass(c)}"><span class="th-label">${label}</span></th>`;
  }).join('');
  const body = rows.map(r => `<tr data-id="${r.id}">
      ${cols.map(c => `<td class="${cellClass(c)}">${cellValue(r, c)}</td>`).join('')}
      <td><div class="row-actions">
        ${canWrite() ? `<button class="btn btn--sm" data-edit="${r.id}">${esc(t('common.edit'))}</button>
                        <button class="btn btn--sm btn--danger" data-del="${r.id}">${esc(t('common.delete'))}</button>` : ''}
      </div></td>
    </tr>`).join('');

  return `<div class="card"><div class="table-wrap"><table class="data">
      <thead><tr>${head}<th></th></tr></thead><tbody>${body}</tbody>
    </table></div></div>`;
}

function cellClass(c) {
  if (c.kind === 'num') return 'num';
  if (c.kind === 'date' || c.kind === 'datetime') return 'date';
  if (c.kind === 'text') return 'text';
  return '';
}

function cellValue(row, c) {
  const v = row[c.n];
  switch (c.kind) {
    case 'date': return esc(fmtDate(v));
    case 'datetime': {
      if (!v) return '';
      const time = fmtTime(v);
      return `${esc(fmtDate(v))}${time ? `<span class="cell-sub">${esc(time)}</span>` : ''}`;
    }
    case 'bool': return v === null || v === undefined || v === '' ? '' : (Number(v) ? esc(t('common.yes')) : esc(t('common.no')));
    case 'opt': return esc(optLabel(c.opts, v));
    case 'num': return v === null || v === undefined || v === '' ? '' : esc(String(v).replace(/\.00$/, '') + (c.suffix || ''));
    default: {
      const s = String(v ?? '');
      return esc(s.length > 90 ? s.slice(0, 90) + '…' : s);
    }
  }
}

function bindRowActions(entity, rows, refresh) {
  $$('[data-edit]').forEach(b => b.addEventListener('click', async () => {
    const row = rows.find(r => String(r.id) === b.dataset.edit);
    if (await openForm(entity, row)) refresh();
  }));
  $$('[data-del]').forEach(b => b.addEventListener('click', async () => {
    if (await remove(entity, b.dataset.del)) refresh();
  }));
}

function bindNewButtons(refresh) {
  $$('[data-new]').forEach(b => b.addEventListener('click', async () => {
    const entity = b.dataset.new;
    const preset = b.dataset.colonyId ? { colony_id: Number(b.dataset.colonyId) } : {};
    if (await openForm(entity, preset)) {
      if (refresh) refresh(); else route();
    }
  }));
}

async function remove(entity, id) {
  if (!confirm(t('common.confirm_delete'))) return false;
  try {
    await api(`${entity}/delete`, { id: Number(id) });
    toast(t('common.deleted'));
    if (entity === 'colonies' || entity === 'apiaries') await refreshLookups();
    return true;
  } catch (e) {
    showError(e);
    return false;
  }
}

/* --------------------------------------------------- generic record views */

async function viewRecords(entity) {
  if (!FORMS[entity]) { location.hash = '#/dashboard'; return; }
  const f = state.recordFilter?.[entity] || { colony_id: '', date_from: '', date_to: '' };
  const rows = await api(`${entity}/list`, { ...f, limit: 1000 });

  $('#view').innerHTML =
    topbar(t(entity + '.title'),
      canWrite() ? `<button class="btn btn--primary" data-new="${entity}">${esc(t(entity + '.new'))}</button>` : '') +
    `<div class="filters">
       <label>${esc(t('common.colony'))}
         <select id="f-colony">${optionList(state.colonies.map(c => [c.id, c.name]), f.colony_id, t('common.all'))}</select>
       </label>
       <label>${esc(t('common.from'))}<input type="date" id="f-from" value="${esc(f.date_from)}"></label>
       <label>${esc(t('common.to'))}<input type="date" id="f-to" value="${esc(f.date_to)}"></label>
       <div class="form-actions"><button class="btn" id="f-reset">${esc(t('common.reset'))}</button></div>
     </div>` +
    recordTable(entity, rows);

  const setFilter = patch => {
    state.recordFilter = state.recordFilter || {};
    state.recordFilter[entity] = { ...f, ...patch };
    viewRecords(entity);
  };
  $('#f-colony').addEventListener('change', e => setFilter({ colony_id: e.target.value }));
  $('#f-from').addEventListener('change', e => setFilter({ date_from: e.target.value }));
  $('#f-to').addEventListener('change', e => setFilter({ date_to: e.target.value }));
  $('#f-reset').addEventListener('click', () => setFilter({ colony_id: '', date_from: '', date_to: '' }));

  bindNewButtons(() => viewRecords(entity));
  bindRowActions(entity, rows, () => viewRecords(entity));
}

async function viewTasks() {
  const f = state.taskFilter || { status: 'open' };
  const rows = await api('tasks/list', { ...f, limit: 500 });

  $('#view').innerHTML =
    topbar(t('tasks.title'),
      canWrite() ? `<button class="btn btn--primary" data-new="tasks">${esc(t('tasks.new'))}</button>` : '') +
    `<div class="filters">
       <label>${esc(t('field.status'))}
         <select id="f-status">${optionList(OPTS.task_status.map(s => [s, optLabel('task_status', s)]), f.status, t('common.all'))}</select>
       </label>
     </div>` +
    recordTable('tasks', rows) +
    (canWrite() && rows.length ? `<p class="muted">${esc(t('tasks.mark_done'))}: ${esc(t('common.edit'))} → ${esc(t('field.status'))}</p>` : '');

  $('#f-status').addEventListener('change', e => {
    state.taskFilter = { status: e.target.value };
    viewTasks();
  });
  bindNewButtons(() => viewTasks());
  bindRowActions('tasks', rows, () => viewTasks());
}

/* ------------------------------------------------------------------ forms */

function optionList(pairs, selected, emptyLabel) {
  const head = `<option value="">${esc(emptyLabel ?? t('common.select'))}</option>`;
  return head + pairs.map(([value, label]) =>
    `<option value="${esc(value)}"${String(value) === String(selected ?? '') ? ' selected' : ''}>${esc(label)}</option>`
  ).join('');
}

function refPairs(ref) {
  if (ref === 'apiaries') return state.apiaries.map(a => [a.id, a.name]);
  if (ref === 'colonies') return state.colonies.map(c => [c.id, c.apiary_name ? `${c.name} (${c.apiary_name})` : c.name]);
  if (ref === 'users') return state.users.map(u => [u.id, u.full_name || u.username]);
  return [];
}

/* ------------------------------------------------------------------- map */
/* A minimal slippy map: enough to pan, zoom and click a coordinate, without
   pulling in a mapping library. Tiles come from the server configured in
   api/config.php; if that is switched off, session.map is null and the whole
   block is left out of the form. */

const TILE = 256;
const lon2x = (lon, z) => (lon + 180) / 360 * 2 ** z;
const lat2y = (lat, z) => {
  const r = lat * Math.PI / 180;
  return (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * 2 ** z;
};
const x2lon = (x, z) => x / 2 ** z * 360 - 180;
const y2lat = (y, z) => {
  const n = Math.PI - 2 * Math.PI * y / 2 ** z;
  return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
};

function createMiniMap(box, { lat, lon, zoom, onPick }) {
  const cfg = session.map;
  const layer = box.querySelector('.map__layer');
  const pin = box.querySelector('.map__pin');
  let z = zoom;
  let cx = lon2x(lon, z);          // centre, in fractional tile units
  let cy = lat2y(lat, z);
  let marker = null;               // {lat, lon} once something is picked

  const draw = () => {
    const w = box.clientWidth || 320;
    const h = box.clientHeight || 260;
    const n = 2 ** z;
    const cols = Math.ceil(w / TILE) + 2;
    const rows = Math.ceil(h / TILE) + 2;
    const x0 = Math.floor(cx - cols / 2);
    const y0 = Math.floor(cy - rows / 2);

    let html = '';
    for (let dy = 0; dy <= rows; dy++) {
      for (let dx = 0; dx <= cols; dx++) {
        const tx = x0 + dx, ty = y0 + dy;
        if (ty < 0 || ty >= n) continue;
        const wrapped = ((tx % n) + n) % n;   // the world repeats sideways
        const left = Math.round((tx - cx) * TILE + w / 2);
        const top = Math.round((ty - cy) * TILE + h / 2);
        const url = cfg.tile_url.replace('{z}', z).replace('{x}', wrapped).replace('{y}', ty);
        // No loading="lazy" here: every tile in the grid is on screen by
        // construction, and lazy loading leaves blank patches while panning.
        html += `<img src="${esc(url)}" alt="" draggable="false"
                      style="left:${left}px;top:${top}px">`;
      }
    }
    layer.style.transform = '';
    layer.innerHTML = html;

    if (marker) {
      pin.hidden = false;
      pin.style.left = Math.round((lon2x(marker.lon, z) - cx) * TILE + w / 2) + 'px';
      pin.style.top = Math.round((lat2y(marker.lat, z) - cy) * TILE + h / 2) + 'px';
    } else {
      pin.hidden = true;
    }
  };

  /* Dragging moves the tile layer with a transform and only re-tiles on
     release, so panning stays smooth on a NAS-served page. */
  let drag = null;
  box.addEventListener('pointerdown', e => {
    if (e.target.closest('.map__zoom')) return;
    drag = { x: e.clientX, y: e.clientY, dx: 0, dy: 0 };
    box.setPointerCapture(e.pointerId);
  });
  box.addEventListener('pointermove', e => {
    if (!drag) return;
    drag.dx = e.clientX - drag.x;
    drag.dy = e.clientY - drag.y;
    layer.style.transform = `translate(${drag.dx}px, ${drag.dy}px)`;
    if (pin && !pin.hidden) pin.style.transform = `translate(calc(-50% + ${drag.dx}px), calc(-100% + ${drag.dy}px))`;
  });
  box.addEventListener('pointerup', e => {
    if (!drag) return;
    const moved = Math.abs(drag.dx) + Math.abs(drag.dy);
    const d = drag;
    drag = null;
    if (pin) pin.style.transform = '';
    if (moved > 4) {
      cx -= d.dx / TILE;
      cy -= d.dy / TILE;
      draw();
      return;
    }
    // A click, not a drag: pick the coordinate under the pointer.
    const r = box.getBoundingClientRect();
    const px = e.clientX - r.left, py = e.clientY - r.top;
    const picked = {
      lat: y2lat(cy + (py - r.height / 2) / TILE, z),
      lon: x2lon(cx + (px - r.width / 2) / TILE, z)
    };
    marker = picked;
    draw();
    onPick(picked);
  });
  box.addEventListener('pointercancel', () => { drag = null; layer.style.transform = ''; });

  box.addEventListener('wheel', e => {
    e.preventDefault();
    setZoom(z + (e.deltaY < 0 ? 1 : -1));
  }, { passive: false });

  function setZoom(next) {
    const clamped = Math.max(2, Math.min(cfg.max_zoom || 19, next));
    if (clamped === z) return;
    const lat = y2lat(cy, z), lon = x2lon(cx, z);
    z = clamped;
    cx = lon2x(lon, z);
    cy = lat2y(lat, z);
    draw();
  }
  box.querySelectorAll('.map__zoom button').forEach(b =>
    b.addEventListener('click', () => setZoom(z + Number(b.dataset.dz))));

  /* The box has no width while the dialog is still being built, and it
     changes again when the window is resized - re-tile whenever that
     happens instead of relying on a single well-timed draw. */
  let lastW = 0, lastH = 0;
  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(() => {
      if (box.clientWidth === lastW && box.clientHeight === lastH) return;
      lastW = box.clientWidth;
      lastH = box.clientHeight;
      if (lastW > 0) draw();
    }).observe(box);
  }

  const api = {
    draw,
    goTo(nlat, nlon, nzoom) {
      if (nzoom) z = Math.max(2, Math.min(cfg.max_zoom || 19, nzoom));
      cx = lon2x(nlon, z);
      cy = lat2y(nlat, z);
      marker = { lat: nlat, lon: nlon };
      draw();
    },
    setMarker(nlat, nlon) { marker = { lat: nlat, lon: nlon }; draw(); }
  };
  draw();
  return api;
}

function mapBoxHtml() {
  if (!session.map) return '';
  return `<div class="map" id="geo-map">
      <div class="map__layer"></div>
      <div class="map__pin" hidden></div>
      <div class="map__zoom">
        <button type="button" data-dz="1" aria-label="+">+</button>
        <button type="button" data-dz="-1" aria-label="&minus;">&minus;</button>
      </div>
      <div class="map__attr">${esc(session.map.attribution || '')}</div>
    </div>
    <p class="muted map__hint">${esc(t('apiaries.map_hint'))}</p>`;
}

function fieldHtml(f, record) {
  if (f.section) return `<div class="fieldset-title">${esc(t(f.section))}</div>`;
  if (f.t === 'weather') return weatherBlockHtml(record);
  if (f.t === 'geo') {
    return `<div class="full">
      <label for="geo-q">${esc(t('apiaries.geo_search'))}
        <span style="display:flex;gap:.4rem">
          <input id="geo-q" type="search" placeholder="${esc(t('apiaries.geo_hint'))}">
          <button type="button" class="btn" id="geo-go">${esc(t('common.search'))}</button>
        </span>
      </label>
      <div id="geo-results" class="geo-hits"></div>
      ${mapBoxHtml()}
    </div>`;
  }

  const label = esc(t(f.label || ('field.' + f.n)));
  const req = f.req ? ' required' : '';
  const id = 'fld-' + f.n;
  let value = record?.[f.n];
  if ((value === undefined || value === null || value === '') && record?.id === undefined && f.def !== undefined) {
    value = f.def;
  }
  const wrap = inner => `<label class="${f.full ? 'full' : ''}" for="${id}">${label}${f.req ? ' *' : ''}${inner}
      ${f.hint ? `<span class="muted">${esc(t(f.hint))}</span>` : ''}</label>`;

  switch (f.t) {
    case 'textarea':
      return wrap(`<textarea id="${id}" name="${f.n}"${req}>${esc(value ?? '')}</textarea>`);
    case 'number':
      return wrap(`<input id="${id}" name="${f.n}" type="number" inputmode="decimal"
        ${f.step ? `step="${f.step}"` : ''} ${f.min !== undefined ? `min="${f.min}"` : ''}
        ${f.max !== undefined ? `max="${f.max}"` : ''} value="${esc(value ?? '')}"${req}>`);
    case 'date':
      return wrap(`<input id="${id}" name="${f.n}" type="date" value="${esc(toInputValue(value, 'date') || (f.today && record?.id === undefined ? todayLocal() : ''))}"${req}>`);
    case 'datetime':
      return wrap(`<input id="${id}" name="${f.n}" type="datetime-local" value="${esc(toInputValue(value, 'datetime') || (f.now && record?.id === undefined ? nowLocal() : ''))}"${req}>`);
    case 'password':
      return wrap(`<input id="${id}" name="${f.n}" type="password" autocomplete="new-password" value="">`);
    case 'check':
      return `<label class="check ${f.full ? 'full' : ''}"><input id="${id}" name="${f.n}" type="checkbox"${Number(value) ? ' checked' : ''}> ${label}</label>`;
    // Who else sees this apiary or colony. Everything below a colony follows
    // the colony, so the choice is only offered on those two.
    case 'group': {
      const groups = writableGroups();
      const pairs = groups.map(g => [g.id, g.name]);
      const hint = groups.length
        ? t('groups.share_hint')
        : t('groups.share_none');
      return wrap(
        `<select id="${id}" name="${f.n}">${optionList(pairs, value, t('groups.private'))}</select>
         <small class="muted">${esc(hint)}</small>`
      );
    }
    case 'select': {
      const pairs = f.opts === 'locale_opts'
        ? Object.entries(LOCALES)
        : (OPTS[f.opts] || []).map(o => [o, optLabel(f.opts, o)]);
      return wrap(`<select id="${id}" name="${f.n}"${req}>${optionList(pairs, value, t('common.select'))}</select>`);
    }
    case 'ref':
      return wrap(`<select id="${id}" name="${f.n}"${req}>${optionList(refPairs(f.ref), value, t('common.select'))}</select>`);
    default:
      return wrap(`<input id="${id}" name="${f.n}" type="text" value="${esc(value ?? '')}"${req}>`);
  }
}

function weatherBlockHtml(record) {
  return `<div class="weather" id="weather-block">
    <div class="weather__head">
      <span>${esc(t('weather.title'))}</span>
      ${session.weatherEnabled ? `<button type="button" class="btn btn--sm" id="weather-fetch">${esc(t('weather.fetch'))}</button>` : ''}
    </div>
    <div class="weather__values" id="weather-values">${weatherValuesHtml(record)}</div>
  </div>`;
}

function weatherValuesHtml(w) {
  if (!w || w.weather_temp === null || w.weather_temp === undefined || w.weather_temp === '') {
    return `<span class="muted">${esc(session.weatherEnabled ? t('weather.auto') : t('err.weather_unavailable'))}</span>`;
  }
  const parts = [
    `<span>${esc(t('weather.temp'))}: <b>${esc(w.weather_temp)} °C</b></span>`,
    w.weather_humidity != null ? `<span>${esc(t('weather.humidity'))}: <b>${esc(w.weather_humidity)} %</b></span>` : '',
    w.weather_wind != null ? `<span>${esc(t('weather.wind'))}: <b>${esc(w.weather_wind)} km/h</b></span>` : '',
    w.weather_cloud != null ? `<span>${esc(t('weather.cloud'))}: <b>${esc(w.weather_cloud)} %</b></span>` : '',
    w.weather_precip != null ? `<span>${esc(t('weather.precip'))}: <b>${esc(w.weather_precip)} mm</b></span>` : '',
    w.weather_pressure != null ? `<span>${esc(t('weather.pressure'))}: <b>${esc(w.weather_pressure)} hPa</b></span>` : '',
    w.weather_code != null ? `<span>${esc(weatherText(Number(w.weather_code)))}</span>` : ''
  ];
  return parts.filter(Boolean).join('');
}

/**
 * Opens a modal form for one record. Resolves to true when something was
 * saved, false when the dialog was dismissed.
 */
function openForm(entity, record) {
  return new Promise(resolve => {
    const fields = FORMS[entity];
    const isNew = !record || record.id === undefined;
    const dlg = $('#dialog');
    const weather = {
      weather_temp: record?.weather_temp ?? null,
      weather_humidity: record?.weather_humidity ?? null,
      weather_wind: record?.weather_wind ?? null,
      weather_wind_dir: record?.weather_wind_dir ?? null,
      weather_cloud: record?.weather_cloud ?? null,
      weather_precip: record?.weather_precip ?? null,
      weather_pressure: record?.weather_pressure ?? null,
      weather_code: record?.weather_code ?? null,
      weather_source: record?.weather_source ?? null
    };

    dlg.innerHTML = `
      <form method="dialog" id="record-form">
        <div class="dialog__head">
          <h2>${esc(isNew ? t(entity + '.new') : t('common.edit'))}</h2>
        </div>
        <div class="dialog__body">
          <div class="form-grid">
            ${fields.map(f => fieldHtml(f, record)).join('')}
            <div class="form-actions">
              <button type="button" class="btn" id="form-cancel">${esc(t('common.cancel'))}</button>
              <button type="submit" class="btn btn--primary">${esc(t('common.save'))}</button>
            </div>
          </div>
        </div>
      </form>`;

    const form = $('#record-form', dlg);

    // --- weather wiring ---------------------------------------------------
    const dateInput = $('[name="inspected_at"]', form);
    const colonyInput = $('[name="colony_id"]', form);
    const values = $('#weather-values', form);

    async function fetchWeather(silent) {
      if (!session.weatherEnabled || !values) return;
      const colonyId = colonyInput?.value;
      const at = dateInput?.value;
      if (!colonyId || !at) return;
      values.innerHTML = `<span class="muted">${esc(t('weather.pending'))}</span>`;
      try {
        const w = await api('weather/get', { colony_id: Number(colonyId), at });
        weather.weather_temp = w.temp;
        weather.weather_humidity = w.humidity;
        weather.weather_wind = w.wind;
        weather.weather_wind_dir = w.wind_dir;
        weather.weather_cloud = w.cloud;
        weather.weather_precip = w.precip;
        weather.weather_pressure = w.pressure;
        weather.weather_code = w.code;
        weather.weather_source = w.source;
        values.innerHTML = weatherValuesHtml(weather) +
          `<span class="muted">${esc(w.source === 'archive' ? t('weather.source_archive') : t('weather.source_forecast'))}</span>`;
      } catch (e) {
        values.innerHTML = `<span class="muted">${esc(t(e.message))}</span>`;
        if (!silent) showError(e);
      }
    }

    if (values) {
      $('#weather-fetch', form)?.addEventListener('click', () => fetchWeather(false));
      colonyInput?.addEventListener('change', () => fetchWeather(true));
      dateInput?.addEventListener('change', () => fetchWeather(true));
      if (isNew) setTimeout(() => fetchWeather(true), 150);
    }

    // --- address search and click map (apiaries) --------------------------
    const geoGo = $('#geo-go', form);
    if (geoGo) {
      const latInput = form.querySelector('[name="latitude"]');
      const lonInput = form.querySelector('[name="longitude"]');
      const out = $('#geo-results', form);
      let miniMap = null;

      const setCoords = (lat, lon, alt) => {
        latInput.value = Number(lat).toFixed(6);
        lonInput.value = Number(lon).toFixed(6);
        if (alt != null) form.querySelector('[name="altitude"]').value = Math.round(alt);
      };

      const runSearch = async () => {
        const q = $('#geo-q', form).value.trim();
        if (!q) return;
        out.textContent = t('common.loading');
        try {
          const hits = await api('geo/search', { q });
          out.innerHTML = hits.length
            ? hits.map((h, i) => `<button type="button" class="geo-hit" data-geo="${i}">
                 <b>${esc(h.name)}</b>${h.admin ? `<span>${esc(h.admin)}</span>` : ''}</button>`).join('')
            : `<span class="muted">${esc(t('apiaries.geo_none'))}</span>`;
          $$('[data-geo]', out).forEach(b => b.addEventListener('click', () => {
            const h = hits[Number(b.dataset.geo)];
            setCoords(h.latitude, h.longitude, h.altitude);
            if (!form.querySelector('[name="address"]').value) {
              form.querySelector('[name="address"]').value = [h.name, h.admin].filter(Boolean).join(', ');
            }
            // Zoom in far enough that the exact spot can be corrected by hand.
            miniMap?.goTo(h.latitude, h.longitude, 16);
            out.innerHTML = '';
          }));
        } catch (e) {
          out.textContent = t(e.message);
        }
      };
      geoGo.addEventListener('click', runSearch);
      $('#geo-q', form).addEventListener('keydown', ev => {
        if (ev.key === 'Enter') { ev.preventDefault(); runSearch(); }
      });

      const mapBox = $('#geo-map', form);
      if (mapBox && session.map) {
        const has = latInput.value && lonInput.value;
        // Fall back to an apiary that already has coordinates, so a second
        // apiary starts near the first one instead of somewhere in the sea.
        const near = state.apiaries.find(a => a.latitude != null && a.longitude != null);
        const start = has
          ? { lat: Number(latInput.value), lon: Number(lonInput.value), z: 16 }
          : near
            ? { lat: Number(near.latitude), lon: Number(near.longitude), z: 11 }
            : { lat: 51.16, lon: 10.45, z: 5 };

        miniMap = createMiniMap(mapBox, {
          lat: start.lat, lon: start.lon, zoom: start.z,
          onPick: p => setCoords(p.lat, p.lon)
        });
        if (has) miniMap.setMarker(start.lat, start.lon);

        // Typing coordinates by hand keeps the map in sync.
        [latInput, lonInput].forEach(i => i.addEventListener('change', () => {
          const la = Number(latInput.value), lo = Number(lonInput.value);
          if (Number.isFinite(la) && Number.isFinite(lo) && latInput.value && lonInput.value) {
            miniMap.goTo(la, lo);
          }
        }));
        // The dialog is not laid out yet while we build it, so the first
        // tiling has to wait for its real size.
        requestAnimationFrame(() => miniMap.draw());
      }
    }

    // --- submit -----------------------------------------------------------
    const close = saved => {
      dlg.close();
      dlg.innerHTML = '';
      resolve(saved);
    };

    $('#form-cancel', form).addEventListener('click', () => close(false));
    dlg.addEventListener('cancel', ev => { ev.preventDefault(); close(false); }, { once: true });

    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      const data = { ...(record?.id ? { id: record.id } : {}) };
      for (const f of fields) {
        if (!f.n || f.t === 'weather') continue;
        const input = form.querySelector(`[name="${f.n}"]`);
        if (!input) continue;
        data[f.n] = f.t === 'check' ? (input.checked ? 1 : 0) : input.value;
      }
      if (entity === 'inspections') Object.assign(data, weather);
      if (entity === 'colonies' && record?.id === undefined && !data.status) data.status = 'active';

      try {
        await api(`${entity}/save`, { record: data });
        toast(t('common.saved'));
        if (entity === 'colonies' || entity === 'apiaries' || entity === 'users') await refreshLookups();
        close(true);
      } catch (e) {
        showError(e);
      }
    });

    dlg.showModal();
  });
}

/* ---------------------------------------------------------------- reports */

async function viewReports() {
  const f = state.reportFilter || {
    types: [...REPORT_TYPES],
    apiary_id: '', colony_id: '', user_id: '',
    date_from: `${new Date().getFullYear()}-01-01`,
    date_to: '', search: ''
  };
  state.reportFilter = f;

  $('#view').innerHTML =
    topbar(t('reports.title'),
      `<button class="btn" id="rep-print">${esc(t('common.print'))}</button>
       <button class="btn" id="rep-csv">${esc(t('common.export_csv'))}</button>`) +
    `<p class="muted no-print">${esc(t('reports.hint'))}</p>
     <div class="filters">
       <label>${esc(t('common.apiary'))}
         <select id="r-apiary">${optionList(state.apiaries.map(a => [a.id, a.name]), f.apiary_id, t('common.all'))}</select>
       </label>
       <label>${esc(t('common.colony'))}
         <select id="r-colony">${optionList(state.colonies.map(c => [c.id, c.name]), f.colony_id, t('common.all'))}</select>
       </label>
       <label>${esc(t('common.user'))}
         <select id="r-user">${optionList(state.users.map(u => [u.id, u.full_name || u.username]), f.user_id, t('common.all'))}</select>
       </label>
       <label>${esc(t('common.from'))}<input type="date" id="r-from" value="${esc(f.date_from)}"></label>
       <label>${esc(t('common.to'))}<input type="date" id="r-to" value="${esc(f.date_to)}"></label>
       <label>${esc(t('reports.search'))}<input type="search" id="r-search" value="${esc(f.search)}"></label>
       <div class="full">
         <div class="muted" style="margin-bottom:.3rem">${esc(t('reports.types'))}</div>
         <div style="display:flex;flex-wrap:wrap;gap:.8rem">
           ${REPORT_TYPES.map(ty => `<label class="check"><input type="checkbox" data-type="${ty}"${f.types.includes(ty) ? ' checked' : ''}> ${esc(t('type.' + ty))}</label>`).join('')}
         </div>
       </div>
       <div class="full">
         <div class="muted" style="margin-bottom:.3rem">${esc(t('reports.view'))}</div>
         <div style="display:flex;flex-wrap:wrap;gap:.8rem">
           ${[['detail', 'reports.view_detail'], ['table', 'reports.view_table']].map(([v, key]) =>
             `<label class="check"><input type="radio" name="r-view" value="${v}"${state.reportView === v ? ' checked' : ''}> ${esc(t(key))}</label>`).join('')}
         </div>
       </div>
       <div class="full" style="display:flex;flex-wrap:wrap;gap:.4rem">
         <button class="btn btn--sm" data-range="this_year">${esc(t('reports.this_year'))}</button>
         <button class="btn btn--sm" data-range="last_year">${esc(t('reports.last_year'))}</button>
         <button class="btn btn--sm" data-range="season">${esc(t('reports.season'))}</button>
         <div style="flex:1"></div>
         <button class="btn btn--primary" id="r-run">${esc(t('reports.run'))}</button>
       </div>
     </div>
     <div id="report-out"><p class="muted">${esc(t('common.loading'))}</p></div>`;

  const read = () => ({
    types: $$('[data-type]').filter(c => c.checked).map(c => c.dataset.type),
    apiary_id: $('#r-apiary').value,
    colony_id: $('#r-colony').value,
    user_id: $('#r-user').value,
    date_from: $('#r-from').value,
    date_to: $('#r-to').value,
    search: $('#r-search').value
  });

  $('#r-run').addEventListener('click', () => { state.reportFilter = read(); runReport(); });
  $$('[name="r-view"]').forEach(r => r.addEventListener('change', () => {
    state.reportView = r.value;
    state.reportFilter = read();
    runReport();
  }));
  $$('[data-range]').forEach(b => b.addEventListener('click', () => {
    const y = new Date().getFullYear();
    const ranges = {
      this_year: [`${y}-01-01`, `${y}-12-31`],
      last_year: [`${y - 1}-01-01`, `${y - 1}-12-31`],
      season: [`${y}-03-01`, `${y}-09-30`]
    };
    const [from, to] = ranges[b.dataset.range];
    $('#r-from').value = from;
    $('#r-to').value = to;
    state.reportFilter = read();
    runReport();
  }));
  $('#rep-print').addEventListener('click', () => window.print());
  $('#rep-csv').addEventListener('click', exportReportCsv);

  await runReport();
}

/** The filter written out, so a printed report says what it contains. */
function reportHeadHtml(count) {
  const f = state.reportFilter || {};
  const nameOf = (list, id, key = 'name') =>
    (list.find(x => String(x.id) === String(id)) || {})[key] || '';

  const period = f.date_from || f.date_to
    ? `${f.date_from ? fmtDate(f.date_from) : '…'} – ${f.date_to ? fmtDate(f.date_to) : '…'}`
    : t('reports.filter_none');

  const parts = [[t('reports.period'), period]];
  if (f.apiary_id) parts.push([t('common.apiary'), nameOf(state.apiaries, f.apiary_id)]);
  if (f.colony_id) parts.push([t('common.colony'), nameOf(state.colonies, f.colony_id)]);
  if (f.user_id) {
    const u = state.users.find(x => String(x.id) === String(f.user_id)) || {};
    parts.push([t('common.user'), u.full_name || u.username || '']);
  }
  if (f.search) parts.push([t('reports.search'), f.search]);
  parts.push([t('reports.types'), (f.types || []).map(ty => t('type.' + ty)).join(', ') || '–']);

  return `<div class="report-head">
      <h2>${esc(t('reports.rows', { n: count }))}</h2>
      <dl class="report-head__meta">
        ${parts.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}
      </dl>
      <p class="report-head__stamp">${esc(t('reports.generated', { date: fmtDateTime(new Date().toISOString()) }))}</p>
    </div>`;
}

/** One value of a full protocol record, formatted for reading. */
function detailValue(row, f) {
  if (f.t === 'ref') {
    return row[{ colonies: 'colony_name', apiaries: 'apiary_name', users: 'user_name' }[f.ref]] || '';
  }
  const v = row[f.n];
  if (v === null || v === undefined || v === '') return '';
  switch (f.t) {
    case 'check': return Number(v) ? t('common.yes') : t('common.no');
    case 'select': return optLabel(f.opts, v);
    case 'date': return fmtDate(v);
    case 'datetime': return fmtDateTime(v);
    // MariaDB returns DECIMAL as "8.50"; drop the zeros it padded on.
    case 'number': return String(v).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    default: return String(v);
  }
}

/** Every filled field of one record, grouped by the sections of its form. */
function detailBlockHtml(row) {
  const type = row.record_type;
  const skip = new Set(['colony_id', 'apiary_id', REPORT_DATE_FIELD[type]]);
  const groups = [];
  let current = { title: '', items: [] };

  for (const f of FORMS[type] || []) {
    if (f.section) {
      groups.push(current);
      current = { title: t(f.section), items: [] };
      continue;
    }
    if (skip.has(f.n)) continue;

    if (f.t === 'weather') {
      if (row.weather_temp !== null && row.weather_temp !== undefined && row.weather_temp !== '') {
        // The values are bare spans; .weather__values is what spaces them out.
        current.items.push({
          label: t('weather.title'),
          html: `<div class="weather__values">${weatherValuesHtml(row)}</div>`,
          wide: true
        });
      }
      continue;
    }
    const value = detailValue(row, f);
    if (value === '') continue;
    current.items.push({
      label: t('field.' + f.n),
      html: esc(value),
      wide: f.t === 'textarea'
    });
  }
  groups.push(current);

  const dateField = (FORMS[type] || []).find(f => f.n === REPORT_DATE_FIELD[type]);
  const dateText = dateField && dateField.t === 'datetime'
    ? fmtDateTime(row.record_date) : fmtDate(row.record_date);
  const place = [row.colony_name, row.apiary_name].filter(Boolean).join(' · ');
  const body = groups.filter(g => g.items.length).map(g => `
      ${g.title ? `<h4>${esc(g.title)}</h4>` : ''}
      <dl class="record__fields">
        ${g.items.map(i => `<div${i.wide ? ' class="wide"' : ''}><dt>${esc(i.label)}</dt><dd>${i.html}</dd></div>`).join('')}
      </dl>`).join('');

  return `<article class="record">
      <header class="record__head">
        <span class="pill pill--type">${esc(t('type.' + type))}</span>
        <b>${esc(dateText)}</b>
        ${place ? `<span>${esc(place)}</span>` : ''}
        ${row.user_name ? `<span class="muted">${esc(row.user_name)}</span>` : ''}
      </header>
      ${body || `<p class="muted">${esc(t('reports.no_fields'))}</p>`}
    </article>`;
}

/**
 * One CSV cell.
 *
 * Excel and LibreOffice execute a value starting with =, +, - or @ as a
 * formula, so those get a leading apostrophe. Everything is quoted, and a
 * quote inside the value is doubled.
 */
function csvCell(value) {
  let s = value === null || value === undefined ? '' : String(value);
  if (s !== '' && '=+-@\t\r'.includes(s[0])) {
    s = "'" + s;
  }
  return '"' + s.replace(/"/g, '""') + '"';
}

/**
 * The report as CSV, built from the rows already on screen. Assembling it here
 * rather than on the server is what lets option values appear in the chosen
 * language - the database only stores keys like "syrup_3_2".
 */
function exportReportCsv() {
  const rows = state.reportRows || [];
  if (!rows.length) {
    toast(t('reports.empty'), true);
    return;
  }
  const head = [
    t('common.date'), t('common.type'), t('common.apiary'), t('common.colony'),
    t('common.user'), t('common.summary'), t('field.notes')
  ];
  const lines = [head.map(csvCell).join(';')];
  for (const r of rows) {
    lines.push([
      fmtDate(r.record_date),
      t('type.' + r.record_type),
      r.apiary_name || '',
      r.colony_name || '',
      r.user_name || '',
      recordSummary(r),
      r.notes ?? r.description ?? ''
    ].map(csvCell).join(';'));
  }
  // The BOM is what makes Excel read the file as UTF-8.
  const blob = new Blob(['﻿' + lines.join('\r\n') + '\r\n'],
    { type: 'text/csv;charset=utf-8' });
  saveBlob(blob, `apiary-journal-report-${todayStamp()}.csv`);
}

function todayStamp() {
  const d = new Date();
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

async function runReport() {
  const out = $('#report-out');
  out.innerHTML = `<p class="muted">${esc(t('common.loading'))}</p>`;
  const detail = state.reportView === 'detail';
  // Both views read full records: the table's summary column is composed
  // here so that option values appear in the chosen language.
  const data = await api('reports/detail', { filter: state.reportFilter });
  state.reportRows = data.rows;   // the CSV export builds on these
  const s = data.summary;

  const chip = (label, value) =>
    value === null || value === undefined ? '' :
    `<div class="stat"><div class="stat__value">${esc(value)}</div><div class="stat__label">${esc(label)}</div></div>`;

  const noTypes = !(state.reportFilter.types || []).length;

  const rowsHtml = !data.rows.length
    ? `<p class="muted">${esc(t(noTypes ? 'reports.no_types' : 'reports.empty'))}</p>`
    : detail
      ? `<div class="records">${data.rows.map(detailBlockHtml).join('')}</div>`
      : `<div class="table-wrap"><table class="data">
          <thead><tr>
            <th>${esc(t('common.date'))}</th><th>${esc(t('common.type'))}</th>
            <th>${esc(t('common.apiary'))}</th><th>${esc(t('common.colony'))}</th>
            <th>${esc(t('common.summary'))}</th><th>${esc(t('field.notes'))}</th>
            <th>${esc(t('common.user'))}</th>
          </tr></thead>
          <tbody>${data.rows.map(r => `<tr>
            <td class="date">${esc(fmtDate(r.record_date))}</td>
            <td><span class="pill pill--type">${esc(t('type.' + r.record_type))}</span></td>
            <td>${esc(r.apiary_name || '')}</td>
            <td>${esc(r.colony_name || '')}</td>
            <td>${esc(recordSummary(r))}</td>
            <td>${esc(r.notes ?? r.description ?? '')}</td>
            <td>${esc(r.user_name || '')}</td>
          </tr>`).join('')}</tbody></table></div>`;

  const chips = [
    chip(t('inspections.title'), s.inspections),
    chip(t('reports.varroa_avg'), s.varroa_avg),
    chip(t('reports.varroa_max'), s.varroa_max),
    chip(t('feedings.title'), s.feedings),
    chip(t('reports.feed_kg'), s.feed_kg),
    chip(t('reports.feed_l'), s.feed_l || null),
    chip(t('treatments.title'), s.treatments),
    chip(t('reports.harvest_kg'), s.harvest_kg),
    chip(t('reports.water_avg'), s.water_avg),
    chip(t('events.title'), s.events)
  ].join('');

  out.innerHTML =
    reportHeadHtml(data.rows.length) +
    (chips ? `<div class="grid grid--stats" style="margin-bottom:1rem">${chips}</div>` : '') +
    `<div class="card">${rowsHtml}</div>`;
}

/* ------------------------------------------------------------------ users */

async function viewUsers() {
  const rows = await api('users/list');
  state.users = rows;

  $('#view').innerHTML =
    topbar(t('users.title'), `<button class="btn btn--primary" data-new="users">${esc(t('users.new'))}</button>`) +
    `<div class="card"><div class="table-wrap"><table class="data">
      <thead><tr>
        <th>${esc(t('common.username'))}</th><th>${esc(t('field.full_name'))}</th>
        <th>${esc(t('field.email'))}</th><th>${esc(t('field.role'))}</th>
        <th>${esc(t('field.locale'))}</th><th>${esc(t('field.is_active'))}</th>
        <th>${esc(t('users.last_login'))}</th><th></th>
      </tr></thead>
      <tbody>${rows.map(u => `<tr>
        <td class="mono">${esc(u.username)}</td>
        <td>${esc(u.full_name || '')}</td>
        <td>${esc(u.email || '')}</td>
        <td>${esc(optLabel('role', u.role))}</td>
        <td>${esc(LOCALES[u.locale] || u.locale)}</td>
        <td>${Number(u.is_active) ? esc(t('common.yes')) : esc(t('common.no'))}</td>
        <td class="date">${u.last_login_at ? esc(fmtServerDateTime(u.last_login_at)) : esc(t('users.never'))}</td>
        <td><div class="row-actions">
          <button class="btn btn--sm" data-edit-user="${u.id}">${esc(t('common.edit'))}</button>
          <button class="btn btn--sm btn--danger" data-del-user="${u.id}">${esc(t('common.delete'))}</button>
        </div></td>
      </tr>`).join('')}</tbody></table></div></div>`;

  $$('[data-new]').forEach(b => b.addEventListener('click', async () => {
    if (await openForm('users', {})) viewUsers();
  }));
  $$('[data-edit-user]').forEach(b => b.addEventListener('click', async () => {
    const u = rows.find(r => String(r.id) === b.dataset.editUser);
    if (await openForm('users', u)) viewUsers();
  }));
  $$('[data-del-user]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm(t('common.confirm_delete'))) return;
    try {
      await api('users/delete', { id: Number(b.dataset.delUser) });
      toast(t('common.deleted'));
      viewUsers();
    } catch (e) { showError(e); }
  }));
}

async function viewProfile() {
  const u = session.user;
  $('#view').innerHTML =
    topbar(t('profile.title')) +
    `<form class="card form-grid" id="profile-form">
       <label class="full">${esc(t('common.username'))}<input value="${esc(u.username)}" disabled></label>
       <label>${esc(t('field.full_name'))}<input name="full_name" value="${esc(u.full_name || '')}"></label>
       <label>${esc(t('field.email'))}<input name="email" type="email" value="${esc(u.email || '')}"></label>
       <label>${esc(t('field.locale'))}
         <select name="locale">${optionList(Object.entries(LOCALES), u.locale, null)}</select>
       </label>
       <div class="fieldset-title">${esc(t('profile.change_password'))}</div>
       <label>${esc(t('profile.current_password'))}<input name="current_password" type="password" autocomplete="current-password"></label>
       <label>${esc(t('profile.new_password'))}<input name="new_password" type="password" autocomplete="new-password"></label>
       <div class="form-actions"><button class="btn btn--primary" type="submit">${esc(t('common.save'))}</button></div>
     </form>`;

  $('#view').insertAdjacentHTML('beforeend', `
    <div class="card">
      <h2>${esc(t('account.export_title'))}</h2>
      <p class="muted">${esc(t('account.export_hint'))}</p>
      <div class="form-actions"><button class="btn" id="acc-export">${esc(t('account.export'))}</button></div>
    </div>
    <div class="card">
      <h2>${esc(t('account.delete_title'))}</h2>
      <p class="muted">${esc(t('account.delete_hint'))}</p>
      <div class="form-actions"><button class="btn btn--danger" id="acc-delete">${esc(t('account.delete'))}</button></div>
    </div>`);

  $('#acc-export').addEventListener('click', async () => {
    try {
      await apiDownload('account/export', {}, 'apiary-journal-export.json');
    } catch (e) { showError(e); }
  });

  $('#acc-delete').addEventListener('click', openDeleteAccount);

  $('#profile-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    try {
      const data = await api('profile/save', { record: Object.fromEntries(fd.entries()) });
      session.user = data.user;
      setLocale(data.user.locale);
      toast(t('common.saved'));
      // Redraw the frame for the new language; startApp() would attach a
      // second hashchange listener each time.
      renderShell();
      route();
    } catch (e) { showError(e); }
  });
}

/* ----------------------------------------------------------------- groups */

async function viewGroups() {
  const groups = await api('groups/list');
  state.groups = groups;

  $('#view').innerHTML =
    topbar(t('groups.title'), `<button class="btn btn--primary" id="new-group">${esc(t('groups.new'))}</button>`) +
    `<p class="muted">${esc(t('groups.intro'))}</p>` +
    (groups.length
      ? `<div class="grid grid--cards">${groups.map(groupCard).join('')}</div>`
      : `<div class="card"><p class="muted">${esc(t('groups.empty'))}</p></div>`);

  $('#new-group').addEventListener('click', () => editGroup(null));
  $$('[data-group]').forEach(el => el.addEventListener('click', () => {
    location.hash = '#/groups/' + el.dataset.group;
  }));
}

function groupCard(g) {
  return `<article class="colony" data-group="${g.id}" tabindex="0">
    <div class="colony__head">
      <div style="flex:1"><div class="colony__name">${esc(g.name)}</div>
        <div class="muted">${esc(g.description || '')}</div></div>
      <span class="pill">${esc(t('groups.role_' + g.my_role))}</span>
    </div>
    <div class="colony__meta">
      <span>${esc(t('groups.members', { n: g.member_count }))}</span>
    </div>
  </article>`;
}

async function editGroup(group) {
  const saved = await openSimpleForm(
    group ? t('groups.edit') : t('groups.new'),
    [
      { n: 'name', label: t('field.name'), value: group ? group.name : '', required: true },
      { n: 'description', label: t('groups.description'), value: group ? group.description : '' }
    ],
    data => api('groups/save', { record: Object.assign({}, data, { id: group ? group.id : null }) })
  );
  if (saved) {
    await refreshLookups();
    viewGroups();
  }
}

async function viewGroup(id) {
  id = Number(id);
  const [data, groups] = await Promise.all([
    api('groups/members', { group_id: id }),
    api('groups/list')
  ]);
  state.groups = groups;
  const group = groups.find(g => Number(g.id) === id);
  if (!group) { location.hash = '#/groups'; return; }
  const isOwner = data.my_role === 'owner';
  const meId = session.user.id;

  $('#view').innerHTML =
    `<div class="topbar">
       <a class="btn btn--ghost btn--sm" href="#/groups">&larr; ${esc(t('common.back'))}</a>
       <h1>${esc(group.name)}</h1>
       <div class="topbar__spacer"></div>
       ${isOwner ? `<button class="btn" id="edit-group">${esc(t('common.edit'))}</button>
                    <button class="btn btn--danger" id="del-group">${esc(t('common.delete'))}</button>` : ''}
       <button class="btn btn--danger" id="leave-group">${esc(t('groups.leave'))}</button>
     </div>
     ${group.description ? `<p class="muted">${esc(group.description)}</p>` : ''}

     <div class="card">
       <h2>${esc(t('groups.members_title'))}</h2>
       <div class="table-wrap"><table class="data">
         <thead><tr><th>${esc(t('field.name'))}</th><th>${esc(t('groups.role'))}</th><th></th></tr></thead>
         <tbody>${data.members.map(m => `<tr>
           <td>${esc(m.name)}</td>
           <td>${isOwner && Number(m.user_id) !== meId
                 ? `<select data-role-for="${m.user_id}">${optionList(
                      ['owner', 'member', 'viewer'].map(r => [r, t('groups.role_' + r)]), m.role)}</select>`
                 : esc(t('groups.role_' + m.role))}</td>
           <td class="row-actions">${isOwner && Number(m.user_id) !== meId
                 ? `<button class="btn btn--sm btn--danger" data-remove="${m.user_id}">${esc(t('groups.remove'))}</button>`
                 : ''}</td>
         </tr>`).join('')}</tbody>
       </table></div>
     </div>

     ${isOwner ? `<div class="card">
       <h2>${esc(t('groups.invite_title'))}</h2>
       <p class="muted">${esc(session.mail ? t('groups.invite_hint') : t('groups.invite_no_mail'))}</p>
       ${session.mail ? `<div class="filters">
         <label>${esc(t('groups.invite_email'))}<input type="email" id="inv-email" placeholder="name@example.org"></label>
         <label>${esc(t('groups.role'))}
           <select id="inv-role">${optionList(['member', 'viewer', 'owner'].map(r => [r, t('groups.role_' + r)]), 'member')}</select>
         </label>
         <div class="full"><button class="btn btn--primary" id="inv-send">${esc(t('groups.invite_send'))}</button></div>
       </div>` : ''}
       ${data.invites.length ? `<div class="table-wrap"><table class="data">
         <thead><tr><th>${esc(t('groups.invite_email'))}</th><th>${esc(t('groups.role'))}</th>
           <th>${esc(t('groups.invite_until'))}</th><th></th></tr></thead>
         <tbody>${data.invites.map(i => `<tr>
           <td>${esc(i.email)}</td><td>${esc(t('groups.role_' + i.role))}</td>
           <td class="date">${esc(fmtServerDateTime(i.expires_at))}</td>
           <td class="row-actions"><button class="btn btn--sm btn--danger" data-revoke="${i.id}">${esc(t('groups.invite_revoke'))}</button></td>
         </tr>`).join('')}</tbody>
       </table></div>` : ''}
     </div>` : ''}`;

  $('#edit-group')?.addEventListener('click', () => editGroup(group));
  $('#del-group')?.addEventListener('click', async () => {
    if (!confirm(t('groups.confirm_delete'))) return;
    try {
      await api('groups/delete', { id });
      toast(t('common.deleted'));
      await refreshLookups();
      location.hash = '#/groups';
    } catch (e) { showError(e); }
  });
  $('#leave-group').addEventListener('click', async () => {
    if (!confirm(t('groups.confirm_leave'))) return;
    try {
      await api('groups/member_remove', { group_id: id });
      toast(t('groups.left'));
      await refreshLookups();
      location.hash = '#/groups';
    } catch (e) { showError(e); }
  });
  $$('[data-role-for]').forEach(sel => sel.addEventListener('change', async () => {
    try {
      await api('groups/member_role', { group_id: id, user_id: Number(sel.dataset.roleFor), role: sel.value });
      toast(t('common.saved'));
    } catch (e) { showError(e); viewGroup(id); }
  }));
  $$('[data-remove]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm(t('groups.confirm_remove'))) return;
    try {
      await api('groups/member_remove', { group_id: id, user_id: Number(b.dataset.remove) });
      viewGroup(id);
    } catch (e) { showError(e); }
  }));
  $('#inv-send')?.addEventListener('click', async () => {
    const email = $('#inv-email').value.trim();
    if (!email) return;
    try {
      await api('groups/invite', { group_id: id, email, role: $('#inv-role').value });
      toast(t('groups.invite_sent'));
      viewGroup(id);
    } catch (e) { showError(e); }
  });
  $$('[data-revoke]').forEach(b => b.addEventListener('click', async () => {
    try {
      await api('groups/invite_revoke', { id: Number(b.dataset.revoke) });
      viewGroup(id);
    } catch (e) { showError(e); }
  }));
}

/**
 * An invitation opened while signed in. The signed-out case is renderInvite()
 * below, which shows the same thing without the application around it.
 */
async function viewInvite(token) {
  let info;
  try {
    info = await api('groups/invite_preview', { token });
  } catch (e) {
    $('#view').innerHTML = `<div class="alert alert--bad">${esc(t('err.invite_invalid'))}</div>
      <a class="btn" href="#/groups">${esc(t('groups.title'))}</a>`;
    return;
  }

  $('#view').innerHTML =
    topbar(t('groups.invite_heading')) +
    `<div class="card">
       <p>${esc(t('groups.invite_body', { group: info.group, role: t('groups.role_' + info.role) }))}</p>
       <p class="muted">${esc(t('groups.invite_for', { email: info.email }))}</p>
       <div class="form-actions" style="margin-top:1rem">
         <a class="btn" href="#/groups">${esc(t('common.cancel'))}</a>
         <div style="flex:1"></div>
         <button class="btn btn--primary" id="accept">${esc(t('groups.invite_accept'))}</button>
       </div>
     </div>`;

  $('#accept').addEventListener('click', async () => {
    try {
      const r = await api('groups/invite_accept', { token });
      toast(t('groups.joined', { group: r.group }));
      await refreshLookups();
      renderNav();
      location.hash = '#/groups/' + r.group_id;
    } catch (e) { showError(e); }
  });
}

/**
 * Following an invitation link. Shown before signing in as well, so the
 * recipient can see what they are being asked to join.
 */
async function renderInvite(token) {
  let info;
  try {
    info = await api('groups/invite_preview', { token });
  } catch (e) {
    document.body.className = 'login-page';
    document.body.innerHTML = `<main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <div class="card"><div class="alert alert--bad">${esc(t('err.invite_invalid'))}</div>
      <button class="btn" id="go" style="margin-top:1rem">${esc(t('common.login'))}</button></div>
    </main><div class="toast-host" id="toasts"></div>`;
    $('#go').addEventListener('click', () => { location.hash = ''; renderLogin(); });
    return;
  }

  document.body.className = 'login-page';
  document.body.innerHTML = `<main class="login">
      <div class="login__brand"><span class="brand__mark"></span><h1>${esc(t('app.title'))}</h1></div>
      <div class="card">
        <h2>${esc(t('groups.invite_heading'))}</h2>
        <p>${esc(t('groups.invite_body', { group: info.group, role: t('groups.role_' + info.role) }))}</p>
        <p class="muted">${esc(t('groups.invite_for', { email: info.email }))}</p>
        <div class="form-actions" style="margin-top:1rem">
          <button class="btn" id="cancel">${esc(t('common.cancel'))}</button>
          <div style="flex:1"></div>
          <button class="btn btn--primary" id="accept">
            ${esc(info.signed_in ? t('groups.invite_accept') : t('common.login'))}</button>
        </div>
      </div>
    </main><div class="toast-host" id="toasts"></div>`;

  $('#cancel').addEventListener('click', () => { location.hash = ''; boot(); });
  $('#accept').addEventListener('click', async () => {
    // Not signed in yet. The invitation stays in the address bar, so once
    // the session exists the router opens viewInvite() and the visitor
    // carries on where they left off.
    if (!info.signed_in) { renderLogin(); return; }
    try {
      const r = await api('groups/invite_accept', { token });
      toast(t('groups.joined', { group: r.group }));
      await startApp();
      location.hash = '#/groups/' + r.group_id;
    } catch (e) { showError(e); }
  });
}

/** A small modal form, for the few places that do not need the full builder. */
function openSimpleForm(title, fields, onSubmit) {
  return new Promise(resolve => {
    const dlg = $('#dialog');
    dlg.innerHTML = `<form class="dialog-form">
      <h2>${esc(title)}</h2>
      ${fields.map(f => `<label>${esc(f.label)}
        <input name="${esc(f.n)}" value="${esc(f.value || '')}"${f.required ? ' required' : ''}>
      </label>`).join('')}
      <div class="form-actions" style="margin-top:1rem">
        <button type="button" class="btn" id="sf-cancel">${esc(t('common.cancel'))}</button>
        <div style="flex:1"></div>
        <button type="submit" class="btn btn--primary">${esc(t('common.save'))}</button>
      </div>
    </form>`;
    const form = dlg.querySelector('form');
    $('#sf-cancel', dlg).addEventListener('click', () => { dlg.close(); resolve(false); });
    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      try {
        await onSubmit(data);
        toast(t('common.saved'));
        dlg.close();
        resolve(true);
      } catch (e) { showError(e); }
    });
    dlg.showModal();
  });
}

/**
 * Deleting takes effect at once, so it asks for the password and for a typed
 * word - a misplaced click should not be able to do this.
 */
function openDeleteAccount() {
  const dlg = $('#dialog');
  dlg.innerHTML = `<form class="dialog-form" id="del-form">
      <h2>${esc(t('account.delete_title'))}</h2>
      <p class="muted">${esc(t('account.delete_warning'))}</p>
      <label>${esc(t('common.password'))}
        <input name="password" type="password" required autocomplete="current-password">
      </label>
      <label>${esc(t('account.delete_confirm_label', { word: 'DELETE' }))}
        <input name="confirm" required autocomplete="off" placeholder="DELETE">
      </label>
      <div class="form-actions">
        <button class="btn" type="button" id="del-cancel">${esc(t('common.cancel'))}</button>
        <div style="flex:1"></div>
        <button class="btn btn--danger" type="submit">${esc(t('account.delete'))}</button>
      </div>
    </form>`;
  dlg.showModal();

  $('#del-cancel').addEventListener('click', () => { dlg.close(); dlg.innerHTML = ''; });
  $('#del-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    try {
      await api('account/delete', { password: fd.get('password'), confirm: fd.get('confirm') });
      dlg.close();
      dlg.innerHTML = '';
      session.user = null;
      session.csrf = null;
      location.hash = '';
      renderLoginNotice(t('account.deleted'));
    } catch (e) { showError(e); }
  });
}

/* ----------------------------------------------------------------- backup */

async function viewBackup() {
  const data = await api('backup/list');

  $('#view').innerHTML =
    topbar(t('backup.title'),
      `<button class="btn btn--primary" id="bk-create">${esc(t('backup.create'))}</button>`) +
    `<div class="card">
       <h2>${esc(t('backup.list'))}</h2>
       <p class="muted">${esc(t('backup.dir'))}: <span class="mono">${esc(data.dir)}</span></p>
       ${data.files.length ? `<div class="table-wrap"><table class="data">
         <thead><tr><th>${esc(t('backup.created_at'))}</th><th>${esc(t('field.name'))}</th>
           <th class="num">${esc(t('backup.size'))}</th><th></th></tr></thead>
         <tbody>${data.files.map(f => `<tr>
           <td class="date">${esc(fmtServerDateTime(f.created))}</td>
           <td class="mono">${esc(f.name)}</td>
           <td class="num">${(f.size / 1024).toFixed(1)} KB</td>
           <td><div class="row-actions">
             <button class="btn btn--sm" data-dl="${esc(f.name)}">${esc(t('backup.download'))}</button>
             <button class="btn btn--sm" data-restore="${esc(f.name)}">${esc(t('backup.restore'))}</button>
             <button class="btn btn--sm btn--danger" data-delbk="${esc(f.name)}">${esc(t('common.delete'))}</button>
           </div></td></tr>`).join('')}</tbody></table></div>`
        : `<p class="muted">${esc(t('backup.empty'))}</p>`}
     </div>

     <div class="card">
       <h2>${esc(t('backup.upload'))}</h2>
       <div class="form-grid">
         <label class="full"><input type="file" id="bk-file" accept=".gz,.json"></label>
         <label class="check full"><input type="checkbox" id="bk-keep-users" checked> ${esc(t('backup.keep_users'))}</label>
         <div class="form-actions">
           <button class="btn" id="bk-sql">${esc(t('backup.sql'))}</button>
           <button class="btn btn--primary" id="bk-upload">${esc(t('backup.upload'))}</button>
         </div>
       </div>
     </div>`;

  $('#bk-create').addEventListener('click', async () => {
    try {
      const r = await api('backup/create');
      toast(t('backup.created', { file: r.file }));
      viewBackup();
    } catch (e) { showError(e); }
  });

  $('#bk-sql')?.addEventListener('click', async () => {
    try {
      await apiDownload('backup/sql', {}, 'apiary-journal.sql');
    } catch (e) { showError(e); }
  });

  $$('[data-dl]').forEach(b => b.addEventListener('click', async () => {
    try {
      await apiDownload('backup/download', { file: b.dataset.dl }, b.dataset.dl);
    } catch (e) { showError(e); }
  }));

  $$('[data-restore]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm(t('backup.confirm_restore'))) return;
    try {
      await api('backup/restore', { file: b.dataset.restore, keep_users: $('#bk-keep-users').checked });
      toast(t('backup.restored'));
      await refreshLookups();
      location.hash = '#/dashboard';
    } catch (e) { showError(e); }
  }));

  $$('[data-delbk]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm(t('common.confirm_delete'))) return;
    try {
      await api('backup/delete', { file: b.dataset.delbk });
      viewBackup();
    } catch (e) { showError(e); }
  }));

  $('#bk-upload').addEventListener('click', async () => {
    const file = $('#bk-file').files[0];
    if (!file) { toast(t('err.no_file'), true); return; }
    try {
      await apiUpload('backup/upload', file);
      toast(t('common.saved'));
      viewBackup();
    } catch (e) { showError(e); }
  });
}

async function viewLog() {
  const rows = await api('log/list');
  $('#view').innerHTML =
    topbar(t('log.title')) +
    `<div class="card"><div class="table-wrap"><table class="data">
      <thead><tr><th>${esc(t('log.when'))}</th><th>${esc(t('common.user'))}</th>
        <th>${esc(t('log.action'))}</th><th>${esc(t('log.entity'))}</th>
        <th>${esc(t('log.detail'))}</th><th>IP</th></tr></thead>
      <tbody>${rows.map(r => `<tr>
        <td class="date">${esc(fmtServerDateTime(r.created_at))}</td>
        <td>${esc(r.username || '')}</td>
        <td class="mono">${esc(r.action)}</td>
        <td>${esc(r.entity || '')}${r.entity_id ? ' #' + esc(r.entity_id) : ''}</td>
        <td>${esc(r.detail || '')}</td>
        <td class="mono">${esc(r.ip || '')}</td>
      </tr>`).join('')}</tbody></table></div></div>`;
}

/* ------------------------------------------------------------------ misc. */

function emptyState(title, text) {
  return `<div class="card empty">
    <div class="empty__title">${esc(title)}</div>
    <p class="muted">${esc(text)}</p>
  </div>`;
}
