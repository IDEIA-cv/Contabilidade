// Módulo de estatísticas: rácios, gastos, clientes/fornecedores, orçamento, previsão, indicadores próprios e relatório de gestão.
import { api } from '../api.js';
import { state, go, cpath, setCrumbs } from '../main.js';
import { barChart, hbars } from '../charts.js';
import { esc, money, pct, MONTHS, MONTHS_FULL, signClass, toast, modal, tableToCsv, fmtDate, monthOptions } from '../util.js';
import { tableToXlsx } from '../xlsx.js';

const TABS = [['racios', 'Rácios'], ['vendas', 'Vendas e artigos'], ['compras', 'Compras'], ['prazos', 'Prazos de pagamento'], ['gastos', 'Gastos'], ['terceiros', 'Clientes e fornecedores'], ['orcamento', 'Orçamento'], ['previsao', 'Previsão'], ['indicadores', 'Indicadores próprios'], ['relatorio', 'Relatório de gestão']];

export async function render({ view, params, query = {} }) {
  const tab = params[0] || 'racios';
  setCrumbs('Estatísticas', TABS.find((t) => t[0] === tab)?.[1] || '');
  view.innerHTML = `<div class="page-head"><div><h1>Estatísticas ${state.year}</h1><p class="muted">Calculadas neste computador a partir dos lançamentos validados${['vendas', 'compras', 'prazos'].includes(tab) ? ' e dos documentos de venda e de compra' : ''}.</p></div></div>
    <div class="tabs no-print">${TABS.map(([k, l]) => `<a href="#/${cpath('estatisticas/' + k)}" class="${k === tab ? 'active' : ''}">${l}</a>`).join('')}</div>
    <div id="tab"><div class="loading">A calcular…</div></div>`;
  const el = view.querySelector('#tab');
  const fn = { racios, vendas, compras, prazos, gastos, terceiros, orcamento, previsao, indicadores, relatorio }[tab];
  if (fn) await fn(el, query);
}

function fmtRatio(r) {
  if (r.value === null || r.value === undefined) return '—';
  if (r.unit === '%') return pct(r.value);
  if (r.unit === 'dias') return Number(r.value) === 1 ? '1 dia' : `${r.value} dias`;
  return Number(r.value).toFixed(2).replace('.', ',');
}

async function racios(el) {
  const r = await api.get('stats.ratios', { year: state.year });
  const groups = [...new Set(r.ratios.map((x) => x.group))];
  el.innerHTML = `<p class="muted">Situação no fim de ${MONTHS_FULL[r.to - 1].toLowerCase()} de ${state.year}. Rácios de atividade anualizados pelos meses decorridos.</p>
    <div class="grid cols-2">${groups.map((g) => `<div class="card"><h2>${esc(g)}</h2><table><tbody>
      ${r.ratios.filter((x) => x.group === g).map((x) => `<tr><td><b>${esc(x.label)}</b><div class="muted small">${esc(x.formula)} · ${esc(x.reading)}</div></td><td class="num" style="font-size:18px">${fmtRatio(x)}</td></tr>`).join('')}
    </tbody></table></div>`).join('')}</div>
    <div class="card small"><h2>Valores de base</h2><div class="kpis">
      ${[['Ativo', r.base.ativo], ['Capital próprio', r.base.capital_proprio], ['Passivo', r.base.passivo], ['Ativo corrente', r.base.ativo_corrente], ['Passivo corrente', r.base.passivo_corrente], ['Volume de negócios', r.base.vn], ['Resultado líquido', r.base.rl]]
        .map(([l, v]) => `<div class="kpi"><div class="label">${l}</div><div class="value ${signClass(v)}">${money(v)}</div></div>`).join('')}</div>
      <p class="muted">O mapeamento das rubricas para as contas SNCRF deve ser validado por um contabilista certificado.</p></div>`;
}

async function gastos(el) {
  const c = await api.get('stats.costs', { year: state.year });
  el.innerHTML = `<div class="grid cols-2"><div class="card"><h2>Gastos por natureza <span class="muted small">total ${money(c.total)}</span></h2><div id="hb"></div></div>
    <div class="card"><h2>Detalhe <span><button class="icon-btn small" id="xlsx">Excel</button> <button class="icon-btn small" id="csv">CSV</button></span></h2><div class="table-wrap"><table id="t">
      <thead><tr><th>Conta</th><th>Nome</th><th class="num">Valor</th><th class="num">% do total</th></tr></thead><tbody>
      ${c.groups.map((g) => `<tr class="section"><td class="mono">${esc(g.code)}</td><td>${esc(g.name)}</td><td class="num">${money(g.value)}</td><td class="num">${pct(g.pct)}</td></tr>
        ${g.detail.map((d) => `<tr class="clickable" data-acc="${esc(d.code)}"><td class="mono indent-1">${esc(d.code)}</td><td>${esc(d.name)}</td><td class="num">${money(d.value)}</td><td class="num">${pct(c.total ? d.value / c.total * 100 : 0)}</td></tr>`).join('')}`).join('')
        || '<tr><td colspan="4" class="empty">Sem gastos registados.</td></tr>'}</tbody></table></div></div></div>`;
  hbars(el.querySelector('#hb'), c.groups.map((g) => ({ label: `${g.code} ${g.name}`, value: g.value, sub: pct(g.pct), code: g.code })), { onClick: (it) => go(cpath('relatorios/razao?account=' + it.code)) });
  el.querySelectorAll('tr[data-acc]').forEach((tr) => { tr.onclick = () => go(cpath('relatorios/razao?account=' + tr.dataset.acc)); });
  el.querySelector('#csv').onclick = () => tableToCsv(el.querySelector('#t'), `gastos_${state.year}.csv`);
  el.querySelector('#xlsx').onclick = () => tableToXlsx(el.querySelector('#t'), `gastos_${state.year}.xlsx`, { sheet: 'Gastos', title: `${state.info.name} · Estrutura de gastos ${state.year}` });
}

async function terceiros(el) {
  const [tc, tf, oc, of] = await Promise.all([
    api.get('stats.top', { year: state.year, kind: 'C', limit: 15 }), api.get('stats.top', { year: state.year, kind: 'F', limit: 15 }),
    api.get('stats.open', { year: state.year, kind: 'C' }), api.get('stats.open', { year: state.year, kind: 'F' }),
  ]);
  const aging = (o) => `<div class="kpis">${Object.entries(o.buckets).map(([k, v]) => `<div class="kpi"><div class="label">${k} dias</div><div class="value">${money(v)}</div></div>`).join('')}</div>`;
  const openTable = (o) => `<table><thead><tr><th>Nome</th><th>NIF</th><th class="num">Saldo</th><th>Mais antigo</th><th class="num">Dias</th></tr></thead><tbody>
    ${o.rows.map((r) => `<tr><td>${esc(r.name)}</td><td class="mono">${esc(r.nif || '')}</td><td class="num">${money(r.balance)}</td><td>${fmtDate(r.oldest)}</td><td class="num ${r.age_days > 90 ? 'neg' : ''}">${r.age_days ?? ''}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">Sem saldos em aberto.</td></tr>'}</tbody></table>`;
  el.innerHTML = `<div class="grid cols-2">
      <div class="card"><h2>Maiores clientes <span class="muted small">top 3 = ${pct(tc.concentration_top3)} das vendas</span></h2><div id="hc"></div></div>
      <div class="card"><h2>Maiores fornecedores <span class="muted small">top 3 = ${pct(tf.concentration_top3)} das compras</span></h2><div id="hf"></div></div>
    </div>
    <div class="card"><h2>Clientes: saldos em aberto e antiguidade <span class="muted small">total ${money(oc.total)}</span></h2>${aging(oc)}<div class="table-wrap">${openTable(oc)}</div></div>
    <div class="card"><h2>Fornecedores: saldos em aberto e antiguidade <span class="muted small">total ${money(of.total)}</span></h2>${aging(of)}<div class="table-wrap">${openTable(of)}</div></div>
    <p class="muted small">A antiguidade assume que os pagamentos liquidam primeiro os documentos mais antigos. Só considera linhas com terceiro nas contas 21 e 22.</p>`;
  hbars(el.querySelector('#hc'), tc.rows.map((r) => ({ label: r.name, value: r.value, sub: pct(r.pct) })));
  hbars(el.querySelector('#hf'), tf.rows.map((r) => ({ label: r.name, value: r.value, sub: pct(r.pct) })));
}

async function orcamento(el) {
  const b = await api.get('stats.budget', { year: state.year });
  el.innerHTML = `<div class="card"><h2>Orçamento contra real até ${MONTHS_FULL[b.to - 1].toLowerCase()} <button class="primary" id="add">Orçamentar conta</button></h2>
    ${b.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Conta</th><th class="num">Orçado (acum.)</th><th class="num">Real (acum.)</th><th class="num">Desvio</th><th class="num">%</th><th class="num">Orçado no ano</th><th></th></tr></thead><tbody>
      ${b.rows.map((r) => `<tr><td><span class="mono">${esc(r.account)}</span> ${esc(r.name)}</td><td class="num">${money(r.budget_ytd)}</td><td class="num">${money(r.actual_ytd)}</td>
        <td class="num ${r.unfavorable ? 'neg' : 'pos'}">${money(r.deviation)}</td><td class="num ${r.unfavorable ? 'neg' : 'pos'}">${pct(r.deviation_pct)}</td><td class="num">${money(r.budget_year)}</td>
        <td><button class="icon-btn" data-edit="${esc(r.account)}">Editar</button></td></tr>`).join('')}</tbody></table></div><div id="chart" style="margin-top:16px"></div>`
      : '<div class="empty">Ainda não há orçamento. Orçamente as principais contas de rendimentos (7) e gastos (6).</div>'}
    <p class="muted small">Desvio desfavorável (vermelho): gastos acima ou rendimentos abaixo do orçado.</p></div>`;
  const edit = (acc) => {
    const r = b.rows.find((x) => x.account === acc);
    modal({
      title: acc ? `Orçamento ${acc}` : 'Orçamentar conta', wide: true,
      body: `<div class="form"><label>Conta (6 ou 7)<input name="account" class="mono" value="${esc(acc || '')}" ${acc ? 'disabled' : ''}></label>
        <label>Valor mensal igual<input id="fill" class="num" placeholder="preenche os 12 meses"></label></div>
        <div class="form" style="grid-template-columns:repeat(6,1fr);margin-top:12px">${MONTHS.map((m, i) => `<label>${m}<input class="num" data-m="${i}" value="${r ? money(r.budget[i], { blankZero: true }) : ''}"></label>`).join('')}</div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Guardar', primary: true, onClick: async (m) => {
        const months = [...m.querySelectorAll('[data-m]')].map((i) => i.value || '0');
        await api.post('stats.budget_save', { year: state.year, account: acc || m.querySelector('[name=account]').value.trim(), months });
        toast('Orçamento guardado.', 'good');
        await orcamento(el);
      } }],
    }).el.querySelector('#fill').oninput = (e) => { e.target.closest('.modal').querySelectorAll('[data-m]').forEach((i) => { i.value = e.target.value; }); };
  };
  el.querySelector('#add').onclick = () => edit(null);
  el.querySelectorAll('[data-edit]').forEach((x) => { x.onclick = () => edit(x.dataset.edit); });
  if (b.rows.length) {
    const r0 = b.rows[0];
    barChart(el.querySelector('#chart'), { title: 'Orçado contra real', labels: MONTHS, series: [
      { name: `Orçado ${r0.account}`, values: r0.budget, color: 'var(--series-2)' }, { name: `Real ${r0.account}`, values: r0.actual, color: 'var(--series-1)' }] });
  }
}

async function previsao(el) {
  const f = await api.get('stats.forecast', { year: state.year });
  el.innerHTML = `<div class="card"><h2>Previsão do fecho de ${state.year}</h2>
    <p class="muted">${f.months_elapsed} mês(es) com movimentos. Método: <b>${f.method === 'sazonal' ? `sazonal (usa a distribuição mensal de ${state.year - 1})` : 'linear (média mensal × 12)'}</b>.</p>
    <table><thead><tr><th>Rubrica</th><th class="num">Acumulado</th><th class="num">Projeção para o ano</th><th class="num">Ano anterior</th></tr></thead><tbody>
    ${Object.values(f.items).map((i) => `<tr><td>${esc(i.label)}</td><td class="num">${money(i.ytd)}</td><td class="num"><b class="${signClass(i.projection)}">${money(i.projection)}</b></td><td class="num muted">${i.previous_total !== null ? money(i.previous_total) : '—'}</td></tr>`).join('')}
    </tbody></table><p class="muted small" style="margin-top:12px">Projeção indicativa: não considera sazonalidade quando não há ano anterior, nem acontecimentos futuros conhecidos.</p></div>`;
}

async function indicadores(el) {
  const list = await api.get('stats.custom', { year: state.year });
  const draw = (items) => {
    el.innerHTML = `<div class="card"><h2>Indicadores próprios <button class="primary" id="edit">Editar indicadores</button></h2>
      ${items.length ? `<div class="kpis">${items.map((i) => `<div class="kpi"><div class="label">${esc(i.name)}</div><div class="value">${i.error ? '<span class="neg small">' + esc(i.error) + '</span>' : (i.unit === '%' ? pct(i.value) : money(Math.round(i.value * 100)))}</div><div class="delta muted mono">${esc(i.formula)}</div></div>`).join('')}</div>`
        : '<div class="empty">Crie indicadores com fórmulas sobre as contas, por exemplo margem de serviços: <span class="mono">(C(72) - D(62)) / C(72) * 100</span></div>'}
      <p class="muted small"><b>C(71,72)</b> = saldo credor líquido das contas começadas por 71 e 72 · <b>D(62)</b> = saldo devedor líquido · operadores + − * / e parênteses. Valores do ano até ao último mês com movimentos.</p></div>`;
    el.querySelector('#edit').onclick = () => {
      const rows = (items.length ? items : [{ name: '', formula: '', unit: 'ECV' }]).concat([{ name: '', formula: '', unit: 'ECV' }]);
      modal({
        title: 'Indicadores próprios', wide: true,
        body: `<table><thead><tr><th>Nome</th><th>Fórmula</th><th>Unidade</th></tr></thead><tbody id="ci">${rows.map((r) => `<tr><td><input data-k="name" value="${esc(r.name)}"></td><td><input data-k="formula" class="mono" style="width:100%" value="${esc(r.formula)}"></td>
          <td><select data-k="unit"><option ${r.unit === 'ECV' ? 'selected' : ''}>ECV</option><option ${r.unit === '%' ? 'selected' : ''}>%</option></select></td></tr>`).join('')}</tbody></table>
          <p class="muted small">Deixe o nome vazio para remover. Guarde e volte a editar para acrescentar mais linhas.</p>`,
        actions: [{ label: 'Cancelar' }, { label: 'Guardar', primary: true, onClick: async (m) => {
          const out = [...m.querySelectorAll('#ci tr')].map((tr) => Object.fromEntries([...tr.querySelectorAll('[data-k]')].map((i) => [i.dataset.k, i.value])));
          draw(await api.post('stats.custom_save', { year: state.year, items: out }));
          toast('Indicadores guardados.', 'good');
        } }],
      });
    };
  };
  draw(list);
}

async function relatorio(el) {
  const y = state.year;
  const [d, r, inc, costs, top, f, sv, tm] = await Promise.all([
    api.get('stats.dashboard', { year: y }), api.get('stats.ratios', { year: y }), api.get('reports.income', { year: y, from: 1 }),
    api.get('stats.costs', { year: y }), api.get('stats.top', { year: y, kind: 'C', limit: 5 }), api.get('stats.forecast', { year: y }),
    api.get('stats.sales', { year: y }), api.get('stats.terms', { year: y }),
  ]);
  const to = d.to;
  const cut = (arr) => arr.map((v, i) => (i < to ? v : null));
  el.innerHTML = `<div class="toolbar no-print" style="margin-bottom:12px"><button class="primary ro-ok" data-print>Imprimir / guardar em PDF</button></div>
    <div class="card"><h2>Relatório de gestão · ${esc(state.info.name)}</h2>
      <p class="muted">NIF ${esc(state.info.nif || '—')} · janeiro a ${MONTHS_FULL[to - 1].toLowerCase()} de ${y} · gerado em ${fmtDate(new Date().toISOString())}</p>
      <div class="kpis">${d.kpis.map((k) => `<div class="kpi"><div class="label">${esc(k.label)}</div><div class="value ${signClass(k.value)}">${money(k.value)}</div><div class="delta muted">${k.change !== null ? `${k.change >= 0 ? '▲' : '▼'} ${pct(Math.abs(k.change))} vs ${y - 1}` : ''}</div></div>`).join('')}</div>
      <div id="ch"></div>
      <div class="grid cols-2" style="margin-top:16px">
        <div><h3>Demonstração dos resultados</h3><table><tbody>
          ${[['Volume de negócios', inc.lines[0].value], ['EBITDA', inc.ebitda], ['Resultado operacional', inc.ebit], ['Resultado antes de impostos', inc.resultado_antes_impostos], ['Resultado líquido', inc.resultado_liquido]].map(([l, v]) => `<tr><td>${l}</td><td class="num ${signClass(v)}">${money(v)}</td></tr>`).join('')}
        </tbody></table></div>
        <div><h3>Rácios principais</h3><table><tbody>${r.ratios.filter((x) => ['liq_geral', 'autonomia', 'endividamento', 'ros', 'pmr', 'pmp'].includes(x.key)).map((x) => `<tr><td>${esc(x.label)}</td><td class="num">${fmtRatio(x)}</td></tr>`).join('')}</tbody></table></div>
        <div><h3>Principais gastos</h3><table><tbody>${costs.groups.slice(0, 5).map((g) => `<tr><td>${esc(g.name)}</td><td class="num">${money(g.value)}</td><td class="num muted">${pct(g.pct)}</td></tr>`).join('') || '<tr><td>Sem gastos</td></tr>'}</tbody></table></div>
        <div><h3>Principais clientes</h3><table><tbody>${top.rows.map((t) => `<tr><td>${esc(t.name)}</td><td class="num">${money(t.value)}</td><td class="num muted">${pct(t.pct)}</td></tr>`).join('') || '<tr><td>Sem dados</td></tr>'}</tbody></table></div>
        ${sv.items.length ? `<div><h3>Artigos mais vendidos</h3><table><tbody>${sv.items.filter((i) => i.item_id).slice(0, 5).map((i) => `<tr><td>${esc(i.name)}</td><td class="num">${money(i.net)}</td><td class="num muted">${i.margin_pct !== null ? 'margem ' + pct(i.margin_pct) : pct(i.pct)}</td></tr>`).join('')}</tbody></table></div>` : ''}
        ${tm.receivable.count || tm.payable.count ? `<div><h3>Prazos de pagamento</h3><table><tbody>
          <tr><td>Clientes pagam em média em</td><td class="num">${tm.receivable.avg_days ?? '—'} dias</td><td class="num muted">${tm.receivable.on_time_pct !== null ? pct(tm.receivable.on_time_pct) + ' a tempo' : ''}</td></tr>
          <tr><td>A empresa paga em média em</td><td class="num">${tm.payable.avg_days ?? '—'} dias</td><td class="num muted">${tm.payable.on_time_pct !== null ? pct(tm.payable.on_time_pct) + ' a tempo' : ''}</td></tr>
          <tr><td>Vencido por receber</td><td class="num ${tm.receivable.overdue ? 'neg' : ''}">${money(tm.receivable.overdue)}</td><td></td></tr></tbody></table></div>` : ''}
      </div>
      <h3 style="margin-top:16px">Previsão do fecho</h3>
      <p>Mantendo o ritmo atual (${f.method}), o volume de negócios de ${y} deverá rondar <b>${money(f.items.vendas.projection)}</b> e o resultado <b class="${signClass(f.items.resultado.projection)}">${money(f.items.resultado.projection)}</b>.</p>
    </div>`;
  barChart(el.querySelector('#ch'), { title: 'Vendas e gastos', labels: MONTHS, height: 220, series: [
    { name: 'Vendas', values: cut(d.monthly.vendas), color: 'var(--series-1)' }, { name: 'Gastos', values: cut(d.monthly.gastos), color: 'var(--series-3)' }],
    line: { name: 'Resultado', values: cut(d.monthly.resultado), color: 'var(--good)' } });
}

// ------------------------------------------------------------------ a partir dos documentos (faturação e compras)

const dias = (n) => (Number(n) === 1 ? '1 dia' : `${n} dias`);
const qty = (v) => Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 3 });
const change = (c, y) => (c === null || c === undefined ? '' : `<div class="delta ${c >= 0 ? 'pos' : 'neg'}">${c >= 0 ? '▲' : '▼'} ${pct(Math.abs(c))} vs ${y - 1}</div>`);

/** Barra de filtros do período (e extras); ao mudar, volta a abrir o separador com os novos parâmetros. */
function periodBar(tab, q, extra = '') {
  return `<div class="toolbar no-print" id="pf" style="margin-bottom:14px;align-items:flex-end"><label>De<select name="from">${monthOptions(q.from)}</select></label><label>Até<select name="to">${monthOptions(q.to)}</select></label>${extra}
    <button data-print class="ro-ok">Imprimir</button></div>`;
}
function bindPeriod(el, tab) {
  el.querySelectorAll('#pf select').forEach((s) => {
    s.onchange = () => {
      const p = new URLSearchParams();
      el.querySelectorAll('#pf select').forEach((x) => { if (x.value !== '') p.set(x.name, x.value); });
      go(cpath(`estatisticas/${tab}?${p}`));
    };
  });
}
function bindExports(el, name) {
  el.querySelectorAll('[data-xlsx]').forEach((b) => { b.onclick = () => tableToXlsx(el.querySelector('#' + b.dataset.xlsx), `${name}_${b.dataset.xlsx}_${state.year}.xlsx`, { sheet: name, title: `${state.info.name} · ${b.closest('.card').querySelector('h2').firstChild.textContent.trim()} ${state.year}` }); });
}
const xl = (id) => `<button class="icon-btn small" data-xlsx="${id}">Excel</button>`;
const period = (q) => ({ from: Number(q.from || 1), to: Number(q.to || 12) });

async function vendas(el, query) {
  const q = { ...period(query), family: query.family ?? '' };
  const r = await api.get('stats.sales', { year: state.year, ...q });
  const t = r.totals;
  const y = state.year;
  const famSel = `<label>Família<select name="family"><option value="">Todas</option>${r.family_list.map((f) => `<option value="${f.id}" ${String(q.family) === String(f.id) ? 'selected' : ''}>${esc(f.name)}</option>`).join('')}<option value="0" ${q.family === '0' ? 'selected' : ''}>Sem família</option></select></label>`;
  if (!r.items.length) {
    el.innerHTML = periodBar('vendas', q, famSel) + '<div class="empty">Sem documentos de venda emitidos neste período. As estatísticas de vendas usam as faturas, faturas-recibo, talões e notas de crédito emitidos na aplicação.</div>';
    bindPeriod(el, 'vendas');
    return;
  }
  const m = (v) => (v === null ? '<span class="muted">—</span>' : `<span class="${signClass(v)}">${pct(v)}</span>`);
  el.innerHTML = `${periodBar('vendas', q, famSel)}
    <div class="kpis">
      <div class="kpi"><div class="label">Vendas sem IVA</div><div class="value">${money(t.net)}</div>${change(t.change, y)}</div>
      <div class="kpi"><div class="label">Documentos</div><div class="value">${t.docs}</div><div class="small muted">valor médio ${money(t.avg_doc)}</div></div>
      <div class="kpi"><div class="label">Clientes</div><div class="value">${t.customers}</div><div class="small muted">notas de crédito ${money(t.credit_notes)}</div></div>
      <div class="kpi"><div class="label">Margem bruta dos artigos</div><div class="value">${m(t.margin_pct)}</div><div class="small muted">${t.margin_pct === null ? 'sem custo de compra conhecido' : `${money(t.margin)} · ${pct(t.cost_coverage_pct)} das vendas com custo`}</div></div>
    </div>
    <div class="card"><h2>Vendas por mês</h2><div id="ch"></div></div>
    <div class="grid cols-2">
      <div class="card"><h2>Por família ${xl('tf')}</h2><div class="table-wrap" tabindex="0" role="region" aria-label="Vendas por família"><table id="tf"><thead><tr><th>Família</th><th class="num">Vendas</th><th class="num">%</th><th class="num">Margem</th></tr></thead><tbody>
        ${r.families.map((f) => `<tr><td>${esc(f.family)}</td><td class="num">${money(f.net)}</td><td class="num">${pct(f.pct)}</td><td class="num">${m(f.margin_pct)}</td></tr>`).join('')}</tbody></table></div></div>
      <div class="card"><h2>Maiores clientes ${xl('tc')}</h2><div class="table-wrap" tabindex="0" role="region" aria-label="Vendas por cliente"><table id="tc"><thead><tr><th>Cliente</th><th class="num">Doc.</th><th class="num">Vendas</th><th class="num">%</th></tr></thead><tbody>
        ${r.customers.slice(0, 15).map((c) => `<tr><td>${c.id ? `<a href="#/${cpath('cliente/' + c.id)}">${esc(c.name)}</a>` : esc(c.name)}${c.nif ? `<div class="small muted mono">${esc(c.nif)}</div>` : ''}</td><td class="num">${c.docs}</td><td class="num">${money(c.net)}</td><td class="num">${pct(c.pct)}</td></tr>`).join('')}</tbody></table></div></div>
    </div>
    <div class="card"><h2>Por artigo ${xl('ti')}</h2><div class="table-wrap" tabindex="0" role="region" aria-label="Vendas por artigo"><table id="ti">
      <thead><tr><th>Artigo</th><th>Família</th><th class="num">Quantidade</th><th class="num">Preço médio</th><th class="num">Vendas</th><th class="num">%</th><th class="num">Custo médio</th><th class="num">Margem</th><th class="num">Margem %</th></tr></thead><tbody>
      ${r.items.map((i) => `<tr><td>${i.code ? `<span class="mono">${esc(i.code)}</span> ` : ''}${esc(i.name)}</td><td>${esc(i.family || '')}</td><td class="num">${i.item_id ? qty(i.qty) + ' ' + esc(i.unit || '') : ''}</td>
        <td class="num">${i.item_id && i.avg_price !== null ? money(i.avg_price) : ''}</td><td class="num">${money(i.net)}</td><td class="num">${pct(i.pct)}</td>
        <td class="num">${i.unit_cost !== null ? money(Math.round(i.unit_cost)) : '<span class="muted">—</span>'}</td><td class="num">${i.margin !== null ? money(i.margin) : '<span class="muted">—</span>'}</td><td class="num">${m(i.margin_pct)}</td></tr>`).join('')}
      </tbody></table></div>
      <p class="small muted">Valores sem IVA; as notas de crédito abatem valor e quantidade. O custo é o custo médio das compras registadas com esse artigo (em Compras, escolha o artigo na descrição da linha). Serviços e artigos sem compras ficam sem margem.</p></div>`;
  const cut = (arr) => arr.map((v, i) => (i + 1 >= q.from && i + 1 <= q.to ? v : null));
  const series = [{ name: `Vendas ${y}`, values: cut(r.monthly), color: 'var(--series-1)' }];
  if (r.monthly_previous) series.unshift({ name: `Vendas ${y - 1}`, values: cut(r.monthly_previous), color: 'var(--series-2)' });
  barChart(el.querySelector('#ch'), { title: 'Vendas por mês', labels: MONTHS, series, height: 220 });
  bindPeriod(el, 'vendas');
  bindExports(el, 'vendas');
}

async function compras(el, query) {
  const q = period(query);
  const r = await api.get('stats.purchases', { year: state.year, ...q });
  const t = r.totals;
  const y = state.year;
  if (!r.suppliers.length) {
    el.innerHTML = periodBar('compras', q) + '<div class="empty">Sem faturas de fornecedores registadas neste período. As estatísticas de compras usam os documentos registados em Compras.</div>';
    bindPeriod(el, 'compras');
    return;
  }
  el.innerHTML = `${periodBar('compras', q)}
    <div class="kpis">
      <div class="kpi"><div class="label">Compras sem IVA</div><div class="value">${money(t.net)}</div>${change(t.change, y)}</div>
      <div class="kpi"><div class="label">Documentos</div><div class="value">${t.docs}</div></div>
      <div class="kpi"><div class="label">Fornecedores</div><div class="value">${t.suppliers}</div></div>
      <div class="kpi"><div class="label">Investimento (contas 43/44)</div><div class="value">${money(t.investment)}</div></div>
    </div>
    <div class="card"><h2>Compras por mês</h2><div id="ch"></div></div>
    <div class="grid cols-2">
      <div class="card"><h2>Por fornecedor ${xl('ts')}</h2><div class="table-wrap" tabindex="0" role="region" aria-label="Compras por fornecedor"><table id="ts"><thead><tr><th>Fornecedor</th><th class="num">Doc.</th><th class="num">Compras</th><th class="num">IVA</th><th class="num">%</th></tr></thead><tbody>
        ${r.suppliers.map((s) => `<tr><td>${s.id ? `<a href="#/${cpath('terceiro/' + s.id)}">${esc(s.name)}</a>` : esc(s.name)}${s.nif ? `<div class="small muted mono">${esc(s.nif)}</div>` : ''}</td><td class="num">${s.docs}</td><td class="num">${money(s.net)}</td><td class="num">${money(s.vat)}</td><td class="num">${pct(s.pct)}</td></tr>`).join('')}</tbody></table></div></div>
      <div class="card"><h2>Por conta de gasto ${xl('ta')}</h2><div class="table-wrap" tabindex="0" role="region" aria-label="Compras por conta"><table id="ta"><thead><tr><th>Conta</th><th>Nome</th><th class="num">Compras</th><th class="num">%</th></tr></thead><tbody>
        ${r.accounts.map((g) => `<tr class="section"><td class="mono">${esc(g.code)}</td><td>${esc(g.name)}</td><td class="num">${money(g.net)}</td><td class="num">${pct(g.pct)}</td></tr>
          ${g.detail.map((d) => `<tr class="clickable" data-acc="${esc(d.code)}"><td class="mono indent-1">${esc(d.code)}</td><td>${esc(d.name)}</td><td class="num">${money(d.net)}</td><td class="num">${pct(d.pct)}</td></tr>`).join('')}`).join('')}</tbody></table></div></div>
    </div>
    <p class="small muted">Valores sem IVA: faturas de fornecedor e despesas pagas, menos notas de crédito. Para todos os gastos (incluindo salários, depreciações e lançamentos manuais) veja o separador Gastos.</p>`;
  const cut = (arr) => arr.map((v, i) => (i + 1 >= q.from && i + 1 <= q.to ? v : null));
  barChart(el.querySelector('#ch'), { title: 'Compras por mês', labels: MONTHS, series: [{ name: `Compras ${y}`, values: cut(r.monthly), color: 'var(--series-3)' }], height: 220 });
  el.querySelectorAll('tr[data-acc]').forEach((tr) => { tr.onclick = () => go(cpath('relatorios/razao?account=' + tr.dataset.acc)); });
  bindPeriod(el, 'compras');
  bindExports(el, 'compras');
}

async function prazos(el, query) {
  const q = period(query);
  const r = await api.get('stats.terms', { year: state.year, ...q });
  const block = (x, who, id, link) => `
    <div class="kpis">
      <div class="kpi"><div class="label">Prazo médio</div><div class="value">${x.avg_days === null ? '—' : dias(x.avg_days)}</div><div class="small muted">da fatura ao ${who === 'clientes' ? 'recibo' : 'pagamento'}</div></div>
      <div class="kpi"><div class="label">Liquidado dentro do prazo</div><div class="value">${x.on_time_pct === null ? '—' : pct(x.on_time_pct)}</div><div class="small muted">atraso médio ${dias(x.avg_late ?? 0)}</div></div>
      <div class="kpi"><div class="label">Liquidado no período</div><div class="value">${money(x.paid)}</div><div class="small muted">${x.count} movimento(s)</div></div>
      <div class="kpi"><div class="label">Vencido por ${who === 'clientes' ? 'receber' : 'pagar'}</div><div class="value ${x.overdue ? 'neg' : ''}">${money(x.overdue)}</div><div class="small muted">${x.overdue_avg_days ? `há ${dias(x.overdue_avg_days)} em média · ` : ''}em aberto ${money(x.open)}</div></div>
    </div>
    ${x.parties.length ? `<div class="table-wrap" tabindex="0" role="region" aria-label="Prazos por ${who}"><table id="${id}"><thead><tr><th>${who === 'clientes' ? 'Cliente' : 'Fornecedor'}</th><th class="num">Liquidado</th><th class="num">Prazo médio</th><th class="num">Atraso médio</th></tr></thead><tbody>
      ${x.parties.map((p) => `<tr><td>${p.id ? `<a href="#/${cpath(link + p.id)}">${esc(p.name)}</a>` : esc(p.name)}</td><td class="num">${money(p.amount)}</td><td class="num">${dias(p.avg_days)}</td><td class="num ${p.avg_late > 30 ? 'neg' : ''}">${dias(p.avg_late)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">Sem recebimentos ou pagamentos neste período.</div>'}`;
  el.innerHTML = `${periodBar('prazos', q)}
    <div class="card"><h2>Clientes: quanto tempo demoram a pagar ${r.receivable.parties.length ? xl('tr') : ''}</h2>${block(r.receivable, 'clientes', 'tr', 'cliente/')}</div>
    <div class="card"><h2>Fornecedores: quanto tempo a empresa demora a pagar ${r.payable.parties.length ? xl('tp') : ''}</h2>${block(r.payable, 'fornecedores', 'tp', 'terceiro/')}</div>
    <p class="small muted">Calculado com as datas reais dos documentos: para cada valor liquidado, os dias entre a fatura e o recibo ou pagamento, ponderados pelo valor. As faturas-recibo, os talões e as despesas pagas contam 0 dias. O atraso conta a partir da data de vencimento. As listas mostram primeiro quem mais se atrasa. O valor em aberto é a situação atual.</p>`;
  bindPeriod(el, 'prazos');
  bindExports(el, 'prazos');
}
