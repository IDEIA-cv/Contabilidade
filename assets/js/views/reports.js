// Relatórios: balancete, extrato de conta (razão), diário, balanço e demonstração dos resultados.
import { api } from '../api.js';
import { state, go, cpath, setCrumbs } from '../main.js';
import { esc, money, fmtDate, monthOptions, tableToCsv, signClass, MONTHS_FULL, pager, bindPager } from '../util.js';
import { tableToXlsx } from '../xlsx.js';

const TITLES = { balancete: 'Balancete', razao: 'Extrato de conta', diario: 'Diário', balanco: 'Balanço', dr: 'Demonstração dos resultados por naturezas' };

export async function render({ view, params, query }) {
  const type = params[0] || 'balancete';
  setCrumbs('Relatórios', TITLES[type]);
  const q = { from: 1, to: 12, level: '', account: '', journal: '', ...query };
  const usesFrom = ['balancete', 'razao', 'diario', 'dr'].includes(type);
  view.innerHTML = `
    <div class="page-head"><div><h1>${TITLES[type]}</h1><p class="muted">Exercício ${state.year} · só lançamentos validados.</p></div>
      <div class="toolbar no-print" id="tb">
        ${type === 'razao' ? `<label>Conta<input name="account" class="mono" value="${esc(q.account)}" size="10" list="dl-acc"></label><datalist id="dl-acc"></datalist>` : ''}
        ${type === 'diario' ? `<label>Diário<select name="journal"><option value="">Todos</option>${state.info.journals.map((j) => `<option value="${esc(j.code)}" ${q.journal === j.code ? 'selected' : ''}>${esc(j.code)} · ${esc(j.name)}</option>`).join('')}</select></label>` : ''}
        ${usesFrom ? `<label>De<select name="from">${monthOptions(q.from)}</select></label>` : ''}
        <label>${usesFrom ? 'Até' : 'Data'}<select name="to">${monthOptions(q.to)}</select></label>
        ${type === 'balancete' ? `<label>Nível<select name="level"><option value="">Todos</option>${[1, 2, 3, 4].map((l) => `<option ${String(q.level) === String(l) ? 'selected' : ''}>${l}</option>`).join('')}</select></label>` : ''}
        <button id="xlsx">Exportar Excel</button><button id="csv">CSV</button><button data-print>Imprimir / PDF</button>
      </div></div>
    <div class="print-head"><b>${esc(state.info.name)}</b> · NIF ${esc(state.info.nif || '')} · ${TITLES[type]} · ${state.year}</div>
    <div class="card" id="out"><div class="loading">A calcular…</div></div>`;
  const out = view.querySelector('#out');
  view.querySelectorAll('#tb [name]').forEach((i) => {
    i.onchange = () => {
      const p = new URLSearchParams();
      view.querySelectorAll('#tb [name]').forEach((x) => { if (x.value) p.set(x.name, x.value); });
      go(cpath(`relatorios/${type}?${p}`));
    };
  });
  // relatórios paginados exportam todas as linhas: pedem o relatório completo e geram a tabela fora do ecrã
  let fullTable = null;
  const exportTable = async () => {
    if (fullTable) { const div = document.createElement('div'); div.innerHTML = await fullTable(); return div.querySelector('table'); }
    return out.querySelector('table');
  };
  view.querySelector('#csv').onclick = async () => { const t = await exportTable(); if (t) tableToCsv(t, `${type}_${state.year}.csv`); };
  view.querySelector('#xlsx').onclick = async () => {
    const t = await exportTable();
    if (t) tableToXlsx(t, `${type}_${state.year}.xlsx`, { sheet: TITLES[type], title: `${state.info.name} · NIF ${state.info.nif || ''} · ${TITLES[type]} · ${state.year}` });
  };
  const offset = Math.max(0, Number(q.offset) || 0);
  const goOffset = (off) => { const p = new URLSearchParams(query); p.set('offset', off); go(cpath(`relatorios/${type}?${p}`)); };
  const y = state.year;

  if (type === 'balancete') {
    const r = await api.get('reports.trial', { year: y, from: q.from, to: q.to, level: q.level });
    out.innerHTML = r.rows.length ? `<div class="table-wrap"><table>
      <thead><tr><th>Conta</th><th>Nome</th><th class="num">Saldo anterior</th><th class="num">Débito</th><th class="num">Crédito</th><th class="num">Saldo devedor</th><th class="num">Saldo credor</th></tr></thead>
      <tbody>${r.rows.map((x) => `<tr class="lvl-${Math.min(x.level, 3)} clickable" data-acc="${esc(x.code)}"><td class="mono indent-${Math.min(x.level - 1, 4)}">${esc(x.code)}</td><td>${esc(x.name)}</td>
        <td class="num ${signClass(x.opening)}">${money(x.opening, { blankZero: true })}</td><td class="num">${money(x.debit, { blankZero: true })}</td><td class="num">${money(x.credit, { blankZero: true })}</td>
        <td class="num">${money(x.balance_debit, { blankZero: true })}</td><td class="num">${money(x.balance_credit, { blankZero: true })}</td></tr>`).join('')}
      <tr class="total"><td colspan="2">Total (classes)</td><td class="num">${money(r.totals.opening_debit - r.totals.opening_credit)}</td><td class="num">${money(r.totals.debit)}</td><td class="num">${money(r.totals.credit)}</td>
        <td class="num">${money(r.totals.balance_debit)}</td><td class="num">${money(r.totals.balance_credit)}</td></tr></tbody></table></div>
      ${r.totals.debit !== r.totals.credit ? '<div class="alert bad">Atenção: débitos e créditos do período não coincidem.</div>' : ''}`
      : '<div class="empty">Sem movimentos no período.</div>';
    out.querySelectorAll('tr[data-acc]').forEach((tr) => { tr.onclick = () => go(cpath(`relatorios/razao?account=${tr.dataset.acc}&from=${q.from}&to=${q.to}`)); });
  }

  if (type === 'razao') {
    const accs = await api.get('accounts.list');
    view.querySelector('#dl-acc').innerHTML = accs.map((a) => `<option value="${esc(a.code)}">${esc(a.name)}</option>`).join('');
    if (!q.account) { out.innerHTML = '<div class="empty">Indique uma conta (ou o início do código, ex.: 62 para todos os FSE).</div>'; return; }
    const LIMIT = 500;
    const table = (r) => `<div class="table-wrap"><table>
      <thead><tr><th>Data</th><th>Diário / n.º</th><th>Documento</th><th>Descrição</th><th>Conta</th><th class="num">Débito</th><th class="num">Crédito</th><th class="num">Saldo</th></tr></thead>
      <tbody><tr class="section"><td colspan="7">${r.offset ? 'Saldo transportado' : 'Saldo anterior'}</td><td class="num ${signClass(r.page_start)}">${money(r.page_start)}</td></tr>
      ${r.rows.map((x) => `<tr class="clickable" data-id="${x.entry_id}"><td>${fmtDate(x.date)}</td><td class="mono"><a href="#/${cpath('lancamento/' + x.entry_id)}">${esc(x.journal)} / ${x.number}</a></td><td>${esc(x.document || '')}</td>
        <td>${esc(x.description || x.entry_description)}</td><td class="mono">${esc(x.account)}</td><td class="num">${money(x.debit_cents, { blankZero: true })}</td><td class="num">${money(x.credit_cents, { blankZero: true })}</td>
        <td class="num ${signClass(x.balance)}">${money(x.balance)}</td></tr>`).join('')}
      <tr class="total"><td colspan="5">Totais do período</td><td class="num">${money(r.debit)}</td><td class="num">${money(r.credit)}</td><td class="num ${signClass(r.closing)}">${money(r.closing)}</td></tr></tbody></table></div>`;
    const r = await api.get('reports.ledger', { year: y, account: q.account, from: q.from, to: q.to, limit: LIMIT, offset });
    if (r.total_rows > LIMIT) fullTable = async () => table(await api.get('reports.ledger', { year: y, account: q.account, from: q.from, to: q.to }));
    const nav = pager(r.total_rows, r.offset, LIMIT, 'movimentos');
    out.innerHTML = `<h2>${esc(r.account)} ${esc(r.name || '(todas as subcontas)')}</h2>${nav}${table(r)}${nav}
      <p class="muted small">Saldo positivo = devedor; negativo = credor.${r.total_rows > LIMIT ? ' A exportação para Excel e CSV inclui todos os movimentos.' : ''}</p>`;
    bindPager(out, goOffset);
    out.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = () => go(cpath('lancamento/' + tr.dataset.id)); });
  }

  if (type === 'diario') {
    const LIMIT = 200;
    const table = (r) => { let last = null; return r.rows.length ? `<div class="table-wrap"><table>
      <thead><tr><th>Data</th><th>Diário / n.º</th><th>Descrição</th><th>Conta</th><th>Nome</th><th class="num">Débito</th><th class="num">Crédito</th></tr></thead>
      <tbody>${r.rows.map((x) => { const first = x.entry_id !== last; last = x.entry_id; return `<tr ${first ? 'style="border-top:2px solid var(--border)"' : ''}>
        <td>${first ? fmtDate(x.date) : ''}</td><td class="mono">${first ? `<a href="#/${cpath('lancamento/' + x.entry_id)}">${esc(x.journal)} / ${x.number}</a>` : ''}</td>
        <td>${first ? esc(x.entry_description) : esc(x.description || '')}</td><td class="mono">${esc(x.account)}</td><td>${esc(x.account_name)}</td>
        <td class="num">${money(x.debit_cents, { blankZero: true })}</td><td class="num">${money(x.credit_cents, { blankZero: true })}</td></tr>`; }).join('')}
      <tr class="total"><td colspan="5">Totais do período</td><td class="num">${money(r.debit)}</td><td class="num">${money(r.credit)}</td></tr></tbody></table></div>`
      : '<div class="empty">Sem lançamentos no período.</div>'; };
    const r = await api.get('reports.journal', { year: y, from: q.from, to: q.to, journal: q.journal, limit: LIMIT, offset });
    if (r.total_entries > LIMIT) fullTable = async () => table(await api.get('reports.journal', { year: y, from: q.from, to: q.to, journal: q.journal }));
    const nav = pager(r.total_entries, r.offset, LIMIT, 'lançamentos');
    out.innerHTML = nav + table(r) + nav;
    bindPager(out, goOffset);
  }

  if (type === 'balanco') {
    const r = await api.get('reports.balance', { year: y, to: q.to });
    const sec = (s) => `<tr class="section"><td colspan="2">${esc(s.title)}</td></tr>${s.lines.filter((l) => l.value !== 0).map((l) => `<tr><td class="indent-1">${esc(l.label)}</td><td class="num ${signClass(l.value)}">${money(l.value)}</td></tr>`).join('')}
      <tr class="total"><td>Total ${esc(s.title.toLowerCase())}</td><td class="num">${money(s.total)}</td></tr>`;
    const s = Object.fromEntries(r.sections.map((x) => [x.key, x]));
    out.innerHTML = `<p class="muted">Balanço em ${fmtDate(r.date)} (antes do apuramento de resultados).</p>
      <div class="grid cols-2"><div><table><thead><tr><th>Ativo</th><th class="num">ECV</th></tr></thead><tbody>${sec(s.anc)}${sec(s.ac)}
        <tr class="total"><td>TOTAL DO ATIVO</td><td class="num">${money(r.total_ativo)}</td></tr></tbody></table></div>
      <div><table><thead><tr><th>Capital próprio e passivo</th><th class="num">ECV</th></tr></thead><tbody>${sec(s.cp)}${sec(s.pnc)}${sec(s.pc)}
        <tr class="total"><td>TOTAL DO CAPITAL PRÓPRIO E PASSIVO</td><td class="num">${money(r.total_capital_proprio + r.total_passivo)}</td></tr></tbody></table></div></div>
      ${r.difference !== 0 ? `<div class="alert bad">Diferença de ${money(r.difference)} entre ativo e capital próprio + passivo. Verifique contas fora do mapeamento.</div>` : '<div class="alert good">Ativo = Capital próprio + Passivo ✓</div>'}
      <p class="muted small">Modelo simplificado com base nas classes SNCRF. Os saldos da classe 2 são separados conta a conta: devedores no ativo, credores no passivo.</p>`;
  }

  if (type === 'dr') {
    const r = await api.get('reports.income', { year: y, from: q.from, to: q.to });
    const p = r.previous;
    const row = (label, v, pv, cls = '') => `<tr class="${cls}"><td>${label}</td><td class="num ${signClass(v)}">${money(v)}</td>${p ? `<td class="num muted ${signClass(pv)}">${money(pv)}</td>` : ''}</tr>`;
    out.innerHTML = `<p class="muted">${MONTHS_FULL[q.from - 1]} a ${MONTHS_FULL[q.to - 1]} de ${y}. Rendimentos positivos, gastos negativos.</p>
      <div class="table-wrap"><table><thead><tr><th>Rubrica</th><th class="num">${y}</th>${p ? `<th class="num">${y - 1}</th>` : ''}</tr></thead><tbody>
      ${r.lines.map((l, i) => (l.value || (p && p.lines[i].value)) ? row(esc(l.label), l.value, p?.lines[i].value) : '').join('')}
      ${row('Resultado antes de depreciações, gastos de financiamento e impostos (EBITDA)', r.ebitda, p?.ebitda, 'total')}
      ${row('Gastos de depreciação e de amortização', r.depreciacoes, p?.depreciacoes)}
      ${row('Resultado operacional (antes de gastos de financiamento e impostos)', r.ebit, p?.ebit, 'total')}
      ${row('Juros e rendimentos similares obtidos', r.juros_obtidos, p?.juros_obtidos)}
      ${row('Juros e gastos similares suportados', r.juros_suportados, p?.juros_suportados)}
      ${row('Resultado antes de impostos', r.resultado_antes_impostos, p?.resultado_antes_impostos, 'total')}
      ${row('Imposto sobre o rendimento do período', r.imposto, p?.imposto)}
      ${row('Resultado líquido do período', r.resultado_liquido, p?.resultado_liquido, 'total')}
      </tbody></table></div>`;
  }
}
