/* Thin wrapper around the JSON API. Every call posts to api/index.php?r=...
   and returns the payload, or throws an Error whose message is a translation
   key such as "err.forbidden". */

const API_URL = 'api/index.php';

const session = {
  user: null,
  csrf: null,
  weatherEnabled: false,
  map: null            // tile config from auth/me, null when disabled
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

/** Build a URL for the routes that stream a file instead of JSON. */
function apiFileUrl(route, params) {
  const q = new URLSearchParams({ r: route, csrf: session.csrf || '', ...(params || {}) });
  return `${API_URL}?${q.toString()}`;
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
