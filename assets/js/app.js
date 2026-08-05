/* ==========================================================
   Imkerei-Tagebuch – Frontend-Logik (Vanilla JS, kein Build nötig)
   ========================================================== */

const CSRF_TOKEN = window.CSRF_TOKEN;
const CURRENT_USER = window.CURRENT_USER;

/* ---------------- API-Client ---------------- */
async function api(res, action, { method = 'GET', params = {}, body = null } = {}) {
    const query = new URLSearchParams({ res, action, ...params }).toString();
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json' },
    };
    if (method !== 'GET') opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
    if (body) opts.body = JSON.stringify(body);

    const r = await fetch(`api.php?${query}`, opts);
    let data;
    try { data = await r.json(); } catch { data = {}; }
    if (!r.ok || data.error) {
        throw new Error(data.error || `Fehler (${r.status})`);
    }
    return data.data;
}

function toast(msg, type = 'success') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast ' + type;
    el.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { el.hidden = true; }, 3200);
}

/* ---------------- Konstanten / Dropdown-Optionen ---------------- */
const RASSEN = ['Carnica', 'Buckfast', 'Ligustica (Italienerbiene)', 'Mellifera (Dunkle Biene)', 'Elgon', 'Andere/Mischling'];
const BEUTENTYPEN = ['Zander', 'Deutsch Normal', 'Dadant', 'Segeberger', 'Langstroth', 'Warré', 'Andere'];
const HERKUNFT_VOLK = ['Schwarm', 'Kauf', 'Ableger', 'Teilung', 'Kunstschwarm'];
const VOLK_STATUS = { aktiv: 'Aktiv', ueberwintert: 'Überwintert', aufgeloest: 'Aufgelöst', verkauft: 'Verkauft', verloren: 'Verloren' };
const FARBEN_KOENIGIN = ['Weiß (0/5)', 'Gelb (1/6)', 'Rot (2/7)', 'Grün (3/8)', 'Blau (4/9)'];
const FUTTERARTEN = ['Zuckerwasser 1:1', 'Zuckerwasser 3:2', 'Futterteig', 'Invertzuckersirup', 'Honig (volkseigen)', 'Andere'];
const BEHANDLUNGSMITTEL = ['Ameisensäure 60% (Verdunster)', 'Oxalsäure (Träufeln)', 'Oxalsäure (Sublimation)', 'Milchsäure', 'Thymolpräparat', 'Andere'];
const WEISELRICHTIG = { ja: 'Ja', nein: 'Nein', unsicher: 'Unsicher' };
const VARROA_STUFEN = { keiner: 'Keiner erkennbar', gering: 'Gering', mittel: 'Mittel', hoch: 'Hoch', unbekannt: 'Unbekannt' };

function opts(list, selected) {
    return list.map(v => `<option value="${esc(v)}" ${v === selected ? 'selected' : ''}>${esc(v)}</option>`).join('');
}
function optsMap(map, selected) {
    return Object.entries(map).map(([k, v]) => `<option value="${esc(k)}" ${k === selected ? 'selected' : ''}>${esc(v)}</option>`).join('');
}
function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmtDate(d) {
    if (!d) return '–';
    const [y, m, day] = d.split('-');
    return `${day}.${m}.${y}`;
}
function fmtDateTime(dt) {
    if (!dt) return '–';
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}
function todayISO() { return new Date().toISOString().slice(0, 10); }

/* ---------------- Modal ---------------- */
const modalOverlay = document.getElementById('modalOverlay');
const modalBody = document.getElementById('modalBody');
const modalTitle = document.getElementById('modalTitle');

function openModal(title, html) {
    modalTitle.textContent = title;
    modalBody.innerHTML = html;
    modalOverlay.hidden = false;
}
function closeModal() {
    modalOverlay.hidden = true;
    modalBody.innerHTML = '';
}
document.getElementById('modalClose').addEventListener('click', closeModal);
modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) closeModal(); });

/* ---------------- Sidebar (mobil) ---------------- */
const sidebar = document.getElementById('sidebar');
document.getElementById('menuToggle').addEventListener('click', () => sidebar.classList.toggle('open'));
document.querySelectorAll('.nav-link').forEach(a => a.addEventListener('click', () => sidebar.classList.remove('open')));

/* ---------------- Logout ---------------- */
document.getElementById('logoutBtn').addEventListener('click', async () => {
    await api('auth', 'logout', { method: 'POST' });
    window.location.href = 'login.php';
});

/* ---------------- Cache für Dropdown-Daten ---------------- */
let CACHE = { standorte: [], voelker: [] };
async function loadBaseData() {
    CACHE.standorte = await api('standorte', 'list');
    CACHE.voelker = await api('voelker', 'list');
}
function standortName(id) { return CACHE.standorte.find(s => +s.id === +id)?.name || '–'; }
function volkLabel(v) { return `${v.bezeichnung} (${v.standort_name || standortName(v.standort_id)})`; }

/* ==========================================================
   ROUTER
   ========================================================== */
const view = document.getElementById('view');
const routes = {
    dashboard: renderDashboard,
    standorte: renderStandorte,
    voelker: renderVoelkerListe,
    'voelker/:id': renderVolkDetail,
    durchsichten: renderDurchsichtenListe,
    fuetterungen: renderFuetterungenListe,
    behandlungen: renderBehandlungenListe,
    ernte: renderErnteListe,
    aufgaben: renderAufgaben,
    benutzer: renderBenutzer,
};

async function router() {
    const hash = window.location.hash.replace(/^#\//, '') || 'dashboard';
    const parts = hash.split('/');
    document.querySelectorAll('.nav-link').forEach(a => {
        a.classList.toggle('active', a.dataset.view === parts[0]);
    });

    view.innerHTML = '<div class="empty-state">Lädt…</div>';
    try {
        await loadBaseData();
        if (parts[0] === 'voelker' && parts[1]) {
            await renderVolkDetail(parts[1], parts[2] || 'durchsichten');
        } else if (routes[parts[0]]) {
            await routes[parts[0]]();
        } else {
            await renderDashboard();
        }
    } catch (err) {
        view.innerHTML = `<div class="empty-state">⚠️ ${esc(err.message)}</div>`;
    }
}
window.addEventListener('hashchange', router);
window.addEventListener('DOMContentLoaded', router);

/* ==========================================================
   DASHBOARD
   ========================================================== */
async function renderDashboard() {
    const s = await api('dashboard', 'stats');
    view.innerHTML = `
    <div class="view-header"><h1>Übersicht</h1></div>
    <div class="grid cols-4" style="margin-bottom:24px">
        <div class="card stat-card"><div class="stat-value">${s.anzahl_voelker}</div><div class="stat-label">Aktive Völker</div></div>
        <div class="card stat-card"><div class="stat-value">${s.anzahl_standorte}</div><div class="stat-label">Standorte</div></div>
        <div class="card stat-card"><div class="stat-value">${s.anzahl_offene_aufgaben}</div><div class="stat-label">Offene Aufgaben</div></div>
        <div class="card stat-card"><div class="stat-value">${s.ernte_jahr_kg.toLocaleString('de-DE')} kg</div><div class="stat-label">Ernte ${new Date().getFullYear()}</div></div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3 style="margin-top:0">🕒 Letzte Durchsichten</h3>
            ${s.letzte_durchsichten.length ? `<table><tbody>
                ${s.letzte_durchsichten.map(d => `<tr>
                    <td>${fmtDate(d.datum)}</td>
                    <td><a href="#/voelker/${d.volk_id ?? ''}">${esc(d.volk_bezeichnung)}</a></td>
                    <td>${esc(d.standort_name)}</td>
                    <td>${badgeWeiselrichtig(d.weiselrichtig)}</td>
                </tr>`).join('')}
            </tbody></table>` : emptyRow('Noch keine Durchsichten erfasst.')}
        </div>

        <div class="card">
            <h3 style="margin-top:0">📌 Nächste Aufgaben</h3>
            ${s.naechste_aufgaben.length ? `<table><tbody>
                ${s.naechste_aufgaben.map(a => `<tr>
                    <td>${esc(a.titel)}</td>
                    <td>${a.faelligkeit ? fmtDate(a.faelligkeit) : '<span class="hint">ohne Datum</span>'}</td>
                </tr>`).join('')}
            </tbody></table>` : emptyRow('Keine offenen Aufgaben. 🎉')}
        </div>
    </div>

    ${s.voelker_ohne_durchsicht_30d.length ? `
    <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">⚠️ Länger nicht kontrolliert (&gt; 30 Tage)</h3>
        <table><tbody>
            ${s.voelker_ohne_durchsicht_30d.map(v => `<tr>
                <td><a href="#/voelker/${v.id}">${esc(v.bezeichnung)}</a></td>
                <td>${esc(v.standort_name)}</td>
                <td>${v.letzte_durchsicht ? 'Letzte: ' + fmtDate(v.letzte_durchsicht) : 'Noch nie kontrolliert'}</td>
            </tr>`).join('')}
        </tbody></table>
    </div>` : ''}
    `;
}
function emptyRow(text) { return `<p class="hint">${esc(text)}</p>`; }
function badgeWeiselrichtig(w) {
    if (w === 'ja') return '<span class="badge green">weiselrichtig</span>';
    if (w === 'nein') return '<span class="badge red">nicht weiselrichtig</span>';
    return '<span class="badge gray">unsicher</span>';
}

/* ==========================================================
   STANDORTE
   ========================================================== */
async function renderStandorte() {
    const rows = CACHE.standorte;
    view.innerHTML = `
    <div class="view-header">
        <h1>Standorte</h1>
        <div class="actions"><button class="btn" onclick="openStandortForm()">+ Neuer Standort</button></div>
    </div>
    ${rows.length ? `<div class="grid cols-3">
        ${rows.map(s => `
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <h3 style="margin:0 0 4px">${esc(s.name)}</h3>
                <span class="badge honey">${s.anzahl_voelker} Völker</span>
            </div>
            <p class="hint" style="margin:2px 0 10px">${esc([s.adresse, [s.plz, s.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ')) || 'Keine Adresse hinterlegt'}</p>
            ${s.lat ? `<p class="hint">📍 ${(+s.lat).toFixed(4)}, ${(+s.lon).toFixed(4)}</p>` : '<p class="hint">⚠️ Keine Koordinaten – Wetter-Autofill nicht möglich</p>'}
            <div class="row-actions" style="margin-top:10px">
                <button class="btn small secondary" onclick="openStandortForm(${s.id})">Bearbeiten</button>
                <button class="btn small danger" onclick="deleteStandort(${s.id})">Löschen</button>
            </div>
        </div>`).join('')}
    </div>` : `<div class="empty-state"><div class="emoji">📍</div>Noch keine Standorte angelegt.</div>`}
    `;
}

function openStandortForm(id) {
    const s = id ? CACHE.standorte.find(x => +x.id === id) : {};
    openModal(id ? 'Standort bearbeiten' : 'Neuer Standort', `
    <form id="standortForm">
        <div class="form-row">
            <label>Name des Standorts *</label>
            <input type="text" name="name" value="${esc(s.name)}" required placeholder="z.B. Heimgarten, Streuobstwiese Nord ...">
        </div>
        <div class="form-grid">
            <div class="form-row"><label>Adresse <span class="opt">(für Geokodierung)</span></label>
                <input type="text" name="adresse" value="${esc(s.adresse)}" placeholder="Straße, Hausnummer"></div>
            <div class="form-row"><label>PLZ / Ort</label>
                <input type="text" name="ort" value="${esc(s.ort)}" placeholder="z.B. 21244 Buchholz"></div>
        </div>
        <div class="form-row">
            <button type="button" class="btn secondary small" id="geocodeBtn">📍 Koordinaten automatisch ermitteln</button>
            <span id="geocodeStatus" class="hint"></span>
        </div>
        <div class="form-grid">
            <div class="form-row"><label>Breitengrad (lat)</label>
                <input type="text" name="lat" id="latInput" value="${esc(s.lat)}" placeholder="z.B. 53.3167"></div>
            <div class="form-row"><label>Längengrad (lon)</label>
                <input type="text" name="lon" id="lonInput" value="${esc(s.lon)}" placeholder="z.B. 9.8667"></div>
        </div>
        <div class="form-row"><label>Trachtangebot / Umgebung <span class="opt">(optional)</span></label>
            <input type="text" name="flaeche_info" value="${esc(s.flaeche_info)}" placeholder="z.B. Raps, Linde, Wald im Umkreis"></div>
        <div class="form-row"><label>Pacht / Ansprechpartner <span class="opt">(optional)</span></label>
            <input type="text" name="pachtvertrag" value="${esc(s.pachtvertrag)}"></div>
        <div class="form-row"><label>Notizen</label>
            <textarea name="notizen">${esc(s.notizen)}</textarea></div>
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);

    document.getElementById('geocodeBtn').addEventListener('click', async () => {
        const form = document.getElementById('standortForm');
        const query = [form.adresse.value, form.ort.value].filter(Boolean).join(', ');
        const statusEl = document.getElementById('geocodeStatus');
        if (!query) { statusEl.textContent = 'Bitte zuerst Adresse oder Ort eingeben.'; return; }
        statusEl.textContent = 'Suche…';
        try {
            const result = await api('standorte', 'geocode', { method: 'POST', body: { query } });
            document.getElementById('latInput').value = result.lat;
            document.getElementById('lonInput').value = result.lon;
            statusEl.textContent = '✓ Gefunden: ' + result.label;
        } catch (err) {
            statusEl.textContent = '⚠️ ' + err.message;
        }
    });

    document.getElementById('standortForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const body = Object.fromEntries(fd.entries());
        try {
            if (id) await api('standorte', 'update', { method: 'PUT', params: { id }, body });
            else await api('standorte', 'create', { method: 'POST', body });
            closeModal();
            toast('Standort gespeichert.');
            router();
        } catch (err) {
            const el = document.getElementById('formError');
            el.textContent = err.message; el.hidden = false;
        }
    });
}

async function deleteStandort(id) {
    if (!confirm('Diesen Standort wirklich löschen?')) return;
    try {
        await api('standorte', 'delete', { method: 'DELETE', params: { id } });
        toast('Standort gelöscht.');
        router();
    } catch (err) { toast(err.message, 'error'); }
}

/* ==========================================================
   VÖLKER – Liste
   ========================================================== */
async function renderVoelkerListe() {
    const rows = CACHE.voelker;
    view.innerHTML = `
    <div class="view-header">
        <h1>Völker</h1>
        <div class="actions"><button class="btn" onclick="openVolkForm()">+ Neues Volk</button></div>
    </div>
    ${rows.length ? `<div class="card table-wrap"><table>
        <thead><tr><th>Bezeichnung</th><th>Standort</th><th>Rasse</th><th>Beute</th><th>Status</th><th></th></tr></thead>
        <tbody>
        ${rows.map(v => `<tr>
            <td><a href="#/voelker/${v.id}"><strong>${esc(v.bezeichnung)}</strong></a></td>
            <td>${esc(v.standort_name)}</td>
            <td>${esc(v.rasse) || '–'}</td>
            <td>${esc(v.beutentyp) || '–'}</td>
            <td>${statusBadge(v.status)}</td>
            <td class="row-actions"><a class="btn small secondary" href="#/voelker/${v.id}">Öffnen</a></td>
        </tr>`).join('')}
        </tbody>
    </table></div>` : `<div class="empty-state"><div class="emoji">🐝</div>Noch keine Völker angelegt.</div>`}
    `;
}
function statusBadge(status) {
    const cls = status === 'aktiv' ? 'green' : (status === 'verloren' || status === 'aufgeloest' ? 'red' : 'gray');
    return `<span class="badge ${cls}">${esc(VOLK_STATUS[status] || status)}</span>`;
}

function openVolkForm(id) {
    const v = id ? CACHE.voelker.find(x => +x.id === id) : {};
    if (!CACHE.standorte.length) { toast('Bitte zuerst einen Standort anlegen.', 'error'); return; }
    openModal(id ? 'Volk bearbeiten' : 'Neues Volk', `
    <form id="volkForm">
        <div class="form-grid">
            <div class="form-row"><label>Bezeichnung *</label>
                <input type="text" name="bezeichnung" value="${esc(v.bezeichnung)}" required placeholder="z.B. Volk 1 / Stock A"></div>
            <div class="form-row"><label>Standort *</label>
                <select name="standort_id" required>${opts_std(v.standort_id)}</select></div>
        </div>
        <div class="form-grid cols-3">
            <div class="form-row"><label>Rasse</label><select name="rasse"><option value="">–</option>${opts(RASSEN, v.rasse)}</select></div>
            <div class="form-row"><label>Beutentyp</label><select name="beutentyp"><option value="">–</option>${opts(BEUTENTYPEN, v.beutentyp)}</select></div>
            <div class="form-row"><label>Anzahl Zargen</label><input type="number" min="0" name="anzahl_zargen" value="${esc(v.anzahl_zargen)}"></div>
        </div>
        <div class="form-grid cols-3">
            <div class="form-row"><label>Herkunft</label><select name="herkunft"><option value="">–</option>${opts(HERKUNFT_VOLK, v.herkunft)}</select></div>
            <div class="form-row"><label>Gründungsdatum</label><input type="date" name="gruendungsdatum" value="${esc(v.gruendungsdatum)}"></div>
            <div class="form-row"><label>Status</label><select name="status">${optsMap(VOLK_STATUS, v.status || 'aktiv')}</select></div>
        </div>
        <h3 style="margin:18px 0 10px">👑 Königin</h3>
        <div class="form-grid cols-3">
            <div class="form-row"><label>Zuchtjahr</label><input type="number" name="koenigin_jahr" value="${esc(v.koenigin_jahr)}" placeholder="z.B. 2024"></div>
            <div class="form-row"><label>Zeichenfarbe</label><select name="koenigin_farbe"><option value="">–</option>${opts(FARBEN_KOENIGIN, v.koenigin_farbe)}</select></div>
            <div class="form-row"><label>Herkunft der Königin</label><input type="text" name="koenigin_herkunft" value="${esc(v.koenigin_herkunft)}" placeholder="Züchter / Nachzucht"></div>
        </div>
        <div class="checkbox-row form-row">
            <input type="checkbox" id="kgez" name="koenigin_gezeichnet" ${v.koenigin_gezeichnet ? 'checked' : ''}>
            <label for="kgez">Königin ist gezeichnet</label>
        </div>
        <div class="form-row"><label>Notizen</label><textarea name="notizen">${esc(v.notizen)}</textarea></div>
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);

    document.getElementById('volkForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const body = Object.fromEntries(fd.entries());
        body.koenigin_gezeichnet = e.target.koenigin_gezeichnet.checked;
        try {
            if (id) await api('voelker', 'update', { method: 'PUT', params: { id }, body });
            else await api('voelker', 'create', { method: 'POST', body });
            closeModal();
            toast('Volk gespeichert.');
            router();
        } catch (err) {
            const el = document.getElementById('formError');
            el.textContent = err.message; el.hidden = false;
        }
    });
}
function opts_std(selected) {
    return CACHE.standorte.map(s => `<option value="${s.id}" ${+s.id === +selected ? 'selected' : ''}>${esc(s.name)}</option>`).join('');
}

async function deleteVolk(id) {
    if (!confirm('Dieses Volk inkl. aller Durchsichten/Fütterungen/etc. wirklich löschen?')) return;
    try {
        await api('voelker', 'delete', { method: 'DELETE', params: { id } });
        toast('Volk gelöscht.');
        window.location.hash = '#/voelker';
    } catch (err) { toast(err.message, 'error'); }
}

/* ==========================================================
   VOLK-DETAIL (Stockkarte mit Tabs)
   ========================================================== */
async function renderVolkDetail(id, tab = 'durchsichten') {
    id = +id;
    let v;
    try { v = await api('voelker', 'get', { params: { id } }); }
    catch { view.innerHTML = `<div class="empty-state">Volk nicht gefunden.</div>`; return; }

    view.innerHTML = `
    <a href="#/voelker" class="hint">&larr; zurück zur Übersicht</a>
    <div class="volk-header" style="margin-top:8px">
        <div>
            <h1 style="margin:0">${esc(v.bezeichnung)} ${statusBadge(v.status)}</h1>
            <div class="volk-meta">
                <span>📍 ${esc(v.standort_name)}</span>
                <span>🧬 ${esc(v.rasse) || 'Rasse unbekannt'}</span>
                <span>📦 ${esc(v.beutentyp) || '–'}${v.anzahl_zargen ? ' · ' + v.anzahl_zargen + ' Zargen' : ''}</span>
                ${v.koenigin_jahr ? `<span>👑 Königin ${v.koenigin_jahr}${v.koenigin_farbe ? ' · ' + esc(v.koenigin_farbe) : ''}</span>` : ''}
            </div>
        </div>
        <div class="actions">
            <button class="btn secondary" onclick='openVolkForm(${v.id})'>Bearbeiten</button>
            <button class="btn danger" onclick="deleteVolk(${v.id})">Löschen</button>
        </div>
    </div>
    ${v.notizen ? `<div class="card" style="margin-bottom:18px"><strong>Notizen:</strong> ${esc(v.notizen)}</div>` : ''}

    <div class="tabs">
        <button class="tab-btn ${tab === 'durchsichten' ? 'active' : ''}" onclick="location.hash='#/voelker/${id}/durchsichten'">📋 Durchsichten</button>
        <button class="tab-btn ${tab === 'fuetterungen' ? 'active' : ''}" onclick="location.hash='#/voelker/${id}/fuetterungen'">🍯 Fütterungen</button>
        <button class="tab-btn ${tab === 'behandlungen' ? 'active' : ''}" onclick="location.hash='#/voelker/${id}/behandlungen'">💊 Behandlungen</button>
        <button class="tab-btn ${tab === 'ernte' ? 'active' : ''}" onclick="location.hash='#/voelker/${id}/ernte'">🫙 Ernte</button>
    </div>
    <div id="tabContent"></div>
    `;

    const tabContent = document.getElementById('tabContent');
    if (tab === 'durchsichten') await renderDurchsichtenTab(tabContent, v);
    else if (tab === 'fuetterungen') await renderFuetterungenTab(tabContent, v);
    else if (tab === 'behandlungen') await renderBehandlungenTab(tabContent, v);
    else if (tab === 'ernte') await renderErnteTab(tabContent, v);
}

/* -------- Durchsichten Tab -------- */
async function renderDurchsichtenTab(container, volk) {
    const rows = await api('durchsichten', 'list', { params: { volk_id: volk.id } });
    container.innerHTML = `
    <div class="view-header"><h3 style="margin:0">Durchsichten</h3>
        <button class="btn small" onclick='openDurchsichtForm(null, ${volk.id})'>+ Neue Durchsicht</button></div>
    ${rows.length ? `<div class="card table-wrap"><table>
        <thead><tr><th>Datum</th><th>Wetter</th><th>Weiselrichtig</th><th>Brut</th><th>Varroa</th><th>Maßnahmen</th><th></th></tr></thead>
        <tbody>${rows.map(d => `<tr>
            <td>${fmtDate(d.datum)}</td>
            <td>${d.wetter_temp_c !== null ? `${d.wetter_temp_c}°C ${esc(d.wetter_beschreibung || '')}` : '–'}</td>
            <td>${badgeWeiselrichtig(d.weiselrichtig)}</td>
            <td>${['stifte_vorhanden','offene_brut','verdeckelte_brut'].filter(k=>d[k]).map(k=>({stifte_vorhanden:'Stifte',offene_brut:'off. Brut',verdeckelte_brut:'ved. Brut'}[k])).join(', ') || '–'}</td>
            <td>${esc(VARROA_STUFEN[d.varroa_befall] || '–')}</td>
            <td style="max-width:220px;white-space:normal">${esc(d.massnahmen) || '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openDurchsichtForm(${d.id}, ${volk.id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntry('durchsichten', ${d.id}, ${volk.id}, 'durchsichten')">Löschen</button>
            </td>
        </tr>`).join('')}</tbody>
    </table></div>` : `<div class="empty-state"><div class="emoji">📋</div>Noch keine Durchsichten für dieses Volk.</div>`}
    `;
}

function ratingButtons(name, value) {
    let html = `<div class="rating-row" data-field="${name}">`;
    for (let i = 1; i <= 5; i++) {
        html += `<button type="button" class="${+value === i ? 'active' : ''}" data-val="${i}">${i}</button>`;
    }
    html += `<input type="hidden" name="${name}" value="${value || ''}"></div>`;
    return html;
}
function wireRatingRows(form) {
    form.querySelectorAll('.rating-row').forEach(row => {
        const hidden = row.querySelector('input[type=hidden]');
        row.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                row.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                hidden.value = btn.dataset.val;
            });
        });
    });
}

async function openDurchsichtForm(id, volkId) {
    const d = id ? await api('durchsichten', 'get', { params: { id } }) : {
        datum: todayISO(), weiselrichtig: 'ja', varroa_befall: 'unbekannt',
    };
    openModal(id ? 'Durchsicht bearbeiten' : 'Neue Durchsicht', `
    <form id="durchsichtForm">
        <input type="hidden" name="volk_id" value="${volkId}">
        <div class="form-grid">
            <div class="form-row"><label>Datum *</label><input type="date" name="datum" value="${esc(d.datum)}" required id="dsDatum"></div>
            <div class="form-row"><label>Uhrzeit <span class="opt">(optional)</span></label><input type="time" name="uhrzeit" value="${esc(d.uhrzeit)}"></div>
        </div>

        <div id="weatherBox" class="weather-box">
            <span class="icon">🌤️</span>
            <span id="weatherText">Wetter wird für Datum &amp; Standort geladen…</span>
            <button type="button" class="btn small secondary refresh" id="weatherRefresh">Aktualisieren</button>
        </div>
        <input type="hidden" name="wetter_temp_c" id="wTemp" value="${esc(d.wetter_temp_c)}">
        <input type="hidden" name="wetter_wind_kmh" id="wWind" value="${esc(d.wetter_wind_kmh)}">
        <input type="hidden" name="wetter_beschreibung" id="wDesc" value="${esc(d.wetter_beschreibung)}">
        <input type="hidden" name="wetter_code" id="wCode" value="${esc(d.wetter_code)}">

        <h3 style="margin:16px 0 10px">Brut &amp; Volksstärke</h3>
        <div class="form-grid cols-3">
            <div class="checkbox-row form-row"><input type="checkbox" id="stifte" name="stifte_vorhanden" ${d.stifte_vorhanden ? 'checked' : ''}><label for="stifte">Stifte vorhanden</label></div>
            <div class="checkbox-row form-row"><input type="checkbox" id="offbrut" name="offene_brut" ${d.offene_brut ? 'checked' : ''}><label for="offbrut">Offene Brut</label></div>
            <div class="checkbox-row form-row"><input type="checkbox" id="vedbrut" name="verdeckelte_brut" ${d.verdeckelte_brut ? 'checked' : ''}><label for="vedbrut">Verdeckelte Brut</label></div>
        </div>
        <div class="form-grid cols-3">
            <div class="form-row"><label>Brutwaben (Anzahl)</label><input type="number" step="0.5" min="0" name="brutwaben_anzahl" value="${esc(d.brutwaben_anzahl)}"></div>
            <div class="form-row"><label>Futterwaben (Anzahl)</label><input type="number" step="0.5" min="0" name="futterwaben_anzahl" value="${esc(d.futterwaben_anzahl)}"></div>
            <div class="form-row"><label>Volksstärke (besetzte Waben)</label><input type="number" step="0.5" min="0" name="volksstaerke_waben" value="${esc(d.volksstaerke_waben)}"></div>
        </div>
        <div class="form-grid cols-3">
            <div class="checkbox-row form-row"><input type="checkbox" id="kgesehen" name="koenigin_gesehen" ${d.koenigin_gesehen ? 'checked' : ''}><label for="kgesehen">Königin gesehen</label></div>
            <div class="form-row"><label>Weiselrichtig</label><select name="weiselrichtig">${optsMap(WEISELRICHTIG, d.weiselrichtig)}</select></div>
            <div class="checkbox-row form-row"><input type="checkbox" id="honigraum" name="honigraum_vorhanden" ${d.honigraum_vorhanden ? 'checked' : ''}><label for="honigraum">Honigraum aufgesetzt</label></div>
        </div>
        <div class="form-grid">
            <div class="checkbox-row form-row"><input type="checkbox" id="schwarmz" name="schwarmzellen" ${d.schwarmzellen ? 'checked' : ''}><label for="schwarmz">Schwarmzellen gefunden</label></div>
            <div class="checkbox-row form-row"><input type="checkbox" id="spieln" name="spielnaepfchen" ${d.spielnaepfchen ? 'checked' : ''}><label for="spieln">Spielnäpfchen gefunden</label></div>
        </div>

        <h3 style="margin:16px 0 10px">Gesundheit &amp; Verhalten</h3>
        <div class="form-grid">
            <div class="form-row"><label>Varroa-Befall</label><select name="varroa_befall">${optsMap(VARROA_STUFEN, d.varroa_befall)}</select></div>
            <div class="form-row"><label>Varroa Gemülldiagnose <span class="opt">(Anzahl Milben)</span></label><input type="number" min="0" name="varroa_anzahl_gemuell" value="${esc(d.varroa_anzahl_gemuell)}"></div>
        </div>
        <div class="form-row"><label>Krankheitsanzeichen <span class="opt">(z.B. Kalkbrut, Sackbrut, Kotspuren...)</span></label>
            <input type="text" name="krankheitsanzeichen" value="${esc(d.krankheitsanzeichen)}"></div>
        <div class="form-grid cols-3">
            <div class="form-row"><label>Sanftmut (1=aggressiv, 5=sehr sanft)</label>${ratingButtons('sanftmut', d.sanftmut)}</div>
            <div class="form-row"><label>Wabensitz (1=unruhig, 5=ruhig)</label>${ratingButtons('wabensitz', d.wabensitz)}</div>
            <div class="form-row"><label>Stechlust (1=hoch, 5=keine)</label>${ratingButtons('stechlust', d.stechlust)}</div>
        </div>

        <div class="form-row"><label>Durchgeführte Maßnahmen</label><textarea name="massnahmen" placeholder="z.B. Baurahmen entnommen, Zarge erweitert...">${esc(d.massnahmen)}</textarea></div>
        <div class="form-row"><label>Notizen</label><textarea name="notizen">${esc(d.notizen)}</textarea></div>

        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);

    const form = document.getElementById('durchsichtForm');
    wireRatingRows(form);

    async function refreshWeather() {
        const box = document.getElementById('weatherBox');
        const text = document.getElementById('weatherText');
        const datum = document.getElementById('dsDatum').value;
        if (!datum) return;
        box.className = 'weather-box loading';
        text.textContent = 'Lade Wetterdaten…';
        try {
            const w = await api('durchsichten', 'wetter_vorschau', { params: { volk_id: volkId, datum } });
            box.className = 'weather-box';
            text.textContent = `${w.temp_c}°C, ${w.beschreibung}, Wind ${w.wind_kmh ?? '–'} km/h`;
            document.getElementById('wTemp').value = w.temp_c ?? '';
            document.getElementById('wWind').value = w.wind_kmh ?? '';
            document.getElementById('wDesc').value = w.beschreibung ?? '';
            document.getElementById('wCode').value = w.code ?? '';
        } catch (err) {
            box.className = 'weather-box error';
            text.textContent = '⚠️ ' + err.message;
        }
    }
    document.getElementById('weatherRefresh').addEventListener('click', refreshWeather);
    document.getElementById('dsDatum').addEventListener('change', refreshWeather);
    if (!id) refreshWeather();
    else {
        const box = document.getElementById('weatherBox');
        const text = document.getElementById('weatherText');
        if (d.wetter_temp_c !== null && d.wetter_temp_c !== undefined) {
            text.textContent = `${d.wetter_temp_c}°C, ${d.wetter_beschreibung || ''}, Wind ${d.wetter_wind_kmh ?? '–'} km/h (gespeichert)`;
        } else { refreshWeather(); }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const body = {};
        for (const [k, v] of fd.entries()) body[k] = v;
        ['stifte_vorhanden','offene_brut','verdeckelte_brut','koenigin_gesehen','honigraum_vorhanden','schwarmzellen','spielnaepfchen']
            .forEach(k => body[k] = form.querySelector(`[name=${k}]`).checked);
        try {
            if (id) await api('durchsichten', 'update', { method: 'PUT', params: { id }, body });
            else await api('durchsichten', 'create', { method: 'POST', body });
            closeModal();
            toast('Durchsicht gespeichert.');
            router();
        } catch (err) {
            const el = document.getElementById('formError'); el.textContent = err.message; el.hidden = false;
        }
    });
}

/* -------- Fütterungen Tab -------- */
async function renderFuetterungenTab(container, volk) {
    const rows = await api('fuetterungen', 'list', { params: { volk_id: volk.id } });
    container.innerHTML = `
    <div class="view-header"><h3 style="margin:0">Fütterungen</h3>
        <button class="btn small" onclick='openFuetterungForm(null, ${volk.id})'>+ Neue Fütterung</button></div>
    ${rows.length ? `<div class="card table-wrap"><table>
        <thead><tr><th>Datum</th><th>Futterart</th><th>Menge</th><th>Notizen</th><th></th></tr></thead>
        <tbody>${rows.map(f => `<tr>
            <td>${fmtDate(f.datum)}</td><td>${esc(f.futterart)}</td>
            <td>${f.menge ?? '–'} ${f.menge ? esc(f.einheit) : ''}</td>
            <td>${esc(f.notizen) || '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openFuetterungForm(${f.id}, ${volk.id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntry('fuetterungen', ${f.id}, ${volk.id}, 'fuetterungen')">Löschen</button>
            </td>
        </tr>`).join('')}</tbody>
    </table></div>` : `<div class="empty-state"><div class="emoji">🍯</div>Noch keine Fütterungen erfasst.</div>`}
    `;
}
async function openFuetterungForm(id, volkId) {
    const rows = id ? await api('fuetterungen', 'list', { params: { volk_id: volkId } }) : [];
    const f = id ? rows.find(x => +x.id === id) : { datum: todayISO(), einheit: 'l' };
    openModal(id ? 'Fütterung bearbeiten' : 'Neue Fütterung', `
    <form id="fForm">
        <input type="hidden" name="volk_id" value="${volkId}">
        <div class="form-grid">
            <div class="form-row"><label>Datum *</label><input type="date" name="datum" value="${esc(f.datum)}" required></div>
            <div class="form-row"><label>Futterart *</label><select name="futterart" required>${opts(FUTTERARTEN, f.futterart)}</select></div>
        </div>
        <div class="form-grid">
            <div class="form-row"><label>Menge</label><input type="number" step="0.1" min="0" name="menge" value="${esc(f.menge)}"></div>
            <div class="form-row"><label>Einheit</label><select name="einheit">
                ${['l','kg','ml','g'].map(u=>`<option value="${u}" ${f.einheit===u?'selected':''}>${u}</option>`).join('')}
            </select></div>
        </div>
        <div class="form-row"><label>Notizen</label><textarea name="notizen">${esc(f.notizen)}</textarea></div>
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);
    document.getElementById('fForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        try {
            if (id) await api('fuetterungen', 'update', { method: 'PUT', params: { id }, body });
            else await api('fuetterungen', 'create', { method: 'POST', body });
            closeModal(); toast('Fütterung gespeichert.'); router();
        } catch (err) { const el = document.getElementById('formError'); el.textContent = err.message; el.hidden = false; }
    });
}

/* -------- Behandlungen Tab -------- */
async function renderBehandlungenTab(container, volk) {
    const rows = await api('behandlungen', 'list', { params: { volk_id: volk.id } });
    container.innerHTML = `
    <div class="view-header"><h3 style="margin:0">Behandlungen</h3>
        <button class="btn small" onclick='openBehandlungForm(null, ${volk.id})'>+ Neue Behandlung</button></div>
    ${rows.length ? `<div class="card table-wrap"><table>
        <thead><tr><th>Datum</th><th>Mittel</th><th>Menge</th><th>Wartezeit bis</th><th>Erfolgskontrolle</th><th></th></tr></thead>
        <tbody>${rows.map(b => `<tr>
            <td>${fmtDate(b.datum)}</td><td>${esc(b.mittel)}</td>
            <td>${b.menge ?? '–'} ${esc(b.einheit) || ''}</td>
            <td>${b.wartezeit_bis ? fmtDate(b.wartezeit_bis) : '–'}</td>
            <td>${b.erfolgskontrolle_datum ? fmtDate(b.erfolgskontrolle_datum) + (b.erfolgskontrolle_ergebnis ? ' – ' + esc(b.erfolgskontrolle_ergebnis) : '') : '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openBehandlungForm(${b.id}, ${volk.id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntry('behandlungen', ${b.id}, ${volk.id}, 'behandlungen')">Löschen</button>
            </td>
        </tr>`).join('')}</tbody>
    </table></div>` : `<div class="empty-state"><div class="emoji">💊</div>Noch keine Behandlungen erfasst.</div>`}
    `;
}
async function openBehandlungForm(id, volkId) {
    const rows = id ? await api('behandlungen', 'list', { params: { volk_id: volkId } }) : [];
    const b = id ? rows.find(x => +x.id === id) : { datum: todayISO() };
    openModal(id ? 'Behandlung bearbeiten' : 'Neue Behandlung', `
    <form id="bForm">
        <input type="hidden" name="volk_id" value="${volkId}">
        <div class="form-grid">
            <div class="form-row"><label>Datum *</label><input type="date" name="datum" value="${esc(b.datum)}" required></div>
            <div class="form-row"><label>Mittel *</label><select name="mittel" required>${opts(BEHANDLUNGSMITTEL, b.mittel)}</select></div>
        </div>
        <div class="form-grid cols-3">
            <div class="form-row"><label>Menge</label><input type="number" step="0.1" name="menge" value="${esc(b.menge)}"></div>
            <div class="form-row"><label>Einheit</label><input type="text" name="einheit" value="${esc(b.einheit)}" placeholder="ml, Streifen, ..."></div>
            <div class="form-row"><label>Methode</label><input type="text" name="methode" value="${esc(b.methode)}" placeholder="Verdunster, Träufeln..."></div>
        </div>
        <div class="form-grid">
            <div class="form-row"><label>Wartezeit bis</label><input type="date" name="wartezeit_bis" value="${esc(b.wartezeit_bis)}"></div>
            <div class="form-row"><label>Erfolgskontrolle am</label><input type="date" name="erfolgskontrolle_datum" value="${esc(b.erfolgskontrolle_datum)}"></div>
        </div>
        <div class="form-row"><label>Ergebnis der Erfolgskontrolle</label><input type="text" name="erfolgskontrolle_ergebnis" value="${esc(b.erfolgskontrolle_ergebnis)}"></div>
        <div class="form-row"><label>Notizen</label><textarea name="notizen">${esc(b.notizen)}</textarea></div>
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);
    document.getElementById('bForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        try {
            if (id) await api('behandlungen', 'update', { method: 'PUT', params: { id }, body });
            else await api('behandlungen', 'create', { method: 'POST', body });
            closeModal(); toast('Behandlung gespeichert.'); router();
        } catch (err) { const el = document.getElementById('formError'); el.textContent = err.message; el.hidden = false; }
    });
}

/* -------- Ernte Tab -------- */
async function renderErnteTab(container, volk) {
    const rows = await api('ernte', 'list', { params: { volk_id: volk.id } });
    container.innerHTML = `
    <div class="view-header"><h3 style="margin:0">Ernte</h3>
        <button class="btn small" onclick='openErnteForm(null, ${volk.id})'>+ Neue Ernte</button></div>
    ${rows.length ? `<div class="card table-wrap"><table>
        <thead><tr><th>Datum</th><th>Sorte</th><th>Menge (kg)</th><th>Wassergehalt</th><th></th></tr></thead>
        <tbody>${rows.map(e => `<tr>
            <td>${fmtDate(e.datum)}</td><td>${esc(e.honigsorte) || '–'}</td>
            <td>${e.menge_kg ?? '–'}</td><td>${e.wassergehalt ? e.wassergehalt + ' %' : '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openErnteForm(${e.id}, ${volk.id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntry('ernte', ${e.id}, ${volk.id}, 'ernte')">Löschen</button>
            </td>
        </tr>`).join('')}</tbody>
    </table></div>` : `<div class="empty-state"><div class="emoji">🫙</div>Noch keine Ernte erfasst.</div>`}
    `;
}
async function openErnteForm(id, volkId) {
    const rows = id ? await api('ernte', 'list', { params: { volk_id: volkId } }) : [];
    const e = id ? rows.find(x => +x.id === id) : { datum: todayISO() };
    openModal(id ? 'Ernte bearbeiten' : 'Neue Ernte', `
    <form id="eForm">
        <input type="hidden" name="volk_id" value="${volkId}">
        <div class="form-grid">
            <div class="form-row"><label>Datum *</label><input type="date" name="datum" value="${esc(e.datum)}" required></div>
            <div class="form-row"><label>Honigsorte</label><input type="text" name="honigsorte" value="${esc(e.honigsorte)}" placeholder="Frühtracht, Sommertracht, Waldhonig..."></div>
        </div>
        <div class="form-grid">
            <div class="form-row"><label>Menge (kg)</label><input type="number" step="0.1" min="0" name="menge_kg" value="${esc(e.menge_kg)}"></div>
            <div class="form-row"><label>Wassergehalt (%)</label><input type="number" step="0.1" min="0" max="30" name="wassergehalt" value="${esc(e.wassergehalt)}"></div>
        </div>
        <div class="form-row"><label>Notizen</label><textarea name="notizen">${esc(e.notizen)}</textarea></div>
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);
    document.getElementById('eForm').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const body = Object.fromEntries(new FormData(ev.target).entries());
        try {
            if (id) await api('ernte', 'update', { method: 'PUT', params: { id }, body });
            else await api('ernte', 'create', { method: 'POST', body });
            closeModal(); toast('Ernte gespeichert.'); router();
        } catch (err) { const el = document.getElementById('formError'); el.textContent = err.message; el.hidden = false; }
    });
}

async function deleteEntry(res, id, volkId, tab) {
    if (!confirm('Diesen Eintrag wirklich löschen?')) return;
    try {
        await api(res, 'delete', { method: 'DELETE', params: { id } });
        toast('Eintrag gelöscht.');
        await renderVolkDetail(volkId, tab);
    } catch (err) { toast(err.message, 'error'); }
}

/* ==========================================================
   GLOBALE LISTEN (Durchsichten / Fütterungen / Behandlungen / Ernte über alle Völker)
   ========================================================== */
async function genericGlobalList(title, res, columnsFn, addFn) {
    const filterVolk = document.getElementById('globalVolkFilter')?.value || '';
    const rows = await api(res, 'list', filterVolk ? { params: { volk_id: filterVolk } } : {});
    return { rows };
}

async function renderDurchsichtenListe() {
    await renderGlobalList({
        title: 'Alle Durchsichten', res: 'durchsichten',
        addLabel: '+ Neue Durchsicht',
        onAdd: () => { if (!CACHE.voelker.length) return toast('Bitte zuerst ein Volk anlegen.', 'error'); openDurchsichtForm(null, CACHE.voelker[0].id); },
        head: ['Datum', 'Volk', 'Wetter', 'Weiselrichtig', 'Varroa', ''],
        row: d => `<tr>
            <td>${fmtDate(d.datum)}</td>
            <td><a href="#/voelker/${d.volk_id}">${esc(d.volk_bezeichnung)}</a></td>
            <td>${d.wetter_temp_c !== null ? d.wetter_temp_c + '°C' : '–'}</td>
            <td>${badgeWeiselrichtig(d.weiselrichtig)}</td>
            <td>${esc(VARROA_STUFEN[d.varroa_befall] || '–')}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openDurchsichtForm(${d.id}, ${d.volk_id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntryGlobal('durchsichten', ${d.id})">Löschen</button>
            </td></tr>`,
        empty: 'Noch keine Durchsichten erfasst.',
    });
}
async function renderFuetterungenListe() {
    await renderGlobalList({
        title: 'Alle Fütterungen', res: 'fuetterungen',
        onAdd: () => { if (!CACHE.voelker.length) return toast('Bitte zuerst ein Volk anlegen.', 'error'); openFuetterungForm(null, CACHE.voelker[0].id); },
        head: ['Datum', 'Volk', 'Futterart', 'Menge', ''],
        row: f => `<tr>
            <td>${fmtDate(f.datum)}</td>
            <td><a href="#/voelker/${f.volk_id}">${esc(f.volk_bezeichnung)}</a></td>
            <td>${esc(f.futterart)}</td>
            <td>${f.menge ?? '–'} ${f.menge ? esc(f.einheit) : ''}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openFuetterungForm(${f.id}, ${f.volk_id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntryGlobal('fuetterungen', ${f.id})">Löschen</button>
            </td></tr>`,
        empty: 'Noch keine Fütterungen erfasst.',
    });
}
async function renderBehandlungenListe() {
    await renderGlobalList({
        title: 'Alle Behandlungen', res: 'behandlungen',
        onAdd: () => { if (!CACHE.voelker.length) return toast('Bitte zuerst ein Volk anlegen.', 'error'); openBehandlungForm(null, CACHE.voelker[0].id); },
        head: ['Datum', 'Volk', 'Mittel', 'Wartezeit bis', ''],
        row: b => `<tr>
            <td>${fmtDate(b.datum)}</td>
            <td><a href="#/voelker/${b.volk_id}">${esc(b.volk_bezeichnung)}</a></td>
            <td>${esc(b.mittel)}</td>
            <td>${b.wartezeit_bis ? fmtDate(b.wartezeit_bis) : '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openBehandlungForm(${b.id}, ${b.volk_id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntryGlobal('behandlungen', ${b.id})">Löschen</button>
            </td></tr>`,
        empty: 'Noch keine Behandlungen erfasst.',
    });
}
async function renderErnteListe() {
    await renderGlobalList({
        title: 'Alle Ernten', res: 'ernte',
        onAdd: () => { if (!CACHE.voelker.length) return toast('Bitte zuerst ein Volk anlegen.', 'error'); openErnteForm(null, CACHE.voelker[0].id); },
        head: ['Datum', 'Volk', 'Sorte', 'Menge (kg)', ''],
        row: e => `<tr>
            <td>${fmtDate(e.datum)}</td>
            <td><a href="#/voelker/${e.volk_id}">${esc(e.volk_bezeichnung)}</a></td>
            <td>${esc(e.honigsorte) || '–'}</td>
            <td>${e.menge_kg ?? '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openErnteForm(${e.id}, ${e.volk_id})'>Bearb.</button>
                <button class="btn small danger" onclick="deleteEntryGlobal('ernte', ${e.id})">Löschen</button>
            </td></tr>`,
        empty: 'Noch keine Ernte erfasst.',
    });
}

let CURRENT_GLOBAL_RES = null;
async function renderGlobalList({ title, res, head, row, empty, onAdd }) {
    CURRENT_GLOBAL_RES = res;
    const rows = await api(res, 'list');
    view.innerHTML = `
    <div class="view-header"><h1>${esc(title)}</h1>
        <div class="actions"><button class="btn" id="globalAddBtn">+ Neuer Eintrag</button></div></div>
    ${rows.length ? `<div class="card table-wrap"><table>
        <thead><tr>${head.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead>
        <tbody>${rows.map(row).join('')}</tbody>
    </table></div>` : `<div class="empty-state"><div class="emoji">📄</div>${esc(empty)}</div>`}
    `;
    document.getElementById('globalAddBtn').addEventListener('click', onAdd);
}
async function deleteEntryGlobal(res, id) {
    if (!confirm('Diesen Eintrag wirklich löschen?')) return;
    try {
        await api(res, 'delete', { method: 'DELETE', params: { id } });
        toast('Eintrag gelöscht.');
        router();
    } catch (err) { toast(err.message, 'error'); }
}

/* ==========================================================
   AUFGABEN
   ========================================================== */
async function renderAufgaben() {
    const rows = await api('aufgaben', 'list', { params: { zeige_erledigte: 1 } });
    const offen = rows.filter(a => !a.erledigt);
    const erledigt = rows.filter(a => a.erledigt);
    view.innerHTML = `
    <div class="view-header"><h1>Aufgaben</h1>
        <div class="actions"><button class="btn" id="addAufgabeBtn">+ Neue Aufgabe</button></div></div>
    <div class="card" style="margin-bottom:16px">
        <h3 style="margin-top:0">Offen (${offen.length})</h3>
        ${offen.length ? `<table><tbody>${offen.map(a => aufgabeRow(a)).join('')}</tbody></table>` : `<p class="hint">Keine offenen Aufgaben. 🎉</p>`}
    </div>
    ${erledigt.length ? `<div class="card">
        <h3 style="margin-top:0">Erledigt (${erledigt.length})</h3>
        <table><tbody>${erledigt.map(a => aufgabeRow(a)).join('')}</tbody></table>
    </div>` : ''}
    `;
    document.getElementById('addAufgabeBtn').addEventListener('click', openAufgabeForm);
}
function aufgabeRow(a) {
    return `<tr style="${a.erledigt ? 'opacity:.55;text-decoration:line-through' : ''}">
        <td style="width:30px"><input type="checkbox" ${a.erledigt ? 'checked' : ''} onchange="toggleAufgabe(${a.id})"></td>
        <td>${esc(a.titel)}</td>
        <td class="hint">${a.volk_bezeichnung ? '🐝 ' + esc(a.volk_bezeichnung) : (a.standort_name ? '📍 ' + esc(a.standort_name) : '')}</td>
        <td>${a.faelligkeit ? fmtDate(a.faelligkeit) : ''}</td>
        <td class="row-actions"><button class="btn small danger" onclick="deleteAufgabe(${a.id})">Löschen</button></td>
    </tr>`;
}
function openAufgabeForm() {
    openModal('Neue Aufgabe', `
    <form id="aForm">
        <div class="form-row"><label>Titel *</label><input type="text" name="titel" required placeholder="z.B. Windschutz kontrollieren"></div>
        <div class="form-grid">
            <div class="form-row"><label>Fälligkeit</label><input type="date" name="faelligkeit"></div>
            <div class="form-row"><label>Volk <span class="opt">(optional)</span></label>
                <select name="volk_id"><option value="">–</option>${CACHE.voelker.map(v => `<option value="${v.id}">${esc(volkLabel(v))}</option>`).join('')}</select></div>
        </div>
        <div class="form-row"><label>Notizen</label><textarea name="notizen"></textarea></div>
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);
    document.getElementById('aForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        try {
            await api('aufgaben', 'create', { method: 'POST', body });
            closeModal(); toast('Aufgabe gespeichert.'); router();
        } catch (err) { const el = document.getElementById('formError'); el.textContent = err.message; el.hidden = false; }
    });
}
async function toggleAufgabe(id) {
    try { await api('aufgaben', 'toggle', { method: 'PUT', params: { id } }); router(); }
    catch (err) { toast(err.message, 'error'); }
}
async function deleteAufgabe(id) {
    if (!confirm('Aufgabe löschen?')) return;
    try { await api('aufgaben', 'delete', { method: 'DELETE', params: { id } }); toast('Gelöscht.'); router(); }
    catch (err) { toast(err.message, 'error'); }
}

/* ==========================================================
   BENUTZERVERWALTUNG (nur Admin)
   ========================================================== */
async function renderBenutzer() {
    if (CURRENT_USER.role !== 'admin') { view.innerHTML = '<div class="empty-state">Kein Zugriff.</div>'; return; }
    const rows = await api('users', 'list');
    view.innerHTML = `
    <div class="view-header"><h1>Benutzer</h1>
        <div class="actions"><button class="btn" id="addUserBtn">+ Neuer Benutzer</button></div></div>
    <div class="card table-wrap"><table>
        <thead><tr><th>Name</th><th>Benutzername</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th></th></tr></thead>
        <tbody>${rows.map(u => `<tr>
            <td>${esc(u.name)}</td><td>${esc(u.username)}</td>
            <td>${u.role === 'admin' ? '<span class="badge honey">Admin</span>' : '<span class="badge gray">Imker</span>'}</td>
            <td>${u.active ? '<span class="badge green">Aktiv</span>' : '<span class="badge red">Deaktiviert</span>'}</td>
            <td>${u.last_login ? fmtDateTime(u.last_login) : '–'}</td>
            <td class="row-actions">
                <button class="btn small secondary" onclick='openUserForm(${JSON.stringify(u).replace(/'/g, "&#39;")})'>Bearb.</button>
                ${u.id !== CURRENT_USER.id ? `<button class="btn small danger" onclick="deleteUser(${u.id})">Löschen</button>` : ''}
            </td>
        </tr>`).join('')}</tbody>
    </table></div>
    `;
    document.getElementById('addUserBtn').addEventListener('click', () => openUserForm());
}
function openUserForm(u) {
    const id = u?.id;
    openModal(id ? 'Benutzer bearbeiten' : 'Neuer Benutzer', `
    <form id="uForm">
        <div class="form-grid">
            <div class="form-row"><label>Name *</label><input type="text" name="name" value="${esc(u?.name)}" required></div>
            <div class="form-row"><label>Benutzername *</label><input type="text" name="username" value="${esc(u?.username)}" ${id ? 'disabled' : 'required'}></div>
        </div>
        <div class="form-grid">
            <div class="form-row"><label>E-Mail</label><input type="email" name="email" value="${esc(u?.email)}"></div>
            <div class="form-row"><label>Rolle</label><select name="role">
                <option value="imker" ${u?.role === 'imker' ? 'selected' : ''}>Imker</option>
                <option value="admin" ${u?.role === 'admin' ? 'selected' : ''}>Administrator</option>
            </select></div>
        </div>
        <div class="form-row"><label>${id ? 'Neues Passwort (leer lassen für keine Änderung)' : 'Passwort *'}</label>
            <input type="password" name="password" ${id ? '' : 'required'} minlength="6" placeholder="mind. 6 Zeichen"></div>
        ${id ? `<div class="checkbox-row form-row"><input type="checkbox" id="uactive" name="active" ${u.active ? 'checked' : ''}><label for="uactive">Konto aktiv</label></div>` : ''}
        <p class="error-msg" id="formError" hidden></p>
        <div class="form-actions">
            <button type="button" class="btn secondary" onclick="closeModal()">Abbrechen</button>
            <button type="submit" class="btn">Speichern</button>
        </div>
    </form>`);
    document.getElementById('uForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const body = Object.fromEntries(fd.entries());
        if (id) body.active = e.target.active ? e.target.active.checked : true;
        if (!body.password) delete body.password;
        try {
            if (id) await api('users', 'update', { method: 'PUT', params: { id }, body });
            else await api('users', 'create', { method: 'POST', body });
            closeModal(); toast('Benutzer gespeichert.'); router();
        } catch (err) { const el = document.getElementById('formError'); el.textContent = err.message; el.hidden = false; }
    });
}
async function deleteUser(id) {
    if (!confirm('Diesen Benutzer wirklich löschen?')) return;
    try { await api('users', 'delete', { method: 'DELETE', params: { id } }); toast('Benutzer gelöscht.'); router(); }
    catch (err) { toast(err.message, 'error'); }
}
