// Lançamentos: lista com filtros e editor (rascunho, validação, estorno).
import { api } from '../api.js';
import { state, go, cpath, setCrumbs, refreshInfo } from '../main.js';
import { esc, money, toCents, fmtDate, toast, confirmDialog, debounce, today } from '../util.js';

export async function render(ctx) {
  if (ctx.section === 'lancamento') return editor(ctx);
  return list(ctx);
}

// ------------------------------------------------------------------ lista

async function list({ view, query }) {
  setCrumbs('Lançamentos');
  const f = { year: state.year, journal: '', status: '', q: '', account: '', from: '', to: '', ...query };
  const journals = state.info.journals;
  view.innerHTML = `
    <div class="page-head"><div><h1>Lançamentos</h1><p class="muted">Exercício ${state.year}. Os lançamentos validados são imutáveis; corrija com um estorno.</p></div>
      <div class="toolbar"><a class="btn primary" href="#/${cpath('lancamento/novo')}">Novo lançamento</a></div></div>
    <div class="card">
      <div class="toolbar" id="filters">
        <label>Pesquisar<input name="q" value="${esc(f.q)}" placeholder="Descrição ou documento"></label>
        <label>Diário<select name="journal"><option value="">Todos</option>${journals.map((j) => `<option value="${esc(j.code)}" ${f.journal === j.code ? 'selected' : ''}>${esc(j.code)} · ${esc(j.name)}</option>`).join('')}</select></label>
        <label>Estado<select name="status"><option value="">Todos</option><option value="validado" ${f.status === 'validado' ? 'selected' : ''}>Validados</option><option value="rascunho" ${f.status === 'rascunho' ? 'selected' : ''}>Rascunhos</option></select></label>
        <label>Conta<input name="account" value="${esc(f.account)}" size="8" placeholder="ex.: 62"></label>
        <label>De<input type="date" name="from" value="${esc(f.from)}"></label>
        <label>Até<input type="date" name="to" value="${esc(f.to)}"></label>
      </div>
      <div class="table-wrap" style="margin-top:12px"><table>
        <thead><tr><th>Data</th><th>Diário / n.º</th><th>Documento</th><th>Descrição</th><th>Origem</th><th>Estado</th><th class="num">Total</th></tr></thead>
        <tbody id="rows"><tr><td colspan="7" class="loading">A carregar…</td></tr></tbody></table></div>
      <div class="muted small" id="count" style="margin-top:8px"></div>
    </div>`;
  const load = async () => {
    const params = { ...f, year: state.year, limit: 500 };
    const r = await api.get('entries.list', params);
    const tb = view.querySelector('#rows');
    tb.innerHTML = r.rows.map((e) => `<tr class="clickable" data-id="${e.id}">
        <td>${fmtDate(e.date)}</td><td class="mono">${esc(e.journal)}${e.number ? ' / ' + e.number : ''}</td>
        <td>${esc(e.document || '')}</td><td>${esc(e.description)}${e.reversed_by ? ' <span class="badge">estornado</span>' : ''}</td>
        <td class="small muted">${esc(e.source)}</td>
        <td>${e.status === 'validado' ? '<span class="badge good">Validado</span>' : '<span class="badge warn">Rascunho</span>'}</td>
        <td class="num">${money(e.total_cents)}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">Nenhum lançamento encontrado.</td></tr>';
    tb.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = () => go(cpath('lancamento/' + tr.dataset.id)); });
    view.querySelector('#count').textContent = `${r.rows.length} de ${r.total} lançamento(s)`;
  };
  const reload = debounce(() => {
    view.querySelectorAll('#filters [name]').forEach((i) => { f[i.name] = i.value; });
    load();
  });
  view.querySelectorAll('#filters [name]').forEach((i) => { i.oninput = reload; });
  await load();
}

// ------------------------------------------------------------------ editor

let accountsCache = null;
let partiesCache = null;

async function loadRefs(force = false) {
  if (!accountsCache || force) accountsCache = await api.get('accounts.list');
  if (!partiesCache || force) partiesCache = await api.get('thirdparties.list');
}

export function invalidateRefs() { accountsCache = null; partiesCache = null; }

async function editor({ view, params, query }) {
  const id = params[0] && params[0] !== 'novo' ? Number(params[0]) : null;
  await loadRefs(true);
  let e = id ? await api.get('entries.get', { id }) : {
    date: query.date || (String(state.year) === today().slice(0, 4) ? today() : `${state.year}-01-01`),
    journal: query.journal || 'DIV', description: '', document: '', status: 'rascunho', lines: [{}, {}],
  };
  setCrumbs('Lançamentos', id ? `${e.journal} ${e.number || '(rascunho)'}` : 'Novo');
  const readonly = e.status === 'validado';
  const accMap = Object.fromEntries(accountsCache.map((a) => [a.code, a]));
  const leafs = accountsCache.filter((a) => Number(a.movement) && Number(a.active));
  const partyByLabel = Object.fromEntries(partiesCache.map((p) => [`${p.name}${p.nif ? ' · ' + p.nif : ''}`, p]));
  const partyLabel = (pid) => { const p = partiesCache.find((x) => Number(x.id) === Number(pid)); return p ? `${p.name}${p.nif ? ' · ' + p.nif : ''}` : ''; };
  const vatOpts = (sel) => '<option value=""></option>' + state.info.vat_codes.map((v) => `<option value="${esc(v.code)}" ${sel === v.code ? 'selected' : ''}>${esc(v.code)}</option>`).join('');

  view.innerHTML = `
    <datalist id="dl-accounts">${leafs.map((a) => `<option value="${esc(a.code)}">${esc(a.name)}</option>`).join('')}</datalist>
    <datalist id="dl-parties">${Object.keys(partyByLabel).map((l) => `<option value="${esc(l)}"></option>`).join('')}</datalist>
    <div class="page-head"><div><h1>${id ? (readonly ? `Lançamento ${esc(e.journal)} / ${e.number}` : 'Editar rascunho') : 'Novo lançamento'}</h1>
      <p class="muted">${readonly ? `Validado em ${esc(e.validated_at)} · origem: ${esc(e.source)}${e.reverses ? ` · estorno do <a href="#/${cpath('lancamento/' + e.reverses)}">lançamento ${e.reverses}</a>` : ''}${e.reversed_by ? ` · estornado pelo <a href="#/${cpath('lancamento/' + e.reversed_by)}">lançamento ${e.reversed_by}</a>` : ''}` : 'Use contas de movimento. Enter na última linha acrescenta outra.'}</p></div>
      <div class="toolbar"><a class="btn" href="#/${cpath('lancamentos')}">Voltar à lista</a></div></div>
    <div class="card">
      <div class="form" id="head">
        <label>Data<input type="date" name="date" value="${esc(e.date)}" ${readonly ? 'disabled' : ''}></label>
        <label>Diário<select name="journal" ${readonly ? 'disabled' : ''}>${state.info.journals.map((j) => `<option value="${esc(j.code)}" ${e.journal === j.code ? 'selected' : ''}>${esc(j.code)} · ${esc(j.name)}</option>`).join('')}</select></label>
        <label>Documento<input name="document" value="${esc(e.document || '')}" placeholder="Ex.: FTE 2025/12" ${readonly ? 'disabled' : ''}></label>
        <label class="wide">Descrição<input name="description" value="${esc(e.description)}" ${readonly ? 'disabled' : ''}></label>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap"><table class="lines">
        <thead><tr><th style="width:120px">Conta</th><th>Descrição da linha</th><th style="width:200px">Terceiro</th><th style="width:130px" class="num">Débito</th><th style="width:130px" class="num">Crédito</th><th style="width:80px">IVA</th><th style="width:120px" class="num">Base IVA</th><th></th></tr></thead>
        <tbody id="lines"></tbody></table></div>
      <div class="balance-bar" id="balance"></div>
      ${readonly ? '' : '<button id="add-line">+ Linha</button>'}
    </div>
    <div class="toolbar no-print" style="justify-content:flex-end">
      ${readonly ? `${e.reversed_by ? '' : '<button id="reverse" class="danger">Estornar</button>'}<button onclick="window.print()">Imprimir</button>`
        : `${id ? '<button id="delete" class="danger">Apagar rascunho</button>' : ''}<button id="save">Guardar rascunho</button><button class="primary" id="validate">Validar</button>`}
    </div>`;

  const tbody = view.querySelector('#lines');
  const lineRow = (l = {}) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input name="account" list="dl-accounts" value="${esc(l.account || '')}" class="mono" ${readonly ? 'disabled' : ''}><div class="acc-name"></div></td>
      <td><input name="description" value="${esc(l.description || '')}" ${readonly ? 'disabled' : ''}></td>
      <td><input name="party" list="dl-parties" value="${esc(partyLabel(l.third_party))}" ${readonly ? 'disabled' : ''}></td>
      <td><input name="debit" class="num" inputmode="decimal" value="${l.debit_cents ? money(l.debit_cents) : ''}" ${readonly ? 'disabled' : ''}></td>
      <td><input name="credit" class="num" inputmode="decimal" value="${l.credit_cents ? money(l.credit_cents) : ''}" ${readonly ? 'disabled' : ''}></td>
      <td><select name="vat_code" ${readonly ? 'disabled' : ''}>${vatOpts(l.vat_code)}</select></td>
      <td><input name="vat_base" class="num" value="${l.vat_base_cents ? money(l.vat_base_cents) : ''}" ${readonly ? 'disabled' : ''}></td>
      <td>${readonly ? '' : '<button class="icon-btn" data-del title="Remover linha">✕</button>'}</td>`;
    const acc = tr.querySelector('[name=account]');
    const showName = () => {
      const a = accMap[acc.value.trim()];
      const nm = tr.querySelector('.acc-name');
      nm.textContent = a ? a.name + (Number(a.movement) ? '' : ' (tem subcontas)') : (acc.value ? 'Conta inexistente' : '');
      nm.style.color = a && Number(a.movement) ? '' : 'var(--bad)';
    };
    acc.addEventListener('input', showName);
    showName();
    tr.querySelectorAll('input').forEach((i) => i.addEventListener('input', updateBalance));
    tr.querySelector('[name=debit]')?.addEventListener('input', (ev) => { if (ev.target.value) tr.querySelector('[name=credit]').value = ''; });
    tr.querySelector('[name=credit]')?.addEventListener('input', (ev) => { if (ev.target.value) tr.querySelector('[name=debit]').value = ''; });
    tr.querySelector('[data-del]')?.addEventListener('click', () => { tr.remove(); updateBalance(); });
    tr.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && !readonly) { ev.preventDefault(); if (tr === tbody.lastElementChild) addLine(true); }
    });
    return tr;
  };
  const addLine = (focus) => {
    // sugere o valor que equilibra o lançamento
    const { d, c } = totals();
    const l = {};
    if (d > c) l.credit_cents = d - c; else if (c > d) l.debit_cents = c - d;
    const tr = lineRow(l);
    tbody.appendChild(tr);
    if (focus) tr.querySelector('[name=account]').focus();
    updateBalance();
  };
  const totals = () => {
    let d = 0, c = 0, bad = false;
    tbody.querySelectorAll('tr').forEach((tr) => {
      const dv = toCents(tr.querySelector('[name=debit]').value), cv = toCents(tr.querySelector('[name=credit]').value);
      if (Number.isNaN(dv) || Number.isNaN(cv)) bad = true;
      d += dv || 0; c += cv || 0;
    });
    return { d, c, bad };
  };
  function updateBalance() {
    const { d, c, bad } = totals();
    view.querySelector('#balance').innerHTML = `<span>Débito <b>${money(d)}</b></span><span>Crédito <b>${money(c)}</b></span>
      <span class="diff ${d !== c || bad ? 'bad' : ''}">${bad ? 'Valor inválido' : d === c ? (d ? 'Equilibrado ✓' : '') : 'Diferença ' + money(d - c)}</span>`;
  }
  (e.lines.length ? e.lines : [{}, {}]).forEach((l) => tbody.appendChild(lineRow(l)));
  updateBalance();

  const collect = () => {
    const head = Object.fromEntries([...view.querySelectorAll('#head [name]')].map((i) => [i.name, i.value]));
    const lines = [...tbody.querySelectorAll('tr')].map((tr) => {
      const get = (n) => tr.querySelector(`[name=${n}]`).value.trim();
      const plabel = get('party');
      if (plabel && !partyByLabel[plabel]) throw new Error(`Terceiro desconhecido: "${plabel}". Crie-o em Clientes e fornecedores.`);
      return { account: get('account'), description: get('description'), third_party: plabel ? partyByLabel[plabel].id : null,
        debit: get('debit'), credit: get('credit'), vat_code: get('vat_code'), vat_base: get('vat_base') };
    });
    return { ...head, id, lines };
  };
  const save = async (validate) => {
    const r = await api.post('entries.save', { ...collect(), validate });
    toast(validate ? `Lançamento validado: ${r.journal} / ${r.number}` : 'Rascunho guardado.', 'good');
    await refreshInfo();
    if (validate && !id) go(cpath(`lancamento/novo?journal=${r.journal}&date=${r.date}`));
    else go(cpath('lancamento/' + r.id));
  };
  const btn = (sel, fn) => { const b = view.querySelector(sel); if (b) b.onclick = async () => { b.disabled = true; try { await fn(); } catch (err) { toast(err.message, 'bad'); } finally { b.disabled = false; } }; };
  btn('#add-line', async () => addLine(true));
  btn('#save', () => save(false));
  btn('#validate', () => save(true));
  btn('#delete', async () => {
    if (!(await confirmDialog('Apagar rascunho', 'O rascunho será apagado definitivamente.', 'Apagar'))) return;
    await api.post('entries.delete', { id });
    await refreshInfo();
    toast('Rascunho apagado.');
    go(cpath('lancamentos'));
  });
  btn('#reverse', async () => {
    if (!(await confirmDialog('Estornar lançamento', 'É criado e validado um novo lançamento com débitos e créditos trocados, com a mesma data. O original fica marcado como estornado.', 'Estornar'))) return;
    const r = await api.post('entries.reverse', { id });
    toast(`Estorno criado: ${r.journal} / ${r.number}`, 'good');
    go(cpath('lancamento/' + r.id));
  });
  if (!readonly) view.querySelector('#head [name=description]').focus();
}
