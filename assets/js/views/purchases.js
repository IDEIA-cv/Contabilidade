// Compras: faturas de fornecedores, despesas pagas, notas de crédito de fornecedores, pagamentos,
// dívidas a fornecedores (antiguidade) e mapa de pagamentos.
import { api } from '../api.js';
import { setCrumbs, go, cpath, state } from '../main.js';
import { invalidateRefs } from './entries.js';
import { esc, money, toCents, today, fmtDate, toast, modal, confirmDialog, formData, debounce, pager, bindPager } from '../util.js';
import { tableToXlsx } from '../xlsx.js';
import { selHead, selCell, selBox, bindSelection } from '../selection.js';

let META = null;
export async function meta() { return (META ??= await api.get('purchases.meta')); }
export function resetMeta() { META = null; }

const STATUS = { rascunho: ['Rascunho', 'warn'], registado: ['Registado', 'good'], anulado: ['Anulado', 'bad'] };
const BUCKETS = [['a_vencer', 'A vencer'], ['d30', '1–30 dias'], ['d60', '31–60 dias'], ['d90', '61–90 dias'], ['mais90', 'Mais de 90 dias']];
const GROUPS = [['vencidas', 'Já vencidas', 'bad'], ['semana', 'Vencem esta semana', 'warn'], ['mes', 'Vencem nos próximos 30 dias', ''], ['depois', 'Vencem mais tarde', '']];

const pctText = (bp) => (Number(bp) / 100).toLocaleString('pt-PT', { maximumFractionDigits: 2 }) + '%';
const qtyText = (milli) => (Number(milli) / 1000).toLocaleString('pt-PT', { maximumFractionDigits: 3 });
const toMilli = (t) => { const n = Number(String(t ?? '').replace(/\s/g, '').replace(',', '.')); return Number.isFinite(n) ? Math.round(n * 1000) : NaN; };
const toBp = (t) => { const n = Number(String(t ?? '').replace(/[\s%]/g, '').replace(',', '.') || 0); return Number.isFinite(n) ? Math.round(n * 100) : NaN; };
function lineAmounts(qtyMilli, price, discBp, rateBp) {
  const gross = Math.floor((qtyMilli * price + 500) / 1000);
  const disc = Math.floor((gross * discBp + 5000) / 10000);
  const net = gross - disc;
  return { net, disc, vat: Math.floor((net * rateBp + 5000) / 10000) };
}
const docLabel = (r) => r.doc_no || META.types[r.type].name + ' (rascunho)';

// =========================================================================== entrada

export async function render(ctx) {
  await meta();
  if (ctx.section === 'dividas-fornecedores') return renderCreditors(ctx);
  if (ctx.section === 'pagamentos') return renderSchedule(ctx);
  const [p] = ctx.params;
  if (p === 'novo') return renderEditor(ctx, null);
  if (p) return renderDoc(ctx, Number(p));
  return renderList(ctx);
}

// =========================================================================== lista

async function renderList({ view, query }) {
  setCrumbs('Compras');
  const T = META.types;
  const f = { type: query.type || '', status: query.status || '', pay: query.pay || '', q: query.q || '', from: query.from || '', to: query.to || '', offset: Number(query.offset || 0) };
  const [summary, res] = await Promise.all([api.get('purchases.summary', { year: state.year }), api.get('purchases.list', { ...f, limit: 50 })]);
  const filtered = Object.entries(f).some(([k, v]) => k !== 'offset' && v);
  view.innerHTML = `
    <div class="page-head"><div><h1>Compras</h1><p class="muted">Registe aqui as faturas que recebe dos fornecedores. O lançamento contabilístico e o IVA dedutível são feitos automaticamente.</p></div>
      <div class="toolbar"><button class="primary" data-new="FC">Registar fatura de fornecedor</button><button data-new="FP">Registar despesa já paga</button></div></div>
    <div class="kpis">
      <div class="kpi" data-href="?type=FC"><div class="label">Compras em ${state.year}</div><div class="value">${money(summary.purchased)}</div><div class="small muted">faturas − notas de crédito</div></div>
      <div class="kpi" data-href="?pay=aberto"><div class="label">Por pagar</div><div class="value">${money(summary.open)}</div><div class="small muted">faturas de fornecedores em aberto</div></div>
      <div class="kpi" data-href="?pay=vencido"><div class="label">Vencido</div><div class="value ${summary.overdue ? 'neg' : ''}">${money(summary.overdue)}</div><div class="small muted">já passou o prazo</div></div>
      <div class="kpi" data-go="pagamentos"><div class="label">Vence nos próximos 7 dias</div><div class="value">${money(summary.week)}</div><div class="small muted">ver mapa de pagamentos</div></div>
      <div class="kpi" data-href="?status=rascunho"><div class="label">Rascunhos</div><div class="value">${summary.drafts}</div><div class="small muted">por registar</div></div>
    </div>
    <div class="card">
      <form class="form" id="filters" role="search" aria-label="Filtros">
        <label>Tipo<select name="type"><option value="">Todos os documentos</option>${Object.entries(T).map(([k, v]) => `<option value="${k}" ${f.type === k ? 'selected' : ''}>${v.plural}</option>`).join('')}</select></label>
        <label>Estado<select name="status"><option value="">Todos</option>${Object.entries(STATUS).map(([k, [l]]) => `<option value="${k}" ${f.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>Pagamento<select name="pay"><option value="">Todos</option><option value="aberto" ${f.pay === 'aberto' ? 'selected' : ''}>Por pagar</option><option value="vencido" ${f.pay === 'vencido' ? 'selected' : ''}>Vencido</option><option value="pago" ${f.pay === 'pago' ? 'selected' : ''}>Pago</option></select></label>
        <label>Pesquisar<input name="q" value="${esc(f.q)}" placeholder="N.º, fornecedor, NIF"></label>
        <label>De<input type="date" name="from" value="${esc(f.from)}"></label>
        <label>Até<input type="date" name="to" value="${esc(f.to)}"></label>
      </form>
      <div class="toolbar" style="margin-top:10px;justify-content:space-between">
        <span class="muted small">${res.total} documento(s)${f.type || f.pay ? ` · compras ${money(res.purchased)} · por pagar ${money(res.open)}` : ''}</span>
        <span class="toolbar">${filtered ? '<button id="clear">Limpar filtros</button>' : ''}<button id="xlsx">Excel</button>${selBox}</span>
      </div>
    </div>
    <div class="card">${pager(res.total, res.offset, res.limit, 'documentos')}
      <div class="table-wrap"><table id="list"><caption class="sr-only">Documentos de compra</caption>
        <thead><tr>${selHead}<th>Documento do fornecedor</th><th>Data</th><th>Fornecedor</th><th class="num">Total</th><th class="num">Por pagar</th><th>Estado</th></tr></thead>
        <tbody>${res.rows.map((r) => {
          const [sl, sc] = STATUS[r.status];
          const open = r.open_cents !== null ? Number(r.open_cents) : null;
          const overdue = open > 0 && r.due_date && r.due_date < today();
          const payBadge = open === null ? '' : open <= 0 ? '<span class="badge good">Pago</span>' : overdue ? '<span class="badge bad">Vencido</span>' : '<span class="badge warn">Por pagar</span>';
          return `<tr class="clickable" data-id="${r.id}">${selCell(r.id, r.supplier_doc || docLabel(r))}
            <td><a href="#/${cpath('compras/' + r.id)}">${esc(r.supplier_doc || docLabel(r))}</a><div class="small muted">${esc(T[r.type].name)}${r.doc_no ? ' · ' + esc(r.doc_no) : ''}</div></td>
            <td>${fmtDate(r.date)}${r.due_date && open > 0 ? `<div class="small ${overdue ? 'neg' : 'muted'}">vence ${fmtDate(r.due_date)}</div>` : ''}</td>
            <td>${r.third_party ? `<a href="#/${cpath('terceiro/' + r.third_party)}">${esc(r.supplier)}</a>` : esc(r.supplier)}${r.nif ? `<div class="small muted mono">${esc(r.nif)}</div>` : ''}</td>
            <td class="num">${r.type === 'NF' ? '-' : ''}${money(r.total_cents)}</td>
            <td class="num">${open !== null ? money(open) : ''}</td>
            <td><span class="badge ${sc}">${sl}</span> ${payBadge}</td></tr>`;
        }).join('') || `<tr><td colspan="7" class="empty">${filtered ? 'Nenhum documento com estes filtros.' : 'Ainda não há compras. Comece por <b>Registar fatura de fornecedor</b>.'}</td></tr>`}</tbody>
      </table></div>${pager(res.total, res.offset, res.limit, 'documentos')}</div>`;

  const setQuery = (patch) => {
    const q = { ...f, ...patch };
    const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== '' && v !== 0));
    go(cpath('compras' + (qs.toString() ? '?' + qs : '')));
  };
  const form = view.querySelector('#filters');
  form.onsubmit = (e) => e.preventDefault();
  form.querySelectorAll('select, input[type=date]').forEach((el) => { el.onchange = () => setQuery({ ...formData(form), offset: 0 }); });
  form.querySelector('[name=q]').oninput = debounce(() => setQuery({ ...formData(form), offset: 0 }), 500);
  view.querySelector('#clear')?.addEventListener('click', () => go(cpath('compras')));
  view.querySelectorAll('[data-new]').forEach((b) => { b.onclick = () => go(cpath('compras/novo?type=' + b.dataset.new)); });
  view.querySelectorAll('.kpi[data-href]').forEach((k) => { k.onclick = () => go(cpath('compras' + k.dataset.href)); });
  view.querySelectorAll('.kpi[data-go]').forEach((k) => { k.onclick = () => go(cpath(k.dataset.go)); });
  view.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = (e) => { if (e.target.tagName !== 'A') go(cpath('compras/' + tr.dataset.id)); }; });
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#list'), `compras_${today()}.xlsx`, { sheet: 'Compras', title: `Compras — ${state.info.name}` });
  bindSelection(view, { key: 'compras', action: 'purchases.export_zip', filters: Object.fromEntries(Object.entries(f).filter(([k, v]) => k !== 'offset' && v)), total: res.total });
  bindPager(view, (offset) => setQuery({ offset }));
  if (f.q) { const q = form.querySelector('[name=q]'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
}

// =========================================================================== fornecedores

async function suppliers() {
  const all = await api.get('thirdparties.list');
  return all.filter((t) => t.kind !== 'C');
}
const supLabel = (c) => c ? `${c.name}${c.nif ? ' — ' + c.nif : ''}` : '';

function quickSupplier(onSaved) {
  modal({
    title: 'Novo fornecedor',
    body: `<div class="form">
      <label class="wide">Nome<input name="name" autocomplete="off"></label>
      <label>NIF<input name="nif" class="mono" maxlength="9" inputmode="numeric" placeholder="9 dígitos"></label>
      <label>Telefone<input name="phone"></label>
      <label class="wide">Morada<input name="address"></label>
      <label>Email<input name="email" type="email"></label></div>`,
    actions: [{ label: 'Cancelar' }, { label: 'Guardar fornecedor', primary: true, onClick: async (el) => {
      const c = await api.post('thirdparties.save', { ...formData(el), kind: 'F' });
      invalidateRefs();
      toast('Fornecedor guardado.', 'good');
      onSaved(c);
    } }],
  });
}

// =========================================================================== editor

async function renderEditor({ view, query }, doc) {
  const T = META.types;
  const type = doc ? doc.type : (['FC', 'FP'].includes(query.type) ? query.type : 'FC');
  const info = T[type];
  setCrumbs('Compras', doc ? `${info.name} (rascunho)` : `Registar: ${info.name}`);
  const sups = await suppliers();
  // artigos (produtos) para ligar a linha da compra ao artigo: dá o custo médio e a margem nas estatísticas de vendas
  const prods = (await api.get('items.list').catch(() => [])).filter((i) => i.kind === 'P' && Number(i.active) !== 0);
  const itemLabel = (i) => `${i.code} — ${i.name}`;
  const itemByLabel = Object.fromEntries(prods.map((i) => [itemLabel(i), i]));
  const itemById = Object.fromEntries(prods.map((i) => [String(i.id), i]));
  const supByLabel = Object.fromEntries(sups.map((c) => [supLabel(c), c]));
  const accLabel = (a) => `${a.code} — ${a.name}`;
  const accByCode = Object.fromEntries(META.accounts.map((a) => [a.code, a]));
  const d = doc || { type, date: today(), lines: [], payment_method: type === 'FP' ? 'NU' : '' };
  const cur = sups.find((c) => String(c.id) === String(d.third_party ?? query.party));
  let lastAccount = '';
  const vats = META.vat_codes;
  const defVat = vats.find((v) => Number(v.rate_bp) > 0)?.code || vats[0]?.code;

  view.innerHTML = `
    <div class="page-head"><div><h1>${doc ? `${esc(info.name)} <span class="badge warn">Rascunho</span>` : esc(info.name)}</h1>
      <p class="muted">${doc?.origin ? `Corrige a fatura <a href="#/${cpath('compras/' + doc.origin.id)}">${esc(doc.origin.supplier_doc || doc.origin.doc_no)}</a>. ` : ''}${type === 'FP' ? 'Para pequenas despesas pagas no momento (talões, combustível, material de escritório). ' : ''}Copie os dados da fatura que recebeu e carregue em <b>Registar</b>.</p></div>
      <div class="toolbar"><a class="btn" href="#/${cpath('compras')}">Voltar à lista</a></div></div>
    <datalist id="dl-sups">${Object.keys(supByLabel).map((l) => `<option value="${esc(l)}"></option>`).join('')}</datalist>
    <datalist id="dl-items">${prods.map((i) => `<option value="${esc(itemLabel(i))}"></option>`).join('')}</datalist>
    <datalist id="dl-accs">${META.accounts.map((a) => `<option value="${esc(accLabel(a))}"></option>`).join('')}</datalist>
    <div class="card"><div class="form" id="head">
      ${doc ? '' : `<label>Tipo de documento<select name="type">${['FC', 'FP'].map((t) => `<option value="${t}" ${t === type ? 'selected' : ''}>${T[t].name}</option>`).join('')}</select></label>`}
      <label class="cust-field">Fornecedor${info.party ? '' : ' (opcional)'}
        <span style="display:flex;gap:6px"><input name="supplier" list="dl-sups" value="${esc(supLabel(cur))}" placeholder="Escreva o nome ou NIF" autocomplete="off" style="flex:1" ${type === 'NF' ? 'readonly' : ''}>
        ${type === 'NF' ? '' : '<button type="button" id="new-sup" title="Criar um fornecedor novo">+ Novo</button>'}</span>
        <span class="small" id="sup-hint"></span></label>
      <label>N.º do documento do fornecedor<input name="supplier_doc" value="${esc(d.supplier_doc || '')}" placeholder="Ex.: FT 2025/123"><span class="small muted">está impresso na fatura</span></label>
      <label>Data do documento<input type="date" name="date" value="${esc(d.date)}"></label>
      ${type === 'FC' ? `<label>Data de vencimento<input type="date" name="due_date" value="${esc(d.due_date || '')}"><span class="small muted">vazio = ${info.due} dias</span></label>` : ''}
      ${type === 'FP' ? `<label>Pago por<select name="payment_method">${Object.entries(META.payment_methods).map(([k, v]) => `<option value="${k}" ${d.payment_method === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>` : ''}
    </div></div>
    <div class="card">
      <h2>O que comprou</h2>
      <p class="small muted">Em cada linha escolha a conta de gasto ou de compra (ex.: 311 Mercadorias, 624 Energia, 6261 Rendas). Para o mesmo fornecedor é sugerida a última conta usada.${prods.length ? ' Se comprou um artigo que também vende, escolha-o na descrição: as estatísticas passam a mostrar a margem desse artigo.' : ''}</p>
      <div class="table-wrap"><table class="lines sales-lines">
        <thead><tr><th>Descrição</th><th style="width:26%">Conta</th><th class="num" style="width:80px">Qtd.</th><th class="num" style="width:120px">Valor s/ IVA</th><th class="num" style="width:70px">Desc. %</th><th style="width:110px">IVA</th><th class="num" style="width:120px">Total</th><th></th></tr></thead>
        <tbody id="lines"></tbody></table></div>
      <div class="toolbar" style="justify-content:space-between;margin-top:8px">
        <button id="add-line">+ Acrescentar linha</button>
        <table class="sales-totals" aria-label="Totais"><tbody id="totals"></tbody></table>
      </div>
      <p class="small muted">Confira que o total é igual ao da fatura do fornecedor.</p>
    </div>
    <div class="card"><label class="form" style="display:block">Observações<textarea name="notes" id="notes" rows="2" style="width:100%">${esc(d.notes || '')}</textarea></label></div>
    <div class="toolbar no-print sticky-actions" style="justify-content:flex-end">
      ${doc ? '<button id="delete" class="danger">Apagar rascunho</button>' : ''}
      <button id="save">Guardar rascunho</button><button class="primary" id="register">Registar</button>
    </div>`;

  const head = view.querySelector('#head');
  head.querySelector('[name=type]')?.addEventListener('change', (e) => go(cpath('compras/novo?type=' + e.target.value)));
  const supInput = head.querySelector('[name=supplier]');
  const hint = view.querySelector('#sup-hint');
  const tbody = view.querySelector('#lines');
  const showSup = async () => {
    const c = supByLabel[supInput.value];
    hint.textContent = !supInput.value ? (info.party ? 'Obrigatório' : '') : c ? (c.nif ? 'NIF ' + c.nif : 'Fornecedor sem NIF registado') : 'Fornecedor não encontrado. Escolha da lista ou use "+ Novo".';
    hint.className = 'small ' + (supInput.value && !c ? 'neg' : 'muted');
    lastAccount = c ? ((await api.get('purchases.last_account', { id: c.id })).account || '') : '';
    if (lastAccount) tbody.querySelectorAll('[name=account]').forEach((i) => { if (!i.value && accByCode[lastAccount]) i.value = accLabel(accByCode[lastAccount]); });
  };
  supInput.addEventListener('change', showSup);
  supInput.addEventListener('input', () => { if (supByLabel[supInput.value] || !supInput.value) showSup(); });
  view.querySelector('#new-sup')?.addEventListener('click', () => quickSupplier((c) => {
    supByLabel[supLabel(c)] = c;
    view.querySelector('#dl-sups').insertAdjacentHTML('beforeend', `<option value="${esc(supLabel(c))}"></option>`);
    supInput.value = supLabel(c);
    showSup();
  }));

  const vatOpts = (code) => vats.map((v) => `<option value="${esc(v.code)}" data-rate="${v.rate_bp}" ${v.code === code ? 'selected' : ''}>${Number(v.rate_bp) ? pctText(v.rate_bp) : 'Isento / sem IVA'}</option>`).join('');
  const lineRow = (l = {}) => {
    const tr = document.createElement('tr');
    const acc = accByCode[l.account] || (lastAccount && accByCode[lastAccount]);
    tr.innerHTML = `
      <td data-label="Descrição"><input name="description" aria-label="Descrição" list="dl-items" value="${esc(l.description || '')}" placeholder="Ex.: Mercadoria, eletricidade de maio" autocomplete="off">
        <span class="small muted item-tag">${l.item_id && itemById[String(l.item_id)] ? 'Artigo ' + esc(itemById[String(l.item_id)].code) : ''}</span></td>
      <td data-label="Conta"><input name="account" list="dl-accs" aria-label="Conta de gasto ou compra" value="${esc(acc ? accLabel(acc) : (l.account || ''))}" placeholder="Pesquisar conta" autocomplete="off"></td>
      <td data-label="Qtd."><input name="qty" aria-label="Quantidade" class="num" inputmode="decimal" value="${esc(l.qty_milli ? qtyText(l.qty_milli) : '1')}"></td>
      <td data-label="Valor s/ IVA"><input name="price" aria-label="Valor sem IVA" class="num" inputmode="decimal" value="${l.price_cents !== undefined ? money(l.price_cents) : ''}"></td>
      <td data-label="Desc. %"><input name="discount" aria-label="Desconto em percentagem" class="num" inputmode="decimal" value="${Number(l.discount_bp) ? (l.discount_bp / 100).toString().replace('.', ',') : ''}"></td>
      <td data-label="IVA"><select name="vat_code" aria-label="IVA">${vatOpts(l.vat_code || defVat)}</select></td>
      <td data-label="Total" class="num line-total"></td>
      <td><button class="icon-btn" data-del aria-label="Remover linha" title="Remover linha">✕</button></td>`;
    const q = (n) => tr.querySelector(`[name=${n}]`);
    if (l.item_id) tr.dataset.item = l.item_id;
    q('description').addEventListener('change', () => {
      const it = itemByLabel[q('description').value];
      if (it) { tr.dataset.item = it.id; q('description').value = it.name; }
      else if (tr.dataset.item && q('description').value !== itemById[tr.dataset.item]?.name) delete tr.dataset.item;
      tr.querySelector('.item-tag').textContent = tr.dataset.item ? 'Artigo ' + (itemById[tr.dataset.item]?.code || '') : '';
    });
    q('price').addEventListener('blur', () => { const c = toCents(q('price').value); if (!Number.isNaN(c) && q('price').value) q('price').value = money(c); });
    tr.querySelectorAll('input, select').forEach((i) => i.addEventListener('input', recalc));
    tr.querySelector('[data-del]').onclick = () => { tr.remove(); if (!tbody.children.length) addLine(); recalc(); };
    tr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); if (tr === tbody.lastElementChild) addLine(true); } });
    return tr;
  };
  const addLine = (focus) => { const tr = lineRow(); tbody.appendChild(tr); if (focus) tr.querySelector('[name=description]').focus(); recalc(); };
  const readLines = () => [...tbody.querySelectorAll('tr')].map((tr) => {
    const q = (n) => tr.querySelector(`[name=${n}]`);
    return { tr, description: q('description').value.trim(), account: q('account').value.split(' ')[0].trim(), qty: q('qty').value, price: q('price').value,
      discount: q('discount').value, vat_code: q('vat_code').value, item_id: tr.dataset.item || null, rate: Number(q('vat_code').selectedOptions[0]?.dataset.rate || 0) };
  });
  const recalc = () => {
    let net = 0, vat = 0;
    for (const l of readLines()) {
      const qm = toMilli(l.qty), p = toCents(l.price), bp = toBp(l.discount);
      const cell = l.tr.querySelector('.line-total');
      if ([qm, p, bp].some(Number.isNaN)) { cell.innerHTML = '<span class="neg">valor inválido</span>'; continue; }
      const a = lineAmounts(qm, p, bp, l.rate);
      cell.textContent = l.description || l.price ? money(a.net + a.vat) : '';
      net += a.net; vat += a.vat;
    }
    view.querySelector('#totals').innerHTML = `
      <tr><td>Total sem IVA</td><td class="num">${money(net)}</td></tr>
      <tr><td>IVA dedutível</td><td class="num">${money(vat)}</td></tr>
      <tr class="grand"><td>Total</td><td class="num">${money(net + vat)} ECV</td></tr>`;
  };
  (d.lines.length ? d.lines : [{}]).forEach((l) => tbody.appendChild(lineRow(l)));
  recalc();
  if (cur) showSup();
  view.querySelector('#add-line').onclick = () => addLine(true);

  const payload = (register) => {
    const h = formData(head);
    const c = supByLabel[h.supplier];
    if (h.supplier && !c) throw new Error('O fornecedor indicado não existe. Escolha da lista ou crie com "+ Novo".');
    return {
      id: doc?.id || null, type, date: h.date, due_date: h.due_date || '', third_party: c?.id || null, supplier_doc: h.supplier_doc,
      payment_method: h.payment_method || '', notes: view.querySelector('#notes').value, origin_id: doc?.origin_id || null,
      lines: readLines().filter((l) => l.description || l.price).map(({ tr, rate, ...l }) => l), register,
    };
  };
  view.querySelector('#save').onclick = async () => {
    try { const r = await api.post('purchases.save', payload(false)); toast('Rascunho guardado.', 'good'); if (!doc) go(cpath('compras/' + r.id)); } catch (e) { toast(e.message, 'bad'); }
  };
  view.querySelector('#register').onclick = async () => {
    let p;
    try { p = payload(true); } catch (e) { return toast(e.message, 'bad'); }
    const ok = await confirmDialog(`Registar ${info.name.toLowerCase()}?`,
      `É feito automaticamente o lançamento na contabilidade${type === 'FC' ? ' e a fatura passa a constar das dívidas a fornecedores' : ''}. Depois de registado, o documento só pode ser corrigido com nota de crédito ou anulado.`, 'Registar');
    if (!ok) return;
    try {
      const r = await api.post('purchases.save', p);
      toast(`${info.name} registada (${r.doc_no}).`, 'good');
      go(cpath('compras/' + r.id));
    } catch (e) { toast(e.message, 'bad'); }
  };
  view.querySelector('#delete')?.addEventListener('click', async () => {
    if (!await confirmDialog('Apagar rascunho?', 'O rascunho é apagado.', 'Apagar')) return;
    await api.post('purchases.delete', { id: doc.id });
    toast('Rascunho apagado.');
    go(cpath('compras'));
  });
  if (!doc) supInput.focus();
}

// =========================================================================== documento registado

async function renderDoc(ctx, id) {
  const d = await api.get('purchases.get', { id });
  if (d.status === 'rascunho') return renderEditor(ctx, d);
  const { view } = ctx;
  const T = META.types;
  const info = T[d.type];
  setCrumbs('Compras', d.supplier_doc || d.doc_no);
  const [sl, sc] = STATUS[d.status];
  const open = d.open_cents !== null ? Number(d.open_cents) : null;
  const overdue = open > 0 && d.due_date && d.due_date < today();
  const badges = [`<span class="badge ${sc}">${sl}</span>`];
  if (open !== null) badges.push(open <= 0 ? '<span class="badge good">Pago</span>' : `<span class="badge ${overdue ? 'bad' : 'warn'}">${overdue ? 'Vencido' : 'Por pagar'}: ${money(open)}</span>`);
  const canPay = d.status === 'registado' && open > 0;
  const canCredit = d.status === 'registado' && d.type === 'FC';

  view.innerHTML = `
    <div class="page-head"><div><h1>${esc(info.name)} ${esc(d.supplier_doc || d.doc_no)}</h1><p>${badges.join(' ')} <span class="small muted">registo interno ${esc(d.doc_no)}</span></p></div>
      <div class="toolbar no-print">
        <a class="btn" href="#/${cpath('compras')}">Voltar à lista</a>
        ${canPay ? '<button class="primary" id="pay">Registar pagamento</button>' : ''}
      </div></div>
    ${d.status === 'anulado' ? `<div class="alert bad">Documento anulado. Motivo: ${esc(d.cancel_reason || '')}</div>` : ''}
    <div class="grid cols-2">
      <div class="card"><h2>Fornecedor</h2>
        <p><b>${esc(d.supplier || 'Sem fornecedor')}</b>${d.supplier_nif ? '<br>NIF <span class="mono">' + esc(d.supplier_nif) + '</span>' : ''}</p>
        ${d.third_party ? `<p class="small"><a href="#/${cpath('terceiro/' + d.third_party)}">Ficha do fornecedor: dívida, extrato e documentos</a></p>` : ''}</div>
      <div class="card"><h2>Documento</h2>
        <dl class="dl">
          <dt>Data</dt><dd>${fmtDate(d.date)}</dd>
          ${d.due_date ? `<dt>Vencimento</dt><dd class="${overdue ? 'neg' : ''}">${fmtDate(d.due_date)}</dd>` : ''}
          ${d.payment_method ? `<dt>Pagamento</dt><dd>${esc(META.payment_methods[d.payment_method] || '')}</dd>` : ''}
          ${d.origin ? `<dt>Corrige</dt><dd><a href="#/${cpath('compras/' + d.origin.id)}">${esc(d.origin.supplier_doc || d.origin.doc_no)}</a></dd>` : ''}
          ${d.entry ? `<dt>Contabilidade</dt><dd><a href="#/${cpath('lancamento/' + d.entry.id)}">Lançamento ${esc(d.entry.journal)}/${esc(d.entry.number)}</a></dd>
            <dt>Digitalização</dt><dd>${d.attachments ? `${d.attachments} anexo(s) · ` : ''}<a href="#/${cpath('lancamento/' + d.entry.id)}">${d.attachments ? 'ver' : 'anexar o PDF ou a foto da fatura'}</a></dd>` : ''}
        </dl></div>
    </div>
    <div class="card">
      ${d.type === 'PG' ? `<h2>Faturas pagas</h2><div class="table-wrap"><table><thead><tr><th>Fatura do fornecedor</th><th>Data</th><th class="num">Valor da fatura</th><th class="num">Pago</th></tr></thead>
        <tbody>${d.settlements.map((s) => `<tr><td><a href="#/${cpath('compras/' + s.invoice_id)}">${esc(s.supplier_doc || s.doc_no)}</a></td><td>${fmtDate(s.date)}</td><td class="num">${money(s.total_cents)}</td><td class="num">${money(s.amount_cents)}</td></tr>`).join('')}</tbody></table></div>`
        : `<h2>Linhas</h2><div class="table-wrap"><table><thead><tr><th>Descrição</th><th>Conta</th><th class="num">Qtd.</th><th class="num">Valor</th><th class="num">Desc.</th><th>IVA</th><th class="num">Total s/ IVA</th></tr></thead>
        <tbody>${d.lines.map((l) => `<tr><td>${esc(l.description)}</td><td><span class="mono">${esc(l.account)}</span> <span class="small muted">${esc(l.account_name || '')}</span></td><td class="num">${qtyText(l.qty_milli)}</td>
          <td class="num">${money(l.price_cents)}</td><td class="num">${Number(l.discount_bp) ? pctText(l.discount_bp) : ''}</td><td>${Number(l.vat_rate_bp) ? pctText(l.vat_rate_bp) : 'Isento'}</td><td class="num">${money(l.net_cents)}</td></tr>`).join('')}</tbody></table></div>`}
      <div class="toolbar" style="justify-content:flex-end"><table class="sales-totals"><tbody>
        ${d.type === 'PG' ? '' : `<tr><td>Total sem IVA</td><td class="num">${money(d.net_cents)}</td></tr><tr><td>IVA dedutível</td><td class="num">${money(d.vat_cents)}</td></tr>`}
        <tr class="grand"><td>Total</td><td class="num">${money(d.total_cents)} ECV</td></tr></tbody></table></div>
      ${d.notes ? `<p class="small" style="white-space:pre-wrap"><b>Observações:</b> ${esc(d.notes)}</p>` : ''}
    </div>
    ${d.payments.length || d.credits.length ? `<div class="card"><h2>Pagamentos e notas de crédito</h2><ul>
      ${d.payments.map((r) => `<li><a href="#/${cpath('compras/' + r.id)}">${esc(r.doc_no)}</a> · pagamento de ${fmtDate(r.date)} · ${money(r.amount_cents)} ${r.status === 'anulado' ? '<span class="badge bad">Anulado</span>' : ''}</li>`).join('')}
      ${d.credits.map((x) => `<li><a href="#/${cpath('compras/' + x.id)}">${esc(x.supplier_doc || docLabel(x))}</a> · nota de crédito · ${money(x.total_cents)} ${x.status !== 'registado' ? `<span class="badge ${STATUS[x.status][1]}">${STATUS[x.status][0]}</span>` : ''}</li>`).join('')}
    </ul></div>` : ''}
    ${d.status === 'registado' ? `<div class="card no-print"><h2>Outras ações</h2><div class="toolbar">
      ${canCredit ? '<button id="credit">Registar nota de crédito do fornecedor</button>' : ''}
      <button id="cancel" class="danger">Anular registo</button></div>
      <p class="small muted">${canCredit ? 'Se o fornecedor lhe enviou uma nota de crédito (devolução, desconto), registe-a aqui. Anule apenas se a fatura foi registada por engano.' : 'Anule apenas se o documento foi registado por engano.'}</p></div>` : ''}`;

  view.querySelector('#pay')?.addEventListener('click', () => payDialog(d.third_party, [d], () => renderDoc(ctx, id)));
  view.querySelector('#credit')?.addEventListener('click', async () => {
    const r = await api.post('purchases.credit', { id: d.id });
    toast('Rascunho da nota de crédito criado. Indique o número e os valores que constam da nota do fornecedor.', 'good');
    go(cpath('compras/' + r.id));
  });
  view.querySelector('#cancel')?.addEventListener('click', () => {
    modal({
      title: `Anular ${d.supplier_doc || d.doc_no}?`,
      body: `<p>O registo fica anulado e o lançamento contabilístico é estornado.</p>
        <div class="form"><label class="wide">Motivo da anulação<input name="reason" placeholder="Ex.: fatura registada em duplicado"></label></div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Anular registo', danger: true, onClick: async (el) => {
        await api.post('purchases.cancel', { id: d.id, reason: el.querySelector('[name=reason]').value });
        toast('Registo anulado.');
        renderDoc(ctx, id);
      } }],
    });
  });
}

/** Pagamento de uma ou várias faturas do mesmo fornecedor. `docs`: faturas em dívida (com open_cents). */
export async function payDialog(party, docs, done) {
  await meta();
  const many = docs.length > 1;
  // data por omissão: hoje, se estiver no exercício aberto no topo; senão a data da fatura mais recente
  const payDate = today().startsWith(String(state.year)) ? today() : docs.map((x) => x.date).sort().pop();
  const m = modal({
    title: many ? 'Registar pagamento ao fornecedor' : `Registar pagamento de ${docs[0].supplier_doc || docs[0].doc_no}`,
    wide: many,
    body: `<p>${many ? 'Escolha as faturas que este pagamento liquida.' : `Em dívida: <b>${money(docs[0].open_cents)}</b>.`} O pagamento é registado na contabilidade (caixa ou banco).</p>
      ${many ? `<div class="table-wrap"><table><thead><tr><th><span class="sr-only">Pagar</span></th><th>Fatura</th><th>Vencimento</th><th class="num">Em dívida</th></tr></thead><tbody>
        ${docs.map((x) => `<tr><td><input type="checkbox" data-inv="${x.id}" data-open="${x.open_cents}" checked aria-label="Pagar ${esc(x.supplier_doc || x.doc_no)}"></td><td>${esc(x.supplier_doc || x.doc_no)}</td><td>${fmtDate(x.due_date)}</td><td class="num">${money(x.open_cents)}</td></tr>`).join('')}
        </tbody></table></div><p>Total a pagar: <b id="pay-total"></b></p>` : ''}
      <div class="form">
        <label>Data do pagamento<input type="date" name="date" value="${payDate}"></label>
        ${many ? '' : `<label>Valor pago<input name="amount" class="num" inputmode="decimal" value="${money(docs[0].open_cents)}"></label>`}
        <label>Pago por<select name="payment_method">${Object.entries(META.payment_methods).map(([k, v]) => `<option value="${k}" ${k === 'TB' ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        <label class="wide">Observações<input name="notes"></label></div>`,
    actions: [{ label: 'Cancelar' }, { label: 'Registar pagamento', primary: true, onClick: async (el) => {
      const f = formData(el.querySelector('.form'));
      const invoices = many ? [...el.querySelectorAll('[data-inv]:checked')].map((c) => Number(c.dataset.inv)) : [docs[0].id];
      if (!invoices.length) throw new Error('Escolha pelo menos uma fatura.');
      const r = await api.post('purchases.pay', { invoices, third_party: party, ...f });
      toast(`Pagamento ${r.doc_no} registado: ${money(r.total_cents)}.`, 'good');
      done();
    } }],
  });
  if (many) {
    const upd = () => { m.el.querySelector('#pay-total').textContent = money([...m.el.querySelectorAll('[data-inv]:checked')].reduce((s, c) => s + Number(c.dataset.open), 0)); };
    m.el.querySelectorAll('[data-inv]').forEach((c) => { c.onchange = upd; });
    upd();
  }
}

// =========================================================================== dívidas a fornecedores

async function renderCreditors({ view, query }) {
  setCrumbs('Dívidas a fornecedores');
  const f = { q: query.q || '', overdue: query.overdue || '', as_of: query.as_of || today() };
  const r = await api.get('purchases.creditors', f);
  const t = r.totals;
  view.innerHTML = `
    <div class="page-head"><div><h1>Dívidas a fornecedores</h1><p class="muted">Faturas de fornecedores registadas que ainda não foram pagas, por fornecedor e por antiguidade (dias depois do vencimento).</p></div>
      <div class="toolbar"><a class="btn" href="#/${cpath('pagamentos')}">Mapa de pagamentos</a><button id="xlsx">Excel</button><button data-print>Imprimir / PDF</button></div></div>
    <div class="kpis">
      <div class="kpi"><div class="label">Total por pagar</div><div class="value">${money(t.open)}</div><div class="small muted">${r.rows.length} fornecedor(es)</div></div>
      <div class="kpi"><div class="label">Vencido</div><div class="value ${t.overdue ? 'neg' : ''}">${money(t.overdue)}</div><div class="small muted">já passou o prazo de pagamento</div></div>
      <div class="kpi"><div class="label">Mais de 90 dias</div><div class="value ${t.mais90 ? 'neg' : ''}">${money(t.mais90)}</div><div class="small muted">em atraso há muito tempo</div></div>
    </div>
    <div class="card no-print"><form class="form" id="filters" role="search" aria-label="Filtros">
      <label>Pesquisar fornecedor<input name="q" value="${esc(f.q)}" placeholder="Nome ou NIF"></label>
      <label>Mostrar<select name="overdue"><option value="">Todos os fornecedores com dívida</option><option value="1" ${f.overdue ? 'selected' : ''}>Só com valores vencidos</option></select></label>
      <label>Data de referência<input type="date" name="as_of" value="${esc(f.as_of)}"></label>
    </form></div>
    <div class="print-head"><b>${esc(state.info.name)}</b> · Dívidas a fornecedores em ${fmtDate(r.as_of)}</div>
    <div class="card"><div class="table-wrap"><table id="creditors"><caption class="sr-only">Dívidas por fornecedor</caption>
      <thead><tr><th>Fornecedor</th><th class="num">Faturas</th>${BUCKETS.map(([, l]) => `<th class="num">${l}</th>`).join('')}<th class="num">Total</th><th>Próximo vencimento</th><th class="no-print"></th></tr></thead>
      <tbody>${r.rows.map((x) => `<tr>
        <td><a href="#/${cpath('terceiro/' + x.third_party)}"><b>${esc(x.supplier)}</b></a>${x.nif ? `<div class="small muted mono">${esc(x.nif)}</div>` : ''}</td>
        <td class="num">${x.docs}</td>
        ${BUCKETS.map(([k]) => `<td class="num ${k !== 'a_vencer' && x[k] ? 'neg' : ''}">${x[k] ? money(x[k]) : ''}</td>`).join('')}
        <td class="num"><b>${money(x.open)}</b></td><td>${fmtDate(x.next_due)}</td>
        <td class="no-print"><button data-pay="${x.third_party}">Pagar</button></td></tr>`).join('')
        || '<tr><td colspan="10" class="empty">Não há dívidas a fornecedores registadas.</td></tr>'}</tbody>
      ${r.rows.length ? `<tfoot><tr class="total"><td>Total</td><td></td>${BUCKETS.map(([k]) => `<td class="num">${money(t[k])}</td>`).join('')}<td class="num">${money(t.open)}</td><td></td><td class="no-print"></td></tr></tfoot>` : ''}
    </table></div>
    <p class="small muted">Só inclui faturas registadas em <a href="#/${cpath('compras')}">Compras</a>. O saldo contabilístico de cada fornecedor (com histórico importado) está no extrato da ficha do fornecedor.</p></div>`;
  const form = view.querySelector('#filters');
  form.onsubmit = (e) => e.preventDefault();
  const apply = () => { const q = new URLSearchParams(Object.entries(formData(form)).filter(([k, v]) => v && !(k === 'as_of' && v === today()))); go(cpath('dividas-fornecedores' + (q.toString() ? '?' + q : ''))); };
  form.querySelectorAll('select, input[type=date]').forEach((el) => { el.onchange = apply; });
  form.querySelector('[name=q]').oninput = debounce(apply, 500);
  if (f.q) { const q = form.querySelector('[name=q]'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  view.querySelectorAll('[data-pay]').forEach((b) => {
    b.onclick = async () => {
      const s = await api.get('purchases.supplier', { id: b.dataset.pay, year: state.year });
      payDialog(Number(b.dataset.pay), s.open_docs, () => renderCreditors({ view, query }));
    };
  });
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#creditors'), `dividas_fornecedores_${r.as_of}.xlsx`, { sheet: 'Dívidas', title: `Dívidas a fornecedores em ${fmtDate(r.as_of)} — ${state.info.name}` });
}

// =========================================================================== mapa de pagamentos

async function renderSchedule({ view, query }) {
  setCrumbs('Mapa de pagamentos');
  const asOf = query.as_of || today();
  const r = await api.get('purchases.schedule', { as_of: asOf });
  const total = Object.values(r.totals).reduce((a, b) => a + b, 0);
  view.innerHTML = `
    <div class="page-head"><div><h1>Mapa de pagamentos</h1><p class="muted">O que tem de pagar aos fornecedores, por data de vencimento. Use-o para planear a tesouraria.</p></div>
      <div class="toolbar"><a class="btn" href="#/${cpath('dividas-fornecedores')}">Dívidas por fornecedor</a><button id="xlsx">Excel</button><button data-print>Imprimir / PDF</button></div></div>
    <div class="kpis">${GROUPS.map(([k, l, cls]) => `<div class="kpi"><div class="label">${l}</div><div class="value ${cls === 'bad' && r.totals[k] ? 'neg' : ''}">${money(r.totals[k])}</div><div class="small muted">${r.groups[k].length} fatura(s)</div></div>`).join('')}</div>
    <div class="card no-print"><form class="form" id="filters"><label>Data de referência<input type="date" name="as_of" value="${esc(asOf)}"></label></form></div>
    <div class="print-head"><b>${esc(state.info.name)}</b> · Mapa de pagamentos em ${fmtDate(asOf)}</div>
    <div class="card"><div class="table-wrap"><table id="sched"><caption class="sr-only">Faturas por data de vencimento</caption>
      <thead><tr><th>Vencimento</th><th>Fornecedor</th><th>Fatura</th><th class="num">Em dívida</th><th>Situação</th><th class="no-print"></th></tr></thead>
      ${GROUPS.map(([k, l, cls]) => r.groups[k].length ? `<tbody><tr class="group-row"><th colspan="6" scope="rowgroup">${l} · ${money(r.totals[k])}</th></tr>
        ${r.groups[k].map((d) => `<tr><td class="${cls === 'bad' ? 'neg' : ''}">${fmtDate(d.due_date || d.date)}</td>
          <td><a href="#/${cpath('terceiro/' + d.third_party)}">${esc(d.supplier)}</a></td>
          <td><a href="#/${cpath('compras/' + d.id)}">${esc(d.supplier_doc || d.doc_no)}</a></td>
          <td class="num"><b>${money(d.open_cents)}</b></td>
          <td>${d.days_overdue > 0 ? `<span class="badge ${d.days_overdue > 90 ? 'bad' : 'warn'}">${d.days_overdue} dias de atraso</span>` : d.days_to_due === 0 ? '<span class="badge warn">Vence hoje</span>' : `<span class="badge">faltam ${d.days_to_due} dias</span>`}</td>
          <td class="no-print"><button data-pay="${d.id}">Pagar</button></td></tr>`).join('')}</tbody>` : '').join('')}
      ${total ? `<tfoot><tr class="total"><td colspan="3">Total por pagar</td><td class="num">${money(total)}</td><td colspan="2"></td></tr></tfoot>` : '<tbody><tr><td colspan="6" class="empty">Não há faturas de fornecedores por pagar.</td></tr></tbody>'}
    </table></div></div>`;
  view.querySelector('[name=as_of]').onchange = (e) => go(cpath('pagamentos' + (e.target.value && e.target.value !== today() ? '?as_of=' + e.target.value : '')));
  const all = Object.values(r.groups).flat();
  view.querySelectorAll('[data-pay]').forEach((b) => {
    b.onclick = () => { const d = all.find((x) => String(x.id) === b.dataset.pay); payDialog(d.third_party, [d], () => renderSchedule({ view, query })); };
  });
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#sched'), `mapa_pagamentos_${asOf}.xlsx`, { sheet: 'Pagamentos', title: `Mapa de pagamentos em ${fmtDate(asOf)} — ${state.info.name}` });
}
