// Imobilizado: lista e mapa de depreciações do exercício, ficha de cada bem com o plano, lançar/anular as depreciações
// do exercício e abate ou venda. Cálculos no servidor (src/Assets.php).
import { api } from '../api.js';
import { setCrumbs, go, cpath, state } from '../main.js';
import { esc, money, fmtDate, toast, modal, confirmDialog, formData, today } from '../util.js';
import { tableToXlsx } from '../xlsx.js';

let META = null;
const pct = (bp) => `${(Number(bp) / 100).toLocaleString('pt-PT', { maximumFractionDigits: 2 })}%`;
const centsToInput = (c) => (Number(c) / 100).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export async function render(ctx) {
  META ??= await api.get('assets.meta');
  const [p] = ctx.params;
  if (p === 'novo') return renderForm(ctx, null);
  if (p) return renderAsset(ctx, Number(p));
  return renderList(ctx);
}

async function renderList({ view, query }) {
  setCrumbs('Imobilizado');
  const f = { status: query.status ?? 'ativo', category: query.category || '', q: query.q || '' };
  const res = await api.get('assets.list', { year: state.year, ...f });
  const y = res.year;
  const rows = res.rows.filter((r) => r.in_year || f.status !== 'ativo');
  view.innerHTML = `
    <div class="page-head"><div><h1>Imobilizado</h1><p class="muted">Bens da empresa (equipamentos, viaturas, edifícios, programas) e as suas depreciações e amortizações, pelo método das quotas constantes.</p></div>
      <div class="toolbar"><button class="primary" id="new">Novo bem</button></div></div>
    <div class="kpis">
      <div class="kpi"><div class="label">Custo de aquisição</div><div class="value">${money(res.totals.cost)}</div><div class="small muted">bens em ${y}</div></div>
      <div class="kpi"><div class="label">Depreciações de ${y}</div><div class="value">${money(res.totals.year)}</div><div class="small muted">${res.pending ? `${res.pending} bem(ns) por lançar` : 'todas lançadas'}</div></div>
      <div class="kpi"><div class="label">Acumuladas no fim de ${y}</div><div class="value">${money(res.totals.accum_end)}</div><div class="small muted">depreciações e amortizações</div></div>
      <div class="kpi"><div class="label">Valor líquido</div><div class="value">${money(res.totals.net)}</div><div class="small muted">custo − acumuladas</div></div>
    </div>
    <div class="card" id="post-card">
      <h2>Depreciações do exercício ${y}</h2>
      ${res.pending ? `<p>Há <b>${money(res.pending_cents)}</b> de depreciações por lançar em ${y} (${res.pending} bem(ns)). O lançamento é feito em 31/12/${y} no diário de operações diversas, com uma linha por bem.</p>
        <div class="toolbar"><button class="primary" id="post">Lançar depreciações de ${y}</button></div>`
        : res.posted ? `<p>As depreciações de ${y} estão lançadas${res.posted_entry ? ` (<a href="#/${cpath('lancamento/' + res.posted_entry)}">ver lançamento</a>)` : ''}.</p>
        <div class="toolbar"><button id="unpost">Anular as depreciações de ${y}</button><span class="small muted">Estorna o lançamento. Use se precisar de corrigir um bem.</span></div>`
        : `<p class="muted">Não há depreciações a lançar em ${y}.</p>`}
      <p class="small muted">Mude o exercício no topo da página. As taxas sugeridas por categoria devem ser confirmadas pelo contabilista com a tabela legal em vigor.</p>
    </div>
    <div class="card">
      <form class="form" id="filters" role="search" aria-label="Filtros">
        <label>Estado<select name="status"><option value="ativo" ${f.status === 'ativo' ? 'selected' : ''}>Em uso</option><option value="abatido" ${f.status === 'abatido' ? 'selected' : ''}>Abatidos ou vendidos</option><option value="" ${f.status === '' ? 'selected' : ''}>Todos</option></select></label>
        <label>Categoria<select name="category"><option value="">Todas</option>${Object.entries(META.categories).map(([k, c]) => `<option value="${k}" ${f.category === k ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
        <label>Pesquisar<input name="q" value="${esc(f.q)}" placeholder="Código, descrição, fatura, local"></label>
      </form>
    </div>
    <div class="card"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">Mapa de depreciações e amortizações ${y}</h2><span class="toolbar"><button id="xlsx">Excel</button><button data-print>Imprimir</button></span></div>
      <div class="table-wrap" tabindex="0" role="region" aria-label="Mapa de depreciações"><table id="map"><caption class="sr-only">Mapa de depreciações e amortizações</caption>
        <thead><tr><th>Código</th><th>Bem</th><th>Conta</th><th>Início</th><th class="num">Taxa</th><th class="num">Custo</th><th class="num">Acumuladas no início</th><th class="num">Do exercício</th><th class="num">Acumuladas no fim</th><th class="num">Valor líquido</th></tr></thead>
        <tbody>${rows.map((r) => `<tr class="clickable" data-id="${r.id}"><td class="mono"><a href="#/${cpath('imobilizado/' + r.id)}">${esc(r.code)}</a></td>
          <td>${esc(r.description)}<div class="small muted">${esc(r.category_name)}${r.status === 'abatido' ? ` · <span class="badge">${esc(r.disposal_reason || 'Abatido')} ${fmtDate(r.disposal_date)}</span>` : ''}</div></td>
          <td class="mono">${esc(r.account)}</td><td>${fmtDate(r.start_date)}</td><td class="num">${pct(r.rate_bp)}</td><td class="num">${money(r.cost)}</td><td class="num">${money(r.accum_start)}</td>
          <td class="num">${money(r.year)}${r.year && !r.posted ? '<div class="small muted">por lançar</div>' : ''}</td><td class="num">${money(r.accum_end)}</td><td class="num">${money(r.net)}</td></tr>`).join('')
          || `<tr><td colspan="10" class="empty">${res.rows.length ? 'Nenhum bem com estes filtros neste exercício.' : 'Ainda não há bens registados. Comece por <b>Novo bem</b>.'}</td></tr>`}</tbody>
        ${rows.length ? `<tfoot><tr class="total"><td colspan="5">Total</td><td class="num">${money(res.totals.cost)}</td><td class="num">${money(res.totals.accum_start)}</td><td class="num">${money(res.totals.year)}</td><td class="num">${money(res.totals.accum_end)}</td><td class="num">${money(res.totals.net)}</td></tr></tfoot>` : ''}
      </table></div></div>`;

  const setQuery = (patch) => {
    const q = { ...f, ...patch };
    const qs = new URLSearchParams(Object.entries(q).filter(([k, v]) => v !== '' || k === 'status'));
    go(cpath('imobilizado?' + qs));
  };
  const form = view.querySelector('#filters');
  form.onsubmit = (e) => { e.preventDefault(); setQuery(formData(form)); };
  form.querySelectorAll('select').forEach((el) => { el.onchange = () => setQuery(formData(form)); });
  form.querySelector('[name=q]').onchange = () => setQuery(formData(form));
  view.querySelector('#new').onclick = () => go(cpath('imobilizado/novo'));
  view.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = (e) => { if (e.target.tagName !== 'A') go(cpath('imobilizado/' + tr.dataset.id)); }; });
  view.querySelector('#xlsx').onclick = () => tableToXlsx(view.querySelector('#map'), `mapa_depreciacoes_${y}.xlsx`, { sheet: 'Depreciações', title: `Mapa de depreciações e amortizações ${y} — ${state.info.name}` });
  view.querySelector('#post')?.addEventListener('click', async () => {
    const p = await api.get('assets.preview', { year: y });
    if (p.missing_years.length) return toast(`Lance primeiro as depreciações de ${p.missing_years.join(', ')} (mude o exercício no topo).`, 'bad');
    modal({
      title: `Lançar depreciações de ${y}`,
      wide: true,
      body: `<p>É criado um lançamento validado em 31/12/${y}:</p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Linhas do lançamento"><table><thead><tr><th>Bem</th><th class="num">Meses</th><th>Débito</th><th>Crédito</th><th class="num">Valor</th></tr></thead><tbody>
        ${p.lines.map((l) => `<tr><td><span class="mono">${esc(l.code)}</span> ${esc(l.description)}</td><td class="num">${l.months}</td><td class="mono">${l.expense}</td><td class="mono">${l.accum}</td><td class="num">${money(l.amount)}</td></tr>`).join('')}</tbody>
        <tfoot><tr class="total"><td colspan="4">Total</td><td class="num">${money(p.total)}</td></tr></tfoot></table></div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Lançar', primary: true, onClick: async () => {
        const r = await api.post('assets.post', {}, { year: y });
        toast(`Depreciações lançadas: ${money(r.total)} (${r.count} bem/bens).`, 'good');
        renderList({ view, query });
      } }],
    });
  });
  view.querySelector('#unpost')?.addEventListener('click', async () => {
    if (!await confirmDialog(`Anular as depreciações de ${y}?`, 'O lançamento é estornado (fica o registo do estorno) e pode voltar a lançar depois de corrigir os bens.', 'Anular')) return;
    try { await api.post('assets.unpost', {}, { year: y }); toast('Depreciações anuladas.'); renderList({ view, query }); } catch (err) { toast(err.message, 'bad'); }
  });
}

async function renderAsset(ctx, id) {
  const { view } = ctx;
  const a = await api.get('assets.get', { id });
  setCrumbs('Imobilizado', a.code);
  const posted = a.plan.some((r) => r.posted);
  view.innerHTML = `
    <div class="page-head"><div><h1>${esc(a.code)} · ${esc(a.description)}</h1>
      <p>${a.status === 'abatido' ? `<span class="badge">${esc(a.disposal_reason || 'Abatido')} em ${fmtDate(a.disposal_date)}</span>` : '<span class="badge good">Em uso</span>'} <span class="muted small">${esc(a.category_name)}</span></p></div>
      <div class="toolbar no-print"><a class="btn" href="#/${cpath('imobilizado')}">Voltar à lista</a>
        ${a.status === 'ativo' ? '<button id="edit">Alterar</button><button id="dispose">Abater ou vender</button>' : ''}
        ${a.status === 'ativo' && !posted ? '<button class="danger" id="del">Apagar</button>' : ''}</div></div>
    <div class="grid cols-2">
      <div class="card"><h2>Ficha do bem</h2><dl class="dl">
        <dt>Conta</dt><dd><span class="mono">${esc(a.account)}</span> ${esc(a.account_name || '')}</dd>
        <dt>Aquisição</dt><dd>${fmtDate(a.acquisition_date)}</dd>
        <dt>Entrada em funcionamento</dt><dd>${fmtDate(a.start_date)}</dd>
        <dt>Custo de aquisição</dt><dd>${money(a.cost_cents)}</dd>
        ${Number(a.residual_cents) ? `<dt>Valor residual</dt><dd>${money(a.residual_cents)}</dd>` : ''}
        <dt>Taxa anual</dt><dd>${pct(a.rate_bp)} (quotas constantes)</dd>
        ${Number(a.prior_cents) ? `<dt>Acumuladas até ${esc(a.prior_year)}</dt><dd>${money(a.prior_cents)}</dd>` : ''}
        ${a.supplier || a.supplier_doc ? `<dt>Fornecedor</dt><dd>${esc(a.supplier || '')} ${esc(a.supplier_doc || '')}</dd>` : ''}
        ${a.location ? `<dt>Localização</dt><dd>${esc(a.location)}</dd>` : ''}
        ${a.notes ? `<dt>Notas</dt><dd style="white-space:pre-wrap">${esc(a.notes)}</dd>` : ''}
        ${a.disposal_entry ? `<dt>Abate</dt><dd>${money(a.disposal_value_cents)} · <a href="#/${cpath('lancamento/' + a.disposal_entry.id)}">lançamento ${esc(a.disposal_entry.journal)}/${esc(a.disposal_entry.number)}</a></dd>` : ''}
      </dl></div>
      <div class="card"><h2>Plano de depreciações</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Plano de depreciações"><table><thead><tr><th>Exercício</th><th class="num">Meses</th><th class="num">Depreciação</th><th class="num">Acumuladas</th><th class="num">Valor líquido</th><th></th></tr></thead><tbody>
        ${a.plan.map((r) => `<tr><td>${r.year}</td><td class="num">${r.months}</td><td class="num">${money(r.amount)}</td><td class="num">${money(r.accum)}</td><td class="num">${money(r.net)}</td>
          <td>${r.posted ? (r.entry_id ? `<a class="badge good" href="#/${cpath('lancamento/' + r.entry_id)}">Lançada</a>` : '<span class="badge good">Lançada</span>') : '<span class="badge">Prevista</span>'}</td></tr>`).join('')}</tbody></table></div>
        <p class="small muted">No primeiro ano contam os meses desde a entrada em funcionamento; no ano do abate, até ao mês do abate.</p></div>
    </div>`;
  view.querySelector('#edit')?.addEventListener('click', () => renderForm(ctx, a));
  view.querySelector('#del')?.addEventListener('click', async () => {
    if (!await confirmDialog('Apagar este bem?', 'Só é possível porque ainda não tem lançamentos.', 'Apagar')) return;
    await api.post('assets.delete', { id: a.id });
    toast('Bem apagado.');
    go(cpath('imobilizado'));
  });
  view.querySelector('#dispose')?.addEventListener('click', () => {
    const m = modal({
      title: `Abater ou vender ${a.code}`,
      body: `<p class="small muted">É lançada a depreciação do ano até ao mês do abate, saem o custo e as depreciações acumuladas e fica registado o ganho (788) ou a perda (688).</p>
        <div class="form">
          <label>Data<input type="date" name="date" value="${today()}"></label>
          <label>Motivo<select name="reason"><option>Venda</option><option>Abate</option><option>Abate por avaria</option><option>Roubo ou sinistro</option><option>Doação</option></select></label>
          <label>Valor de venda (0 se for abate)<input name="value" class="num" inputmode="decimal" value="0,00"></label>
          <label>Conta onde entra o valor<input name="account" placeholder="ex.: 121 banco ou 2781 devedores"></label></div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Confirmar', danger: true, onClick: async (el) => {
        const r = await api.post('assets.dispose', { id: a.id, ...formData(el) });
        toast(`${r.disposal_reason}: ${r.result_cents >= 0 ? 'ganho' : 'perda'} de ${money(Math.abs(r.result_cents))}.`, 'good');
        renderAsset(ctx, id);
      } }],
    });
    const reason = m.el.querySelector('[name=reason]');
    reason.onchange = () => { if (reason.value !== 'Venda') m.el.querySelector('[name=value]').value = '0,00'; };
  });
}

async function renderForm(ctx, a) {
  const { view } = ctx;
  setCrumbs('Imobilizado', a ? a.code : 'Novo bem');
  const cats = META.categories;
  const cat = a?.category || 'informatica';
  const parties = await api.get('thirdparties.list', { kind: 'F' }).catch(() => []);
  const partyList = Array.isArray(parties) ? parties : (parties.rows || []);
  view.innerHTML = `
    <div class="page-head"><div><h1>${a ? `Alterar ${esc(a.code)}` : 'Novo bem do imobilizado'}</h1><p class="muted">A categoria sugere a conta e a taxa anual. Confirme a taxa com o contabilista.</p></div></div>
    <div class="card"><div class="form" id="f">
      <label class="wide">Descrição<input name="description" value="${esc(a?.description || '')}" placeholder="ex.: Portátil HP para a contabilidade"></label>
      <label>Categoria<select name="category">${Object.entries(cats).map(([k, c]) => `<option value="${k}" ${cat === k ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
      <label>Conta<select name="account">${META.accounts.map((x) => `<option value="${x.code}" ${(a?.account || cats[cat].account) === x.code ? 'selected' : ''}>${esc(x.code)} ${esc(x.name)}</option>`).join('')}</select></label>
      <label>Taxa anual (%)<input name="rate" class="num" inputmode="decimal" value="${a ? (a.rate_bp / 100).toString().replace('.', ',') : (cats[cat].rate_bp / 100).toString().replace('.', ',')}"></label>
      <label>Data de aquisição<input type="date" name="acquisition_date" value="${esc(a?.acquisition_date || today())}"></label>
      <label>Entrada em funcionamento<input type="date" name="start_date" value="${esc(a?.start_date || '')}"><span class="small muted">vazio = data de aquisição</span></label>
      <label>Custo de aquisição<input name="cost" class="num" inputmode="decimal" value="${a ? centsToInput(a.cost_cents) : ''}" placeholder="0,00"></label>
      <label>Valor residual<input name="residual" class="num" inputmode="decimal" value="${a ? centsToInput(a.residual_cents) : '0,00'}"></label>
      <label>Fornecedor<select name="third_party"><option value="">—</option>${partyList.map((p) => `<option value="${p.id}" ${String(a?.third_party || '') === String(p.id) ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
      <label>Fatura do fornecedor<input name="supplier_doc" value="${esc(a?.supplier_doc || '')}"></label>
      <label>Localização<input name="location" value="${esc(a?.location || '')}" placeholder="ex.: Escritório da Praia"></label>
      <details class="wide" ${a && Number(a.prior_cents) ? 'open' : ''}><summary>Bem que já vinha de outro programa (depreciações anteriores)</summary>
        <div class="form" style="margin-top:8px">
          <label>Depreciações acumuladas<input name="prior" class="num" inputmode="decimal" value="${a ? centsToInput(a.prior_cents) : '0,00'}"></label>
          <label>Até ao exercício (inclusive)<input name="prior_year" inputmode="numeric" value="${esc(a?.prior_year || '')}" placeholder="ex.: 2024"></label></div></details>
      <label class="wide">Notas<textarea name="notes" rows="2">${esc(a?.notes || '')}</textarea></label>
    </div>
    <p class="small muted">Registar o bem não faz o lançamento da compra: a aquisição entra na contabilidade pela fatura do fornecedor (conta ${esc(cats[cat].account)}) ou por um lançamento.</p>
    <div class="toolbar sticky-actions"><button class="primary" id="save">Guardar</button><button id="cancel">Cancelar</button></div></div>`;
  const f = view.querySelector('#f');
  f.querySelector('[name=category]').onchange = (e) => {
    const c = cats[e.target.value];
    f.querySelector('[name=account]').value = c.account;
    f.querySelector('[name=rate]').value = (c.rate_bp / 100).toString().replace('.', ',');
  };
  view.querySelector('#cancel').onclick = () => (a ? renderAsset(ctx, a.id) : go(cpath('imobilizado')));
  view.querySelector('#save').onclick = async () => {
    try {
      const r = await api.post('assets.save', { ...formData(f), id: a?.id || '' });
      toast(a ? 'Bem alterado.' : `Bem ${r.code} registado.`, 'good');
      if (a) renderAsset(ctx, a.id); else go(cpath('imobilizado/' + r.id));
    } catch (err) { toast(err.message, 'bad'); }
  };
}
