// Cliente da API (api.php). Todas as respostas: { ok, data } ou { ok:false, error }.

const BASE = 'api.php';

async function parse(res) {
  let body;
  try { body = await res.json(); } catch { throw new Error(`Resposta inválida do servidor (HTTP ${res.status}). Verifique se o PHP está a correr.`); }
  if (!body.ok) {
    const err = new Error(body.error || 'Erro desconhecido');
    if (body.auth) { err.auth = true; window.dispatchEvent(new CustomEvent('ideia:auth')); }
    throw err;
  }
  return body.data;
}

function url(action, params = {}) {
  const q = new URLSearchParams({ action });
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, v);
  return `${BASE}?${q}`;
}

export const api = {
  company: null,

  get(action, params = {}) {
    return fetch(url(action, { c: this.company, ...params })).then(parse);
  },

  post(action, body = {}, params = {}) {
    return fetch(url(action, { c: this.company, ...params }), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'IDEIA' },
      body: JSON.stringify(body),
    }).then(parse);
  },

  upload(action, file, fields = {}, params = {}) {
    const fd = new FormData();
    fd.append('file', file);
    for (const [k, v] of Object.entries(fields)) fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    return fetch(url(action, { c: this.company, ...params }), {
      method: 'POST', headers: { 'X-Requested-With': 'IDEIA' }, body: fd,
    }).then(parse);
  },

  /** URL para descarregar ficheiros (SAF-T, cópias, modelos). */
  href(action, params = {}) {
    return url(action, { c: this.company, ...params });
  },
};
