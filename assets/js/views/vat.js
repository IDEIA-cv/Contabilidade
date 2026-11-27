// Apuramento do IVA por período e lançamento automático do apuramento.
import { api } from '../api.js';
import { state, go, cpath, setCrumbs, refreshInfo } from '../main.js';
import { esc, money, monthOptions, tableToCsv, confirmDialog, toast, MONTHS_FULL } from '../util.js';
import { tableToXlsx } from '../xlsx.js';

export async function render({ view, query }) {
  setCrumbs('Apuramento do IVA');
  const now = new Date();
  const defTo = String(state.year) === String(now.getFullYear()) ? Math.max(now.getMonth(), 1) : 12;
  const q = { from: Number(query.from || defTo), to: Number(query.to || defTo) };
  const r = await api.get('vat.settlement', { year: state.year, from: q.from, to: q.to });
  const label = r.situacao === 'a_pagar' ? '<span class="badge bad">IVA a pagar</span>' : r.situacao === 'a_recuperar' ? '<span class="badge good">IVA a recuperar</span>' : '<span class="badge">Sem imposto</span>';
  view.innerHTML = `
    <div class="page-head"><div><h1>Apuramento do IVA</h1><p class="muted">${MONTHS_FULL[q.from - 1]}${q.from !== q.to ? ' a ' + MONTHS_FULL[q.to - 1] : ''} de ${state.year}. Base: contas 2432 (dedutível), 2433 (liquidado) e 2434 (regularizações).</p></div>
      <div class="toolbar no-print" id="tb"><label>De<select name="from">${monthOptions(q.from)}</select></label><label>Até<select name="to">${monthOptions(q.to)}</select></label>
        ${r.posted.length ? '' : '<button id="post" class="primary" data-admin>Gerar lançamento de apuramento</button>'}<button data-print>Imprimir</button></div></div>
    ${r.posted.length ? `<div class="alert good">Este período já foi apurado: ${r.posted.map(([f, t, id]) => `<a href="#/${cpath('lancamento/' + id)}">${MONTHS_FULL[f - 1]}${f !== t ? ' a ' + MONTHS_FULL[t - 1] : ''}</a>`).join(', ')}. Para refazer, estorne esse lançamento.</div>` : ''}
    <div class="kpis">
      <div class="kpi"><div class="label">IVA liquidado (vendas)</div><div class="value">${money(r.liquidado)}</div></div>
      <div class="kpi"><div class="label">IVA dedutível (compras)</div><div class="value">${money(r.dedutivel)}</div></div>
      <div class="kpi"><div class="label">Regularizações a favor</div><div class="value">${money(r.regularizacoes)}</div></div>
      <div class="kpi"><div class="label">Resultado ${label}</div><div class="value">${money(Math.abs(r.resultado))}</div></div>
    </div>
    <div class="grid cols-2">
      <div class="card"><h2>Por código de IVA <span><button class="icon-btn small" data-xlsx="t1">Excel</button> <button class="icon-btn small" data-csv="t1">CSV</button></span></h2><div class="table-wrap"><table id="t1">
        <thead><tr><th>Código</th><th>Descrição</th><th class="num">Taxa</th><th class="num">Base</th><th class="num">IVA</th></tr></thead>
        <tbody>${r.by_code.map((c) => `<tr><td class="mono">${esc(c.vat_code)}</td><td>${esc(c.name)}</td><td class="num">${(c.rate_bp / 100).toFixed(0)}%</td><td class="num">${money(c.base)}</td><td class="num">${money(c.vat)}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem linhas com código de IVA.</td></tr>'}</tbody></table></div>
        <p class="muted small">A base vem do campo "Base IVA" das linhas. Preencha-o nos lançamentos para obter este mapa.</p></div>
      <div class="card"><h2>Por cliente / fornecedor <span><button class="icon-btn small" data-xlsx="t2">Excel</button> <button class="icon-btn small" data-csv="t2">CSV</button></span></h2><div class="table-wrap"><table id="t2">
        <thead><tr><th>NIF</th><th>Nome</th><th>Tipo</th><th class="num">Base</th><th class="num">IVA</th></tr></thead>
        <tbody>${r.by_party.map((p) => `<tr><td class="mono">${esc(p.nif || '')}</td><td>${esc(p.name)}</td><td>${p.kind === 'liquidado' ? 'Vendas' : 'Compras'}</td><td class="num">${money(p.base)}</td><td class="num">${money(p.vat)}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem dados por terceiro.</td></tr>'}</tbody></table></div></div>
    </div>
    <div class="alert info">Confirme os valores com a declaração no portal da DNRE antes de submeter. O lançamento de apuramento salda as contas de IVA contra 2436 (a pagar) ou 2437 (a recuperar).</div>`;
  view.querySelectorAll('#tb select').forEach((s) => { s.onchange = () => { const f = view.querySelector('[name=from]').value, t = view.querySelector('[name=to]').value; go(cpath(`iva?from=${f}&to=${t}`)); }; });
  view.querySelectorAll('[data-xlsx]').forEach((b) => { b.onclick = () => tableToXlsx(view.querySelector('#' + b.dataset.xlsx), `iva_${b.dataset.xlsx}_${state.year}.xlsx`, { sheet: 'IVA', title: `${state.info.name} · Apuramento do IVA ${state.year}` }); });
  view.querySelectorAll('[data-csv]').forEach((b) => { b.onclick = () => tableToCsv(view.querySelector('#' + b.dataset.csv), `iva_${b.dataset.csv}_${state.year}.csv`); });
  view.querySelector('#post')?.addEventListener('click', async () => {
    if (!(await confirmDialog('Gerar apuramento do IVA', `Será criado e validado um lançamento no último dia de ${MONTHS_FULL[q.to - 1]} com o resultado de ${money(r.resultado)}.`, 'Gerar'))) return;
    try {
      const e = await api.post('vat.post', { year: state.year, from: q.from, to: q.to });
      toast(`Apuramento lançado: ${e.journal} / ${e.number}`, 'good');
      await refreshInfo();
      go(cpath('lancamento/' + e.id));
    } catch (err) { toast(err.message, 'bad'); }
  });
}
