// Utilitários de interface: formatação, escape, avisos, janelas modais e exportação CSV.

export const MONTHS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
export const MONTHS_FULL = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

export function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Centavos -> "1 234,56" */
export function money(cents, { blankZero = false } = {}) {
  const n = Number(cents || 0);
  if (blankZero && n === 0) return '';
  const neg = n < 0;
  const abs = Math.abs(n);
  const int = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return (neg ? '-' : '') + int + ',' + String(abs % 100).padStart(2, '0');
}

/** Centavos -> "1,2 M" para eixos de gráficos */
export function compact(cents) {
  const v = Number(cents || 0) / 100;
  const a = Math.abs(v);
  if (a >= 1e9) return (v / 1e9).toFixed(1).replace('.', ',') + ' mM';
  if (a >= 1e6) return (v / 1e6).toFixed(1).replace('.', ',') + ' M';
  if (a >= 1e3) return (v / 1e3).toFixed(0) + ' mil';
  return v.toFixed(0);
}

/** "1.234,56" / "1234.56" -> centavos (mesmas regras que Money::toCents no servidor) */
export function toCents(text) {
  let s = String(text ?? '').trim().replace(/[\s $]/g, '');
  if (!s) return 0;
  let neg = false;
  if (s.startsWith('-')) { neg = true; s = s.slice(1); }
  const lc = s.lastIndexOf(','), ld = s.lastIndexOf('.');
  if (lc >= 0 && ld >= 0) s = lc > ld ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
  else if (lc >= 0) s = (s.length - lc - 1 <= 2 && s.split(',').length === 2) ? s.replace(',', '.') : s.replace(/,/g, '');
  else if ((s.match(/\./g) || []).length > 1) s = s.replace(/\./g, '');
  if (!/^\d*(\.\d+)?$/.test(s)) return NaN;
  const [i, f = ''] = s.split('.');
  const c = Number(i || 0) * 100 + Math.round(Number((f + '000').slice(0, 3)) / 10);
  return neg ? -c : c;
}

export function pct(v, digits = 1) {
  return v === null || v === undefined ? '—' : Number(v).toFixed(digits).replace('.', ',') + '%';
}

export function signClass(v) { return Number(v) < 0 ? 'neg' : ''; }

export function today() { return new Date().toISOString().slice(0, 10); }

export function fmtDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.slice(0, 10).split('-');
  return `${d}/${m}/${y}`;
}

export function toast(message, kind = '') {
  const el = document.createElement('div');
  el.className = 'toast ' + kind;
  el.textContent = message;
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => el.remove(), kind === 'bad' ? 7000 : 3500);
}

/** Janela modal. Devolve { el, close }. `onSubmit` recebe o elemento e pode devolver false para manter aberta. */
export function modal({ title, body, actions = [], wide = false }) {
  const root = document.getElementById('modal-root');
  const back = document.createElement('div');
  back.className = 'modal-back';
  back.innerHTML = `<div class="modal ${wide ? 'wide' : ''}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
      <header><h2>${esc(title)}</h2><button class="icon-btn" data-close aria-label="Fechar">✕</button></header>
      <div class="body">${body}</div>
      <footer></footer></div>`;
  const footer = back.querySelector('footer');
  const close = () => { back.remove(); document.removeEventListener('keydown', onKey); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', onKey);
  back.addEventListener('mousedown', (e) => { if (e.target === back) close(); });
  back.querySelector('[data-close]').onclick = close;
  for (const a of actions) {
    const b = document.createElement('button');
    b.textContent = a.label;
    if (a.primary) b.className = 'primary';
    if (a.danger) b.className = 'danger';
    b.onclick = async () => {
      if (!a.onClick) return close();
      b.disabled = true;
      try {
        const r = await a.onClick(back);
        if (r !== false) close();
      } catch (err) {
        toast(err.message, 'bad');
      } finally { b.disabled = false; }
    };
    footer.appendChild(b);
  }
  if (!actions.length) footer.remove();
  root.appendChild(back);
  const first = back.querySelector('input, select, textarea, button.primary');
  if (first) first.focus();
  return { el: back, close };
}

export function confirmDialog(title, message, label = 'Confirmar') {
  return new Promise((resolve) => {
    const m = modal({
      title, body: `<p>${message}</p>`,
      actions: [{ label: 'Cancelar', onClick: () => resolve(false) }, { label, primary: true, onClick: () => resolve(true) }],
    });
    m.el.querySelector('[data-close]').addEventListener('click', () => resolve(false));
  });
}

/** Lê os campos de um formulário (name -> valor). */
export function formData(el) {
  const out = {};
  el.querySelectorAll('[name]').forEach((i) => {
    out[i.name] = i.type === 'checkbox' ? i.checked : i.value;
  });
  return out;
}

/** Exporta uma tabela HTML para CSV (separador ;, compatível com Excel PT). */
export function tableToCsv(table, filename) {
  const rows = [...table.querySelectorAll('tr')].map((tr) => [...tr.children].map((td) => {
    let t = td.innerText.replace(/\s+/g, ' ').trim();
    if (td.classList.contains('num')) t = t.replace(/ /g, '');
    return '"' + t.replace(/"/g, '""') + '"';
  }).join(';'));
  const blob = new Blob(['﻿' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

export function debounce(fn, ms = 250) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

export function monthOptions(selected) {
  return MONTHS_FULL.map((m, i) => `<option value="${i + 1}" ${Number(selected) === i + 1 ? 'selected' : ''}>${m}</option>`).join('');
}
