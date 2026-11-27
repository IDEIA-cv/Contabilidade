// Vendas: documentos comerciais (faturas, faturas-recibo, orçamentos, pro-formas, guias, notas de crédito, recibos) e artigos.
import { api } from '../api.js';
import { setCrumbs, go, cpath, state } from '../main.js';
import { invalidateRefs } from './entries.js';
import { esc, money, toCents, today, fmtDate, toast, modal, confirmDialog, formData, debounce, pager, bindPager } from '../util.js';
import { tableToXlsx } from '../xlsx.js';

let META = null;
async function meta() { return (META ??= await api.get('sales.meta')); }

/** Tipos que se criam diretamente (NC e RC nascem de uma fatura). */
const CREATABLE = ['FT', 'FR', 'OR', 'PF', 'GE', 'GT', 'ND'];
const QUICK = [['FT', 'Nova fatura'], ['FR', 'Nova fatura-recibo'], ['OR', 'Novo orçamento']];
const STATUS = { rascunho: ['Rascunho', 'warn'], emitido: ['Emitido', 'good'], anulado: ['Anulado', 'bad'] };

const saleVats = () => state.info.vat_codes.filter((v) => v.kind !== 'dedutivel');
const pctText = (bp) => (Number(bp) / 100).toLocaleString('pt-PT', { maximumFractionDigits: 2 }) + '%';
const qtyText = (milli) => (Number(milli) / 1000).toLocaleString('pt-PT', { maximumFractionDigits: 3 });
const toMilli = (t) => { const n = Number(String(t ?? '').replace(/\s/g, '').replace(',', '.')); return Number.isFinite(n) ? Math.round(n * 1000) : NaN; };
const toBp = (t) => { const n = Number(String(t ?? '').replace(/[\s%]/g, '').replace(',', '.') || 0); return Number.isFinite(n) ? Math.round(n * 100) : NaN; };

/** Igual a Sales::lineAmounts no servidor (arredondamento ao centavo, metade para cima). */
function lineAmounts(qtyMilli, price, discBp, rateBp) {
  const gross = Math.floor((qtyMilli * price + 500) / 1000);
  const disc = Math.floor((gross * discBp + 5000) / 10000);
  const net = gross - disc;
  return { net, disc, vat: Math.floor((net * rateBp + 5000) / 10000) };
}

function openPrint(id) {
  window.open(api.href('sales.print', { id }), '_blank', 'noopener');
}

// =========================================================================== entrada

export async function render(ctx) {
  await meta();
  if (ctx.section === 'artigos') return renderItems(ctx);
  if (ctx.section === 'dividas') return renderDebtors(ctx);
  if (ctx.section === 'cliente') return renderCustomer(ctx, Number(ctx.params[0]));
  const [p] = ctx.params;
  if (p === 'novo') return renderEditor(ctx, null);
  if (p) return renderDoc(ctx, Number(p));
  return renderList(ctx);
}

// =========================================================================== lista

async function renderList({ view, query }) {
  setCrumbs('Vendas');
  const T = META.types;
  const f = { type: query.type || '', status: query.status || '', pay: query.pay || '', q: query.q || '', from: query.from || '', to: query.to || '', offset: Number(query.offset || 0) };
  const [summary, res] = await Promise.all([
    api.get('sales.summary', { year: state.year }),
    api.get('sales.list', { ...f, limit: 50 }),
  ]);
  const typeOpts = [['', 'Todos os documentos'], ['faturas', 'Faturas e notas (com valor fiscal)'], ...Object.entries(T).map(([k, v]) => [k, v.plural])];
  const otherTypes = CREATABLE.filter((t) => !QUICK.some(([q]) => q === t));
  const filtered = Object.entries(f).some(([k, v]) => k !== 'offset' && v);
  view.innerHTML = `
    <div class="page-head"><div><h1>Vendas</h1><p class="muted">Faturas, orçamentos, guias e recibos. A numeração é automática para cada tipo de documento.</p></div>
      <div class="toolbar">${QUICK.map(([t, l], i) => `<button class="${i === 0 ? 'primary' : ''}" data-new="${t}">${l}</button>`).join('')}
        <label class="sr-only" for="new-other">Outro documento</label>
        <select id="new-other"><option value="">Outro documento…</option>${otherTypes.map((t) => `<option value="${t}">${T[t].name}</option>`).join('')}</select></div></div>
    <div class="kpis">
      <div class="kpi" data-href="?type=faturas"><div class="label">Faturado em ${state.year}</div><div class="value">${money(summary.invoiced)}</div><div class="small muted">faturas − notas de crédito</div></div>
      <div class="kpi" data-href="?pay=aberto"><div class="label">Por receber</div><div class="value">${money(summary.open)}</div><div class="small muted">faturas emitidas não pagas</div></div>
      <div class="kpi" data-href="?pay=vencido"><div class="label">Vencido</div><div class="value ${summary.overdue ? 'neg' : ''}">${money(summary.overdue)}</div><div class="small muted">já passou o prazo</div></div>
      <div class="kpi" data-href="?status=rascunho"><div class="label">Rascunhos</div><div class="value">${summary.drafts}</div><div class="small muted">por emitir</div></div>
      <div class="kpi" data-href="?type=OR&status=emitido"><div class="label">Orçamentos em aberto</div><div class="value">${summary.quotes_open}</div><div class="small muted">válidos e ainda não faturados</div></div>
    </div>
    <div class="card">
      <form class="form" id="filters" role="search" aria-label="Filtros">
        <label>Tipo<select name="type">${typeOpts.map(([k, l]) => `<option value="${k}" ${f.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>Estado<select name="status"><option value="">Todos</option>${Object.entries(STATUS).map(([k, [l]]) => `<option value="${k}" ${f.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>Pagamento<select name="pay"><option value="">Todos</option><option value="aberto" ${f.pay === 'aberto' ? 'selected' : ''}>Por receber</option><option value="vencido" ${f.pay === 'vencido' ? 'selected' : ''}>Vencido</option><option value="pago" ${f.pay === 'pago' ? 'selected' : ''}>Pago</option></select></label>
        <label>Pesquisar<input name="q" value="${esc(f.q)}" placeholder="N.º, cliente, NIF ou referência"></label>
        <label>De<input type="date" name="from" value="${esc(f.from)}"></label>
        <label>Até<input type="date" name="to" value="${esc(f.to)}"></label>
      </form>
      <div class="toolbar" style="margin-top:10px;justify-content:space-between">
        <span class="muted small">${res.total} documento(s)${f.type === 'faturas' || f.pay ? ` · faturado ${money(res.invoiced)} · por receber ${money(res.open)}` : ''}</span>
        <span class="toolbar">${filtered ? '<button id="clear">Limpar filtros</button>' : ''}<button id="xlsx">Excel</button></span>
      </div>
    </div>
    <div class="card">${pager(res.total, res.offset, res.limit, 'documentos')}
      <div class="table-wrap"><table id="list"><caption class="sr-only">Documentos de venda</caption>
        <thead><tr><th>Documento</th><th>Data</th><th>Cliente</th><th class="num">Total</th><th class="num">Por receber</th><th>Estado</th></tr></thead>
        <tbody>${res.rows.map((r) => {
          const [sl, sc] = STATUS[r.status];
          const open = r.open_cents !== null ? Number(r.open_cents) : null;
          const overdue = open > 0 && r.due_date && r.due_date < today();
          const payBadge = open === null ? '' : open <= 0 ? '<span class="badge good">Pago</span>' : overdue ? '<span class="badge bad">Vencido</span>' : '<span class="badge warn">Por pagar</span>';
          return `<tr class="clickable" data-id="${r.id}">
            <td><a href="#/${cpath('vendas/' + r.id)}">${esc(r.doc_no || T[r.type].name + ' (rascunho)')}</a><div class="small muted">${esc(T[r.type].name)}${r.derived ? ' → ' + esc(r.derived) : ''}</div></td>
            <td>${fmtDate(r.date)}${r.due_date && open > 0 ? `<div class="small ${overdue ? 'neg' : 'muted'}">vence ${fmtDate(r.due_date)}</div>` : ''}</td>
            <td>${r.third_party ? `<a href="#/${cpath('cliente/' + r.third_party)}">${esc(r.customer)}</a>` : esc(r.customer || 'Consumidor final')}${r.nif ? `<div class="small muted mono">${esc(r.nif)}</div>` : ''}</td>
            <td class="num">${r.type === 'NC' ? '-' : ''}${money(r.total_cents)}</td>
            <td class="num">${open !== null ? money(open) : ''}</td>
            <td><span class="badge ${sc}">${sl}</span> ${payBadge}</td></tr>`;
        }).join('') || `<tr><td colspan="6" class="empty">${filtered ? 'Nenhum documento com estes filtros.' : 'Ainda não há documentos. Comece por <b>Nova fatura</b> ou <b>Novo orçamento</b>.'}</td></tr>`}</tbody>
      </table></div>${pager(res.total, res.offset, res.limit, 'documentos')}</div>`;

  const setQuery = (patch) => {
    const q = { ...f, ...patch };
    const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v !== 0));
    go(cpath('vendas' + (qs.toString() ? '?' + qs : '')));
  };
  const form = view.querySelector('#filters');
  form.onsubmit = (e) => e.preventDefault();
  form.querySelectorAll('select, input[type=date]').forEach((el) => { el.onchange = () => setQuery({ ...formData(form), offset: 0 }); });
  form.querySelector('[name=q]').oninput = debounce(() => setQuery({ ...formData(form), offset: 0 }), 500);
  view.querySelector('#clear')?.addEventListener('click', () => go(cpath('vendas')));
  view.querySelectorAll('[data-new]').forEach((b) => { b.onclick = () => go(cpath('vendas/novo?type=' + b.dataset.new)); });
  view.querySelector('#new-other').onchange = (e) => { if (e.target.value) go(cpath('vendas/novo?type=' + e.target.value)); };
  view.querySelectorAll('.kpi[data-href]').forEach((k) => { k.onclick = () => go(cpath('vendas' + k.dataset.href)); });
  view.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = (e) => { if (e.target.tagName !== 'A') go(cpath('vendas/' + tr.dataset.id)); }; });
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#list'), `vendas_${today()}.xlsx`, { sheet: 'Vendas', title: `Vendas — ${state.info.name}` });
  bindPager(view, (offset) => setQuery({ offset }));
  if (f.q) { const q = form.querySelector('[name=q]'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
}

// =========================================================================== clientes (lista para escolher)

async function customers() {
  const all = await api.get('thirdparties.list');
  return all.filter((t) => t.kind !== 'F');
}
const custLabel = (c) => c ? `${c.name}${c.nif ? ' — ' + c.nif : ''}` : '';

function quickCustomer(onSaved) {
  modal({
    title: 'Novo cliente',
    body: `<div class="form">
      <label class="wide">Nome<input name="name" autocomplete="off"></label>
      <label>NIF<input name="nif" class="mono" maxlength="9" inputmode="numeric" placeholder="9 dígitos"></label>
      <label>Telefone<input name="phone"></label>
      <label class="wide">Morada<input name="address"></label>
      <label>Cidade<input name="city"></label>
      <label>Email<input name="email" type="email"></label></div>`,
    actions: [{ label: 'Cancelar' }, { label: 'Guardar cliente', primary: true, onClick: async (el) => {
      const c = await api.post('thirdparties.save', { ...formData(el), kind: 'C' });
      invalidateRefs();
      toast('Cliente guardado.', 'good');
      onSaved(c);
    } }],
  });
}

// =========================================================================== editor de rascunhos

async function renderEditor({ view, query }, doc) {
  const T = META.types;
  const type = doc ? doc.type : (CREATABLE.includes(query.type) ? query.type : 'FT');
  const info = T[type];
  setCrumbs('Vendas', doc ? `${info.name} (rascunho)` : `Novo: ${info.name}`);
  const [custs, items] = await Promise.all([customers(), api.get('items.list')]);
  const itemByLabel = Object.fromEntries(items.map((i) => [`${i.code} — ${i.name}`, i]));
  const custByLabel = Object.fromEntries(custs.map((c) => [custLabel(c), c]));
  const d = doc || { type, date: today(), lines: [], payment_method: type === 'FR' ? 'NU' : '' };
  const curCust = custs.find((c) => String(c.id) === String(d.third_party ?? query.party));
  const vats = saleVats();
  const defVat = vats.find((v) => Number(v.rate_bp) > 0)?.code || vats[0]?.code;
  const isGuide = type === 'GE' || type === 'GT';

  view.innerHTML = `
    <div class="page-head"><div><h1>${doc ? `${esc(info.name)} <span class="badge warn">Rascunho</span>` : `Novo documento`}</h1>
      <p class="muted">${doc?.origin ? `Criado a partir de <a href="#/${cpath('vendas/' + doc.origin.id)}">${esc(doc.origin.doc_no)}</a>. ` : ''}Preencha os dados e carregue em <b>Emitir</b>. O número é atribuído só na emissão.</p></div>
      <div class="toolbar"><a class="btn" href="#/${cpath('vendas')}">Voltar à lista</a></div></div>
    <datalist id="dl-items">${Object.keys(itemByLabel).map((l) => `<option value="${esc(l)}"></option>`).join('')}</datalist>
    <datalist id="dl-custs">${Object.keys(custByLabel).map((l) => `<option value="${esc(l)}"></option>`).join('')}</datalist>
    <datalist id="dl-exempt">${META.exemptions.map((x) => `<option value="${esc(x)}"></option>`).join('')}</datalist>
    <div class="card"><div class="form" id="head">
      <label>Tipo de documento<select name="type" ${doc ? 'disabled' : ''}>${[...new Set([...CREATABLE, type])].map((t) => `<option value="${t}" ${t === type ? 'selected' : ''}>${T[t].name}</option>`).join('')}</select></label>
      <label class="cust-field">Cliente${type === 'FR' ? ' (opcional)' : ''}
        <span style="display:flex;gap:6px"><input name="customer" list="dl-custs" value="${esc(custLabel(curCust))}" placeholder="Escreva o nome ou NIF" autocomplete="off" style="flex:1">
        <button type="button" id="new-cust" title="Criar um cliente novo">+ Novo</button></span>
        <span class="small" id="cust-hint"></span></label>
      ${type === 'FR' ? `<label>Nome do cliente (sem ficha)<input name="customer_name" value="${esc(d.customer_name || '')}" placeholder="Consumidor final"></label>
        <label>NIF (sem ficha)<input name="customer_nif" class="mono" maxlength="9" inputmode="numeric" value="${esc(d.customer_nif || '')}"></label>` : ''}
      <label>Data<input type="date" name="date" value="${esc(d.date)}"></label>
      ${info.due_label ? `<label>${esc(info.due_label)}<input type="date" name="due_date" value="${esc(d.due_date || '')}"><span class="small muted">vazio = ${info.due} dias</span></label>` : ''}
      ${type === 'FR' ? `<label>Meio de pagamento<select name="payment_method">${Object.entries(META.payment_methods).map(([k, v]) => `<option value="${k}" ${d.payment_method === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>` : ''}
      ${isGuide ? `<label>Local de entrega<input name="delivery_place" value="${esc(d.delivery_place || '')}"></label>` : ''}
      <label>Referência do cliente<input name="reference" value="${esc(d.reference || '')}" placeholder="N.º de encomenda (opcional)"></label>
    </div></div>
    <div class="card">
      <h2>Artigos</h2>
      <div class="table-wrap"><table class="lines sales-lines">
        <thead><tr><th style="width:24%">Artigo</th><th>Descrição</th><th class="num" style="width:80px">Qtd.</th><th class="num" style="width:120px">Preço s/ IVA</th><th class="num" style="width:70px">Desc. %</th><th style="width:110px">IVA</th><th class="num" style="width:120px">Total</th><th></th></tr></thead>
        <tbody id="lines"></tbody></table></div>
      <div class="toolbar" style="justify-content:space-between;margin-top:8px">
        <span class="toolbar"><button id="add-line">+ Acrescentar linha</button><a href="#/${cpath('artigos')}" class="small">Gerir artigos</a></span>
        <table class="sales-totals" aria-label="Totais"><tbody id="totals"></tbody></table>
      </div>
    </div>
    <div class="card"><label class="form" style="display:block">Observações (aparecem no documento)<textarea name="notes" id="notes" rows="3" style="width:100%">${esc(d.notes || '')}</textarea></label></div>
    <div class="toolbar no-print sticky-actions" style="justify-content:flex-end">
      ${doc ? '<button id="delete" class="danger">Apagar rascunho</button><button id="preview">Pré-visualizar</button>' : ''}
      <button id="save">Guardar rascunho</button><button class="primary" id="issue">Emitir ${esc(info.name.toLowerCase())}</button>
    </div>`;

  const head = view.querySelector('#head');
  head.querySelector('[name=type]').onchange = (e) => go(cpath('vendas/novo?type=' + e.target.value));
  const custInput = head.querySelector('[name=customer]');
  const custHint = view.querySelector('#cust-hint');
  const showCust = () => {
    const c = custByLabel[custInput.value];
    custHint.textContent = !custInput.value ? (info.party ? 'Obrigatório' : '') : c ? [c.address, c.city].filter(Boolean).join(', ') || 'Cliente sem morada registada' : 'Cliente não encontrado. Escolha da lista ou use "+ Novo".';
    custHint.className = 'small ' + (custInput.value && !c ? 'neg' : 'muted');
  };
  custInput.addEventListener('input', showCust);
  showCust();
  view.querySelector('#new-cust').onclick = () => quickCustomer((c) => {
    custByLabel[custLabel(c)] = c;
    view.querySelector('#dl-custs').insertAdjacentHTML('beforeend', `<option value="${esc(custLabel(c))}"></option>`);
    custInput.value = custLabel(c);
    showCust();
  });

  const tbody = view.querySelector('#lines');
  const vatOpts = (code) => vats.map((v) => `<option value="${esc(v.code)}" data-rate="${v.rate_bp}" ${v.code === code ? 'selected' : ''}>${Number(v.rate_bp) ? pctText(v.rate_bp) : 'Isento'}</option>`).join('');
  const lineRow = (l = {}) => {
    const tr = document.createElement('tr');
    const item = items.find((i) => String(i.id) === String(l.item_id));
    tr.dataset.itemId = l.item_id || '';
    tr.dataset.account = l.account || '';
    tr.dataset.unit = l.unit || '';
    tr.innerHTML = `
      <td data-label="Artigo"><input name="item" list="dl-items" aria-label="Artigo" value="${esc(item ? `${item.code} — ${item.name}` : (l.code || ''))}" placeholder="Pesquisar artigo" autocomplete="off"></td>
      <td data-label="Descrição"><input name="description" aria-label="Descrição" value="${esc(l.description || '')}">
        <input name="exemption" list="dl-exempt" aria-label="Motivo de isenção" class="exempt" placeholder="Motivo de isenção do IVA" value="${esc(l.exemption || '')}"></td>
      <td data-label="Qtd."><input name="qty" aria-label="Quantidade" class="num" inputmode="decimal" value="${esc(l.qty_milli ? qtyText(l.qty_milli) : '1')}"></td>
      <td data-label="Preço s/ IVA"><input name="price" aria-label="Preço sem IVA" class="num" inputmode="decimal" value="${l.price_cents !== undefined ? money(l.price_cents) : ''}"></td>
      <td data-label="Desc. %"><input name="discount" aria-label="Desconto em percentagem" class="num" inputmode="decimal" value="${l.discount_bp ? (l.discount_bp / 100).toString().replace('.', ',') : ''}"></td>
      <td data-label="IVA"><select name="vat_code" aria-label="Taxa de IVA">${vatOpts(l.vat_code || defVat)}</select></td>
      <td data-label="Total" class="num line-total"></td>
      <td><button class="icon-btn" data-del aria-label="Remover linha" title="Remover linha">✕</button></td>`;
    const q = (n) => tr.querySelector(`[name=${n}]`);
    q('item').addEventListener('change', () => {
      const it = itemByLabel[q('item').value];
      if (!it) { tr.dataset.itemId = ''; return; }
      tr.dataset.itemId = it.id; tr.dataset.account = it.account || ''; tr.dataset.unit = it.unit;
      q('description').value = it.name;
      q('price').value = money(it.price_cents);
      q('vat_code').value = it.vat_code || defVat;
      q('exemption').value = it.exemption || '';
      recalc();
      q('qty').select();
    });
    q('price').addEventListener('blur', () => { const c = toCents(q('price').value); if (!Number.isNaN(c) && q('price').value) q('price').value = money(c); });
    tr.querySelectorAll('input, select').forEach((i) => i.addEventListener('input', recalc));
    tr.querySelector('[data-del]').onclick = () => { tr.remove(); if (!tbody.children.length) addLine(); recalc(); };
    tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); if (tr === tbody.lastElementChild) addLine(true); } });
    return tr;
  };
  const addLine = (focus) => { const tr = lineRow(); tbody.appendChild(tr); if (focus) tr.querySelector('[name=item]').focus(); recalc(); };
  const readLines = () => [...tbody.querySelectorAll('tr')].map((tr) => {
    const q = (n) => tr.querySelector(`[name=${n}]`);
    return {
      tr, item_id: tr.dataset.itemId || null, account: tr.dataset.account || '', unit: tr.dataset.unit || '',
      description: q('description').value.trim(), qty: q('qty').value, price: q('price').value, discount: q('discount').value,
      vat_code: q('vat_code').value, rate: Number(q('vat_code').selectedOptions[0]?.dataset.rate || 0), exemption: q('exemption').value.trim(),
    };
  });
  const recalc = () => {
    let net = 0, disc = 0, vat = 0;
    for (const l of readLines()) {
      const qm = toMilli(l.qty), p = toCents(l.price), bp = toBp(l.discount);
      l.tr.querySelector('.exempt').style.display = l.rate === 0 ? '' : 'none';
      const cell = l.tr.querySelector('.line-total');
      if ([qm, p, bp].some(Number.isNaN)) { cell.innerHTML = '<span class="neg">valor inválido</span>'; continue; }
      const a = lineAmounts(qm, p, bp, l.rate);
      cell.textContent = l.description || l.price ? money(a.net) : '';
      net += a.net; disc += a.disc; vat += a.vat;
    }
    view.querySelector('#totals').innerHTML = isGuide ? '' : `
      ${disc ? `<tr><td>Descontos</td><td class="num">${money(disc)}</td></tr>` : ''}
      <tr><td>Total sem IVA</td><td class="num">${money(net)}</td></tr>
      <tr><td>IVA</td><td class="num">${money(vat)}</td></tr>
      <tr class="grand"><td>Total</td><td class="num">${money(net + vat)} ECV</td></tr>`;
  };
  (d.lines.length ? d.lines : [{}]).forEach((l) => tbody.appendChild(lineRow(l)));
  recalc();
  view.querySelector('#add-line').onclick = () => addLine(true);

  const payload = (issue) => {
    const h = formData(head);
    const c = custByLabel[h.customer];
    if (h.customer && !c) throw new Error('O cliente indicado não existe. Escolha da lista ou crie com "+ Novo".');
    return {
      id: doc?.id || null, type, date: h.date, due_date: h.due_date || '', third_party: c?.id || null,
      customer_name: h.customer_name || '', customer_nif: h.customer_nif || '', reference: h.reference, payment_method: h.payment_method || '',
      delivery_place: h.delivery_place || '', notes: view.querySelector('#notes').value, origin_id: doc?.origin_id || query.origin || null,
      lines: readLines().filter((l) => l.description || l.item_id || l.price).map(({ tr, rate, ...l }) => l),
      issue,
    };
  };
  const save = async (issue) => {
    return api.post('sales.save', payload(issue));
  };
  view.querySelector('#save').onclick = async () => {
    try { const r = await save(false); toast('Rascunho guardado.', 'good'); if (!doc) go(cpath('vendas/' + r.id)); } catch (e) { toast(e.message, 'bad'); }
  };
  view.querySelector('#issue').onclick = async () => {
    let p;
    try { p = payload(false); } catch (e) { return toast(e.message, 'bad'); }
    const fiscal = info.efatura && info.accounting;
    const ok = await confirmDialog(`Emitir ${info.name.toLowerCase()}?`,
      `O documento recebe o próximo número da série ${esc(p.date.slice(0, 4))} e <b>deixa de poder ser alterado</b>.${fiscal ? ' É registado automaticamente na contabilidade. Para corrigir depois, terá de emitir uma nota de crédito ou anulá-lo.' : ''}`, 'Emitir');
    if (!ok) return;
    try {
      const r = await save(true);
      toast(`${info.name} ${r.doc_no} emitida.`, 'good');
      go(cpath('vendas/' + r.id));
    } catch (e) { toast(e.message, 'bad'); }
  };
  view.querySelector('#preview')?.addEventListener('click', async () => { try { await save(false); openPrint(doc.id); } catch (e) { toast(e.message, 'bad'); } });
  view.querySelector('#delete')?.addEventListener('click', async () => {
    if (!await confirmDialog('Apagar rascunho?', 'O rascunho é apagado. Não fica registo na numeração.', 'Apagar')) return;
    await api.post('sales.delete', { id: doc.id });
    toast('Rascunho apagado.');
    go(cpath('vendas'));
  });
  if (!doc) custInput.focus();
}

// =========================================================================== documento emitido

async function renderDoc(ctx, id) {
  const d = await api.get('sales.get', { id });
  if (d.status === 'rascunho') return renderEditor(ctx, d);
  const { view } = ctx;
  const T = META.types;
  const info = T[d.type];
  setCrumbs('Vendas', d.doc_no);
  const [sl, sc] = STATUS[d.status];
  const open = d.open_cents !== null ? Number(d.open_cents) : null;
  const overdue = open > 0 && d.due_date && d.due_date < today();
  const badges = [`<span class="badge ${sc}">${sl}</span>`];
  if (open !== null) badges.push(open <= 0 ? '<span class="badge good">Pago</span>' : `<span class="badge ${overdue ? 'bad' : 'warn'}">${overdue ? 'Vencido' : 'Por pagar'}: ${money(open)}</span>`);
  if (d.efatura_status === 'pendente' && d.status === 'emitido') badges.push('<span class="badge info" title="A ligação à e-Fatura da DNRE será ativada quando houver credenciais">e-Fatura: por comunicar</span>');
  const isGuide = d.type === 'GE' || d.type === 'GT';
  const canPay = d.status === 'emitido' && open > 0;
  const canCredit = d.status === 'emitido' && ['FT', 'FR', 'ND'].includes(d.type);

  view.innerHTML = `
    <div class="page-head"><div><h1>${esc(info.name)} ${esc(d.doc_no)}</h1><p>${badges.join(' ')}</p></div>
      <div class="toolbar no-print">
        <a class="btn" href="#/${cpath('vendas')}">Voltar à lista</a>
        <button class="primary" id="print">Imprimir / PDF</button>
        ${canPay ? '<button class="primary" id="pay">Registar pagamento</button>' : ''}
      </div></div>
    ${d.status === 'anulado' ? `<div class="alert bad">Documento anulado. Motivo: ${esc(d.cancel_reason || '')}</div>` : ''}
    <div class="grid cols-2">
      <div class="card"><h2>Cliente</h2>
        <p><b>${esc(d.customer_name || 'Consumidor final')}</b><br>${d.customer_nif ? 'NIF <span class="mono">' + esc(d.customer_nif) + '</span><br>' : ''}${esc(d.customer_address || '')}</p>
        ${d.third_party ? `<p class="small"><a href="#/${cpath('cliente/' + d.third_party)}">Ficha do cliente: dívida, extrato e documentos</a></p>` : ''}</div>
      <div class="card"><h2>Documento</h2>
        <dl class="dl">
          <dt>Data</dt><dd>${fmtDate(d.date)}</dd>
          ${info.due_label && d.due_date ? `<dt>${esc(info.due_label)}</dt><dd class="${overdue ? 'neg' : ''}">${fmtDate(d.due_date)}</dd>` : ''}
          ${d.payment_method ? `<dt>Pagamento</dt><dd>${esc(META.payment_methods[d.payment_method] || '')}</dd>` : ''}
          ${d.reference ? `<dt>Referência</dt><dd>${esc(d.reference)}</dd>` : ''}
          ${d.delivery_place ? `<dt>Local de entrega</dt><dd>${esc(d.delivery_place)}</dd>` : ''}
          ${d.origin ? `<dt>Origem</dt><dd><a href="#/${cpath('vendas/' + d.origin.id)}">${esc(d.origin.doc_no)}</a></dd>` : ''}
          ${d.entry ? `<dt>Contabilidade</dt><dd><a href="#/${cpath('lancamento/' + d.entry.id)}">Lançamento ${esc(d.entry.journal)}/${esc(d.entry.number)}</a></dd>` : ''}
        </dl></div>
    </div>
    <div class="card">
      ${d.type === 'RC' ? `<h2>Documentos pagos</h2><div class="table-wrap"><table><thead><tr><th>Documento</th><th>Data</th><th class="num">Valor do documento</th><th class="num">Recebido</th></tr></thead>
        <tbody>${d.settlements.map((s) => `<tr><td><a href="#/${cpath('vendas/' + s.invoice_id)}">${esc(s.doc_no)}</a></td><td>${fmtDate(s.date)}</td><td class="num">${money(s.total_cents)}</td><td class="num">${money(s.amount_cents)}</td></tr>`).join('')}</tbody></table></div>`
        : `<h2>Artigos</h2><div class="table-wrap"><table><thead><tr><th>Código</th><th>Descrição</th><th class="num">Qtd.</th>${isGuide ? '<th>Un.</th>' : '<th class="num">Preço</th><th class="num">Desc.</th><th>IVA</th><th class="num">Total</th>'}</tr></thead>
        <tbody>${d.lines.map((l) => `<tr><td class="mono">${esc(l.code || '')}</td><td>${esc(l.description)}${l.exemption ? `<div class="small muted">${esc(l.exemption)}</div>` : ''}</td><td class="num">${qtyText(l.qty_milli)}</td>
          ${isGuide ? `<td>${esc(l.unit || '')}</td>` : `<td class="num">${money(l.price_cents)}</td><td class="num">${Number(l.discount_bp) ? pctText(l.discount_bp) : ''}</td><td>${Number(l.vat_rate_bp) ? pctText(l.vat_rate_bp) : 'Isento'}</td><td class="num">${money(l.net_cents)}</td>`}</tr>`).join('')}</tbody></table></div>`}
      ${isGuide ? '' : `<div class="toolbar" style="justify-content:flex-end"><table class="sales-totals"><tbody>
        ${Number(d.discount_cents) ? `<tr><td>Descontos</td><td class="num">${money(d.discount_cents)}</td></tr>` : ''}
        ${d.type === 'RC' ? '' : `<tr><td>Total sem IVA</td><td class="num">${money(d.net_cents)}</td></tr><tr><td>IVA</td><td class="num">${money(d.vat_cents)}</td></tr>`}
        <tr class="grand"><td>Total</td><td class="num">${money(d.total_cents)} ECV</td></tr></tbody></table></div>`}
      ${d.notes ? `<p class="small" style="white-space:pre-wrap"><b>Observações:</b> ${esc(d.notes)}</p>` : ''}
    </div>
    ${d.receipts.length || d.derived.length ? `<div class="card"><h2>Documentos relacionados</h2><ul>
      ${d.receipts.map((r) => `<li><a href="#/${cpath('vendas/' + r.id)}">${esc(r.doc_no)}</a> · recibo de ${fmtDate(r.date)} · ${money(r.amount_cents)} ${r.status === 'anulado' ? '<span class="badge bad">Anulado</span>' : ''}</li>`).join('')}
      ${d.derived.map((x) => `<li><a href="#/${cpath('vendas/' + x.id)}">${esc(x.doc_no || T[x.type].name + ' (rascunho)')}</a> · ${esc(T[x.type].name)} · ${money(x.total_cents)} ${x.status !== 'emitido' ? `<span class="badge ${STATUS[x.status][1]}">${STATUS[x.status][0]}</span>` : ''}</li>`).join('')}
    </ul></div>` : ''}
    ${d.status === 'emitido' ? `<div class="card no-print"><h2>Outras ações</h2><div class="toolbar">
      ${d.can_convert.map((t) => `<button data-convert="${t}">Converter em ${esc(T[t].name.toLowerCase())}</button>`).join('')}
      ${canCredit ? '<button id="credit">Emitir nota de crédito</button>' : ''}
      ${d.type !== 'RC' ? '<button id="copy">Duplicar</button>' : ''}
      <button id="cancel" class="danger">Anular documento</button></div>
      <p class="small muted">${canCredit ? 'Para corrigir valores ou registar uma devolução, emita uma nota de crédito. Anule apenas se o documento foi emitido por engano.' : 'Anule apenas se o documento foi emitido por engano.'}</p></div>` : ''}`;

  view.querySelector('#print').onclick = () => openPrint(d.id);
  view.querySelector('#pay')?.addEventListener('click', () => payDialog(d, () => renderDoc(ctx, id)));
  view.querySelectorAll('[data-convert]').forEach((b) => {
    b.onclick = async () => {
      const r = await api.post('sales.derive', { id: d.id, type: b.dataset.convert, mode: 'convert' });
      toast(`Rascunho de ${T[r.type].name.toLowerCase()} criado. Reveja e emita.`, 'good');
      go(cpath('vendas/' + r.id));
    };
  });
  view.querySelector('#credit')?.addEventListener('click', async () => {
    const r = await api.post('sales.derive', { id: d.id, type: 'NC', mode: 'credit' });
    toast('Rascunho da nota de crédito criado. Deixe só as linhas e quantidades a creditar.', 'good');
    go(cpath('vendas/' + r.id));
  });
  view.querySelector('#copy')?.addEventListener('click', async () => {
    const r = await api.post('sales.derive', { id: d.id, type: d.type, mode: 'copy' });
    toast('Cópia criada como rascunho.', 'good');
    go(cpath('vendas/' + r.id));
  });
  view.querySelector('#cancel')?.addEventListener('click', () => {
    modal({
      title: `Anular ${d.doc_no}?`,
      body: `<p>O documento fica marcado como anulado${d.entry ? ' e o lançamento contabilístico é estornado' : ''}. O número não volta a ser usado.</p>
        <div class="form"><label class="wide">Motivo da anulação<input name="reason" placeholder="Ex.: emitida para o cliente errado"></label></div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Anular documento', danger: true, onClick: async (el) => {
        await api.post('sales.cancel', { id: d.id, reason: el.querySelector('[name=reason]').value });
        toast('Documento anulado.');
        renderDoc(ctx, id);
      } }],
    });
  });
}

function payDialog(d, done) {
  modal({
    title: `Registar pagamento de ${d.doc_no}`,
    body: `<p>Em dívida: <b>${money(d.open_cents)}</b>. É emitido um recibo e o recebimento é registado na contabilidade.</p>
      <div class="form">
        <label>Data do pagamento<input type="date" name="date" value="${today()}"></label>
        <label>Valor recebido<input name="amount" class="num" inputmode="decimal" value="${money(d.open_cents)}"></label>
        <label>Meio de pagamento<select name="payment_method">${Object.entries(META.payment_methods).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
        <label class="wide">Observações<input name="notes"></label></div>`,
    actions: [{ label: 'Cancelar' }, { label: 'Emitir recibo', primary: true, onClick: async (el) => {
      const r = await api.post('sales.pay', { id: d.id, ...formData(el) });
      toast(`Recibo ${r.doc_no} emitido.`, 'good');
      done();
    } }],
  });
}

// =========================================================================== artigos

async function renderItems({ view, query }) {
  setCrumbs('Artigos');
  const vats = saleVats();
  let rows = await api.get('items.list', { active: 'all' });
  view.innerHTML = `
    <div class="page-head"><div><h1>Artigos</h1><p class="muted">Produtos e serviços que vende. Ao emitir um documento, escolhe o artigo e o preço e o IVA são preenchidos.</p></div>
      <div class="toolbar"><button class="primary" id="new">Novo artigo</button></div></div>
    <div class="card"><div class="form">
      <label>Pesquisar<input id="q" placeholder="Código, descrição ou código de barras" value="${esc(query.q || '')}"></label>
      <label>Tipo<select id="kind"><option value="">Todos</option><option value="P">Produtos</option><option value="S">Serviços</option></select></label>
      <label>Estado<select id="active"><option value="1">Ativos</option><option value="0">Inativos</option><option value="all">Todos</option></select></label>
    </div></div>
    <div class="card"><div class="table-wrap"><table id="items"><caption class="sr-only">Artigos</caption>
      <thead><tr><th>Código</th><th>Descrição</th><th>Tipo</th><th>Unidade</th><th class="num">Preço s/ IVA</th><th>IVA</th><th class="num">Preço c/ IVA</th></tr></thead>
      <tbody id="rows"></tbody></table></div>
      <div class="toolbar" style="justify-content:flex-end;margin-top:8px"><button id="xlsx">Excel</button></div></div>`;
  const draw = () => {
    const q = view.querySelector('#q').value.trim().toLowerCase();
    const k = view.querySelector('#kind').value;
    const a = view.querySelector('#active').value;
    const list = rows.filter((r) => (!k || r.kind === k) && (a === 'all' || String(r.active) === a)
      && (!q || r.code.toLowerCase().includes(q) || r.name.toLowerCase().includes(q) || (r.barcode || '') === q));
    view.querySelector('#rows').innerHTML = list.map((r) => {
      const withVat = r.price_cents + Math.floor((r.price_cents * Number(r.rate_bp || 0) + 5000) / 10000);
      return `<tr class="clickable" data-id="${r.id}" tabindex="0"><td class="mono">${esc(r.code)}</td><td><b>${esc(r.name)}</b>${Number(r.active) ? '' : ' <span class="badge">Inativo</span>'}</td>
        <td>${r.kind === 'S' ? 'Serviço' : 'Produto'}</td><td>${esc(r.unit)}</td><td class="num">${money(r.price_cents)}</td>
        <td>${Number(r.rate_bp) ? pctText(r.rate_bp) : 'Isento'}</td><td class="num">${money(withVat)}</td></tr>`;
    }).join('') || `<tr><td colspan="7" class="empty">${rows.length ? 'Nenhum artigo com estes filtros.' : 'Ainda não há artigos. Carregue em <b>Novo artigo</b>.'}</td></tr>`;
    view.querySelectorAll('tr[data-id]').forEach((tr) => {
      const open = () => edit(rows.find((r) => String(r.id) === tr.dataset.id));
      tr.onclick = open;
      tr.onkeydown = (e) => { if (e.key === 'Enter') open(); };
    });
  };
  const edit = (r = {}) => {
    const isNew = !r.id;
    const m = modal({
      title: isNew ? 'Novo artigo' : `Editar ${r.code}`,
      body: `<div class="form">
        <label>Tipo<select name="kind"><option value="P" ${r.kind !== 'S' ? 'selected' : ''}>Produto</option><option value="S" ${r.kind === 'S' ? 'selected' : ''}>Serviço</option></select></label>
        <label>Código<input name="code" class="mono" value="${esc(r.code || '')}" placeholder="automático"></label>
        <label class="wide">Descrição<input name="name" value="${esc(r.name || '')}"></label>
        <label>Unidade<input name="unit" value="${esc(r.unit || '')}" placeholder="UN, KG, M, H…"></label>
        <label>IVA<select name="vat_code">${vats.map((v) => `<option value="${esc(v.code)}" data-rate="${v.rate_bp}" ${(r.vat_code || vats.find((x) => Number(x.rate_bp))?.code) === v.code ? 'selected' : ''}>${Number(v.rate_bp) ? pctText(v.rate_bp) : 'Isento'}</option>`).join('')}</select></label>
        <label>Preço sem IVA<input name="price" class="num" inputmode="decimal" value="${r.price_cents !== undefined ? money(r.price_cents) : ''}"></label>
        <label>Preço com IVA<input name="price_vat" class="num" inputmode="decimal"><span class="small muted">pode preencher qualquer um dos dois</span></label>
        <label class="wide exempt-row">Motivo de isenção<input name="exemption" list="dl-exempt-m" value="${esc(r.exemption || '')}"></label>
        <datalist id="dl-exempt-m">${META.exemptions.map((x) => `<option value="${esc(x)}"></option>`).join('')}</datalist>
        <details class="wide"><summary>Opções avançadas</summary><div class="form" style="margin-top:8px">
          <label>Conta de rendimentos<input name="account" class="mono" value="${esc(r.account || '')}" placeholder="711 produtos · 721 serviços"></label>
          <label>Código de barras<input name="barcode" value="${esc(r.barcode || '')}"></label>
          <label style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" name="active" ${r.active === undefined || Number(r.active) ? 'checked' : ''}> Ativo</label>
        </div></details></div>`,
      actions: [
        ...(isNew ? [] : [{ label: Number(r.uses) ? 'Desativar' : 'Apagar', danger: true, onClick: async () => {
          const res = await api.post('items.delete', { id: r.id });
          toast(res.result === 'apagado' ? 'Artigo apagado.' : 'O artigo já foi usado em documentos: ficou inativo.');
          rows = await api.get('items.list', { active: 'all' }); draw();
        } }]),
        { label: 'Cancelar' },
        { label: 'Guardar', primary: true, onClick: async (el) => {
          const data = formData(el);
          const price = toCents(data.price);
          if (Number.isNaN(price)) throw new Error('Preço inválido.');
          await api.post('items.save', { ...data, price_cents: price, id: r.id || null });
          toast('Artigo guardado.', 'good');
          rows = await api.get('items.list', { active: 'all' }); draw();
        } },
      ],
    });
    const el = m.el;
    const q = (n) => el.querySelector(`[name=${n}]`);
    const rate = () => Number(q('vat_code').selectedOptions[0].dataset.rate);
    const fromNet = () => { const c = toCents(q('price').value); q('price_vat').value = Number.isNaN(c) || q('price').value === '' ? '' : money(c + Math.floor((c * rate() + 5000) / 10000)); };
    const fromGross = () => { const g = toCents(q('price_vat').value); if (!Number.isNaN(g) && q('price_vat').value !== '') q('price').value = money(Math.round(g * 10000 / (10000 + rate()))); };
    const showExempt = () => { el.querySelector('.exempt-row').style.display = rate() === 0 ? '' : 'none'; };
    q('price').addEventListener('input', fromNet);
    q('price_vat').addEventListener('input', fromGross);
    q('vat_code').addEventListener('change', () => { fromNet(); showExempt(); });
    fromNet(); showExempt();
    q(isNew ? 'name' : 'price').focus();
  };
  view.querySelector('#q').oninput = debounce(draw, 150);
  view.querySelector('#kind').onchange = draw;
  view.querySelector('#active').onchange = draw;
  view.querySelector('#new').onclick = () => edit();
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#items'), `artigos_${today()}.xlsx`, { sheet: 'Artigos', title: `Artigos — ${state.info.name}` });
  draw();
}

// =========================================================================== dívidas de clientes

const BUCKETS = [['a_vencer', 'A vencer'], ['d30', '1–30 dias'], ['d60', '31–60 dias'], ['d90', '61–90 dias'], ['mais90', 'Mais de 90 dias']];

async function renderDebtors({ view, query }) {
  setCrumbs('Dívidas de clientes');
  const f = { q: query.q || '', overdue: query.overdue || '', as_of: query.as_of || today() };
  const r = await api.get('sales.debtors', f);
  const t = r.totals;
  view.innerHTML = `
    <div class="page-head"><div><h1>Dívidas de clientes</h1><p class="muted">Faturas emitidas que ainda não foram pagas, por cliente e por antiguidade (dias depois do vencimento).</p></div>
      <div class="toolbar"><button id="xlsx">Excel</button><button data-print>Imprimir / PDF</button></div></div>
    <div class="kpis">
      <div class="kpi"><div class="label">Total por receber</div><div class="value">${money(t.open)}</div><div class="small muted">${r.rows.length} cliente(s)</div></div>
      <div class="kpi"><div class="label">Vencido</div><div class="value ${t.overdue ? 'neg' : ''}">${money(t.overdue)}</div><div class="small muted">já passou o prazo de pagamento</div></div>
      <div class="kpi"><div class="label">Mais de 90 dias</div><div class="value ${t.mais90 ? 'neg' : ''}">${money(t.mais90)}</div><div class="small muted">difícil de cobrar</div></div>
    </div>
    <div class="card no-print"><form class="form" id="filters" role="search" aria-label="Filtros">
      <label>Pesquisar cliente<input name="q" value="${esc(f.q)}" placeholder="Nome ou NIF"></label>
      <label>Mostrar<select name="overdue"><option value="">Todos os clientes com dívida</option><option value="1" ${f.overdue ? 'selected' : ''}>Só com valores vencidos</option></select></label>
      <label>Data de referência<input type="date" name="as_of" value="${esc(f.as_of)}"></label>
    </form></div>
    <div class="print-head"><b>${esc(state.info.name)}</b> · Dívidas de clientes em ${fmtDate(r.as_of)}</div>
    <div class="card"><div class="table-wrap"><table id="debtors"><caption class="sr-only">Dívidas por cliente</caption>
      <thead><tr><th>Cliente</th><th class="num">Faturas</th>${BUCKETS.map(([, l]) => `<th class="num">${l}</th>`).join('')}<th class="num">Total</th><th class="no-print"></th></tr></thead>
      <tbody>${r.rows.map((x) => `<tr>
        <td>${x.third_party ? `<a href="#/${cpath('cliente/' + x.third_party)}"><b>${esc(x.customer)}</b></a>` : esc(x.customer)}${x.nif ? `<div class="small muted mono">${esc(x.nif)}</div>` : ''}</td>
        <td class="num">${x.docs}</td>
        ${BUCKETS.map(([k]) => `<td class="num ${k !== 'a_vencer' && x[k] ? 'neg' : ''}">${x[k] ? money(x[k]) : ''}</td>`).join('')}
        <td class="num"><b>${money(x.open)}</b></td>
        <td class="no-print">${x.third_party ? `<a class="btn" href="#/${cpath('cliente/' + x.third_party)}">Ver faturas</a>` : ''}</td></tr>`).join('')
        || '<tr><td colspan="9" class="empty">Não há dívidas de clientes. 👍</td></tr>'}</tbody>
      ${r.rows.length ? `<tfoot><tr class="total"><td>Total</td><td></td>${BUCKETS.map(([k]) => `<td class="num">${money(t[k])}</td>`).join('')}<td class="num">${money(t.open)}</td><td class="no-print"></td></tr></tfoot>` : ''}
    </table></div></div>`;
  const form = view.querySelector('#filters');
  form.onsubmit = (e) => e.preventDefault();
  const apply = () => { const q = new URLSearchParams(Object.entries(formData(form)).filter(([k, v]) => v && !(k === 'as_of' && v === today()))); go(cpath('dividas' + (q.toString() ? '?' + q : ''))); };
  form.querySelectorAll('select, input[type=date]').forEach((el) => { el.onchange = apply; });
  form.querySelector('[name=q]').oninput = debounce(apply, 500);
  if (f.q) { const q = form.querySelector('[name=q]'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#debtors'), `dividas_clientes_${r.as_of}.xlsx`, { sheet: 'Dívidas', title: `Dívidas de clientes em ${fmtDate(r.as_of)} — ${state.info.name}` });
}

// =========================================================================== ficha do cliente

async function renderCustomer(ctx, id) {
  const { view, query } = ctx;
  const from = query.from || `${state.year}-01-01`;
  const to = query.to || (String(state.year) === today().slice(0, 4) ? today() : `${state.year}-12-31`);
  const [c, st] = await Promise.all([api.get('sales.customer', { id, year: state.year }), api.get('sales.statement', { id, from, to })]);
  const cu = c.customer;
  setCrumbs('Clientes', cu.name);
  const tab = query.tab || 'dividas';
  const tabs = [['dividas', `Faturas por pagar (${c.open_docs.length})`], ['extrato', 'Extrato de conta corrente'], ['documentos', 'Últimos documentos']];
  view.innerHTML = `
    <div class="page-head"><div><h1>${esc(cu.name)}</h1><p class="muted">${cu.nif ? `NIF <span class="mono">${esc(cu.nif)}</span> · ` : ''}${esc([cu.address, cu.city].filter(Boolean).join(', '))}${cu.phone ? ' · ' + esc(cu.phone) : ''}${cu.email ? ' · ' + esc(cu.email) : ''}</p></div>
      <div class="toolbar no-print"><a class="btn primary" href="#/${cpath('vendas/novo?type=FT&party=' + id)}">Nova fatura</a>
        <button id="print-st">Imprimir extrato e dívida</button><a class="btn" href="#/${cpath('terceiros')}">Editar dados</a></div></div>
    <div class="kpis">
      <div class="kpi"><div class="label">Em dívida</div><div class="value">${money(c.open)}</div><div class="small muted">${c.open_docs.length} fatura(s) por pagar</div></div>
      <div class="kpi"><div class="label">Vencido</div><div class="value ${c.overdue ? 'neg' : ''}">${money(c.overdue)}</div><div class="small muted">já passou o prazo</div></div>
      <div class="kpi"><div class="label">Faturado em ${state.year}</div><div class="value">${money(c.invoiced)}</div><div class="small muted">faturas − notas de crédito</div></div>
      <div class="kpi"><div class="label">Recebido em ${state.year}</div><div class="value">${money(c.received)}</div><div class="small muted">recibos e faturas-recibo</div></div>
    </div>
    <nav class="tabs no-print" aria-label="Secções">${tabs.map(([k, l]) => `<a href="#/${cpath(`cliente/${id}?tab=${k}&from=${from}&to=${to}`)}" class="${tab === k ? 'active' : ''}" ${tab === k ? 'aria-current="page"' : ''}>${l}</a>`).join('')}</nav>
    <div id="tab"></div>`;
  const box = view.querySelector('#tab');
  if (tab === 'dividas') {
    box.innerHTML = `<div class="card"><div class="table-wrap"><table id="t-open"><caption class="sr-only">Faturas por pagar</caption>
      <thead><tr><th>Documento</th><th>Data</th><th>Vencimento</th><th class="num">Total</th><th class="num">Em dívida</th><th>Atraso</th><th class="no-print"></th></tr></thead>
      <tbody>${c.open_docs.map((d) => `<tr><td><a href="#/${cpath('vendas/' + d.id)}">${esc(d.doc_no)}</a></td><td>${fmtDate(d.date)}</td><td>${fmtDate(d.due_date)}</td>
        <td class="num">${money(d.total_cents)}</td><td class="num"><b>${money(d.open_cents)}</b></td>
        <td>${d.days_overdue > 0 ? `<span class="badge ${d.days_overdue > 90 ? 'bad' : 'warn'}">${d.days_overdue} dias</span>` : '<span class="badge">A vencer</span>'}</td>
        <td class="no-print"><button data-pay="${d.id}">Registar pagamento</button></td></tr>`).join('') || '<tr><td colspan="7" class="empty">Este cliente não tem faturas por pagar.</td></tr>'}</tbody>
      ${c.open_docs.length ? `<tfoot><tr class="total"><td colspan="4">Total em dívida</td><td class="num">${money(c.open)}</td><td colspan="2"></td></tr></tfoot>` : ''}</table></div>
      ${c.open_docs.length ? '<div class="toolbar" style="justify-content:flex-end;margin-top:8px"><button id="x-open">Excel</button></div>' : ''}</div>`;
    box.querySelectorAll('[data-pay]').forEach((b) => {
      b.onclick = async () => payDialog(await api.get('sales.get', { id: b.dataset.pay }), () => renderCustomer(ctx, id));
    });
    box.querySelector('#x-open')?.addEventListener('click', () => tableToXlsx(box.querySelector('#t-open'), `faturas_por_pagar_${cu.name}.xlsx`, { sheet: 'Por pagar', title: `Faturas por pagar — ${cu.name}` }));
  } else if (tab === 'extrato') {
    box.innerHTML = `<div class="card no-print"><form class="form" id="per"><label>De<input type="date" name="from" value="${from}"></label><label>Até<input type="date" name="to" value="${to}"></label></form></div>
      <div class="card"><div class="table-wrap"><table id="t-st"><caption class="sr-only">Extrato de conta corrente</caption>
      <thead><tr><th>Data</th><th>Documento</th><th>Vencimento</th><th class="num">Débito</th><th class="num">Crédito</th><th class="num">Saldo</th></tr></thead>
      <tbody><tr><td>${fmtDate(from)}</td><td colspan="4">Saldo anterior</td><td class="num">${money(st.opening)}</td></tr>
      ${st.rows.map((r) => `<tr><td>${fmtDate(r.date)}</td><td><a href="#/${cpath('vendas/' + r.id)}">${esc(r.doc_no)}</a> <span class="small muted">${esc(r.type_name)}</span></td><td>${fmtDate(r.due_date)}</td>
        <td class="num">${r.debit ? money(r.debit) : ''}</td><td class="num">${r.credit ? money(r.credit) : ''}</td><td class="num">${money(r.balance)}</td></tr>`).join('')}</tbody>
      <tfoot><tr class="total"><td colspan="3">Totais do período</td><td class="num">${money(st.debit)}</td><td class="num">${money(st.credit)}</td><td class="num">${money(st.closing)}</td></tr></tfoot></table></div>
      <div class="toolbar" style="justify-content:flex-end;margin-top:8px"><button id="x-st">Excel</button></div></div>`;
    const per = box.querySelector('#per');
    per.querySelectorAll('input').forEach((i) => { i.onchange = () => { const v = formData(per); go(cpath(`cliente/${id}?tab=extrato&from=${v.from}&to=${v.to}`)); }; });
    box.querySelector('#x-st').onclick = () => tableToXlsx(box.querySelector('#t-st'), `extrato_${cu.name}.xlsx`, { sheet: 'Extrato', title: `Extrato de conta corrente — ${cu.name} — ${fmtDate(from)} a ${fmtDate(to)}` });
  } else {
    const T = META.types;
    box.innerHTML = `<div class="card"><div class="table-wrap"><table><thead><tr><th>Documento</th><th>Data</th><th class="num">Total</th><th>Estado</th></tr></thead>
      <tbody>${c.last_docs.map((d) => `<tr><td><a href="#/${cpath('vendas/' + d.id)}">${esc(d.doc_no || T[d.type].name + ' (rascunho)')}</a> <span class="small muted">${esc(T[d.type].name)}</span></td><td>${fmtDate(d.date)}</td><td class="num">${money(d.total_cents)}</td><td><span class="badge ${STATUS[d.status][1]}">${STATUS[d.status][0]}</span></td></tr>`).join('') || '<tr><td colspan="4" class="empty">Sem documentos.</td></tr>'}</tbody></table></div>
      <p class="small"><a href="#/${cpath('vendas?q=' + encodeURIComponent(cu.nif || cu.name))}">Ver todos os documentos deste cliente na lista de vendas</a></p></div>`;
  }
  view.querySelector('#print-st').onclick = () => window.open(api.href('sales.statement_print', { id, from, to }), '_blank', 'noopener');
}
