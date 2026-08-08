/* Thin wrapper around the JSON API. Every call posts to api/index.php?r=...
   and returns the payload, or throws an Error whose message is a translation
   key such as "err.forbidden". */

const API_URL = 'api/index.php';

const session = {
  user: null,
  csrf: null,
  weatherEnabled: false,
  map: null,           // tile config from auth/me, null when disabled
  mail: false,         // whether a password reset can be sent at all
  mode: 'private',     // 'private' or 'open', from auth/me
  canRegister: false,
  legal: {}
};

async function api(route, body) {
  let res;
  try {
    res = await fetch(`${API_URL}?r=${encodeURIComponent(route)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(session.csrf ? { 'X-CSRF-Token': session.csrf } : {})
      },
      body: JSON.stringify(body || {})
    });
  } catch (e) {
    throw new Error('err.network');
  }

  let payload;
  try {
    payload = await res.json();
  } catch (e) {
    throw new Error('err.server_error');
  }

  if (!payload.ok) {
    const err = new Error('err.' + (payload.error || 'server_error'));
    err.detail = payload.detail;
    err.status = res.status;
    throw err;
  }
  return payload.data;
}

/** Hand a blob to the browser as a download, without ever leaving the page. */
function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}

function filenameFromHeaders(headers, fallback) {
  const cd = headers.get('Content-Disposition') || '';
  const m = /filename="?([^";]+)"?/i.exec(cd);
  return m ? m[1] : fallback;
}

/**
 * Routes that answer with a file rather than JSON.
 *
 * POST with the token in a header, like every other call: a GET download would
 * have to carry the CSRF token in the URL, where it ends up in the web server
 * access log and in the browser history.
 */
async function apiDownload(route, body, fallbackName) {
  let res;
  try {
    res = await fetch(`${API_URL}?r=${encodeURIComponent(route)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(session.csrf ? { 'X-CSRF-Token': session.csrf } : {})
      },
      body: JSON.stringify(body || {})
    });
  } catch (e) {
    throw new Error('err.network');
  }
  // A refusal still arrives as JSON, so report it like any other API error.
  if (!res.ok || (res.headers.get('Content-Type') || '').includes('application/json')) {
    let payload = {};
    try { payload = await res.json(); } catch (e) { /* not JSON after all */ }
    const err = new Error('err.' + (payload.error || 'server_error'));
    err.detail = payload.detail;
    throw err;
  }
  saveBlob(await res.blob(), filenameFromHeaders(res.headers, fallbackName));
}

/** Multipart upload (backup files). */
async function apiUpload(route, file) {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('csrf', session.csrf || '');
  const res = await fetch(`${API_URL}?r=${encodeURIComponent(route)}`, {
    method: 'POST',
    credentials: 'same-origin',
    body: fd
  });
  const payload = await res.json();
  if (!payload.ok) throw new Error('err.' + (payload.error || 'server_error'));
  return payload.data;
}
