// Painel de gestão: indicadores do ano até ao último mês com movimentos, séries mensais e atalhos.
import { api } from '../api.js';
import { state, go, cpath, setCrumbs } from '../main.js';
import { barChart, hbars } from '../charts.js';
import { esc, money, MONTHS, pct, signClass } from '../util.js';

const LINKS = { vn: 'relatorios/razao?account=7', mb: 'relatorios/dr', ebitda: 'relatorios/dr', rl: 'relatorios/dr', mfl: 'relatorios/razao?account=1', iva: 'iva' };

export async function render({ view }) {
  setCrumbs('Painel');
  const y = state.year;
  const [d, costs, top, info] = await Promise.all([
    api.get('stats.dashboard', { year: y }),
    api.get('stats.costs', { year: y }),
    api.get('stats.top', { year: y, kind: 'C', limit: 5 }),
    Promise.resolve(state.info),
  ]);
  const noData = d.monthly.vendas.every((v) => !v) && d.monthly.gastos.every((v) => !v);
  view.innerHTML = `
    <div class="page-head"><div><h1>Painel ${y}</h1><p class="muted">Acumulado de janeiro a ${MONTHS[d.to - 1].toLowerCase()}${d.has_previous ? `, comparado com o mesmo período de ${y - 1}` : ''}.</p></div>
      <div class="toolbar"><a class="btn primary" href="#/${cpath('lancamento/novo')}">Novo lançamento</a></div></div>
    ${noData ? `<div class="alert info">Ainda não há lançamentos validados em ${y}. Comece por <a href="#/${cpath('lancamento/novo')}">criar um lançamento</a> ou <a href="#/${cpath('importar')}">importar o histórico</a>.</div>` : ''}
    ${info.drafts ? `<div class="alert warn">Há ${info.drafts} lançamento(s) em rascunho que não entram nos relatórios. <a href="#/${cpath('lancamentos?status=rascunho')}">Ver rascunhos</a></div>` : ''}
    <div class="kpis">${d.kpis.map((k) => `<div class="kpi" data-href="${LINKS[k.key] || ''}" title="${esc(k.hint)}">
        <div class="label">${esc(k.label)}</div>
        <div class="value ${signClass(k.value)}">${money(k.value)}</div>
        <div class="delta muted">${k.change !== null ? `<span class="${k.change >= 0 ? 'pos' : 'neg'}">${k.change >= 0 ? '▲' : '▼'} ${pct(Math.abs(k.change))}</span> vs ${y - 1}` : esc(k.hint)}</div></div>`).join('')}</div>
    <div class="grid cols-2">
      <div class="card"><h2>Vendas e gastos por mês <span class="muted small">clique numa barra para ver os lançamentos</span></h2><div id="ch-sales"></div></div>
      <div class="card"><h2>Meios financeiros no fim do mês</h2><div id="ch-cash"></div></div>
    </div>
    <div class="grid cols-2">
      <div class="card"><h2>Estrutura de gastos <a class="small" href="#/${cpath('estatisticas/gastos')}">detalhe</a></h2><div id="costs"></div></div>
      <div class="card"><h2>Maiores clientes <a class="small" href="#/${cpath('estatisticas/terceiros')}">detalhe</a></h2><div id="top"></div></div>
    </div>
    <div class="card small muted">Margem bruta: <b>${pct(d.margin_pct)}</b> · Margem líquida: <b>${pct(d.net_margin_pct)}</b> · Indicadores calculados neste computador a partir dos lançamentos validados.</div>`;

  view.querySelectorAll('.kpi[data-href]').forEach((k) => { if (k.dataset.href) k.onclick = () => go(cpath(k.dataset.href)); });
  const cut = (arr) => arr.map((v, i) => (i < d.to ? v : null));
  const series = [
    { name: `Vendas ${y}`, values: cut(d.monthly.vendas), color: 'var(--series-1)' },
    { name: `Gastos ${y}`, values: cut(d.monthly.gastos), color: 'var(--series-3)' },
  ];
  if (d.monthly_previous) series.splice(1, 0, { name: `Vendas ${y - 1}`, values: d.monthly_previous.vendas, color: 'var(--series-2)' });
  barChart(view.querySelector('#ch-sales'), {
    title: 'Vendas e gastos por mês', labels: MONTHS, series,
    line: { name: 'Resultado', values: cut(d.monthly.resultado), color: 'var(--good)' },
    onClick: (i, s) => {
      const acc = series[s]?.name.startsWith('Gastos') ? '6' : '7';
      const last = new Date(y, i + 1, 0).getDate();
      go(cpath(`lancamentos?account=${acc}&from=${y}-${String(i + 1).padStart(2, '0')}-01&to=${y}-${String(i + 1).padStart(2, '0')}-${last}`));
    },
  });
  barChart(view.querySelector('#ch-cash'), {
    title: 'Meios financeiros', labels: MONTHS,
    series: [{ name: `Meios financeiros ${y}`, values: cut(d.monthly.tesouraria), color: 'var(--series-1)' }],
    onClick: (i) => go(cpath(`relatorios/razao?account=1&to=${i + 1}`)),
  });
  hbars(view.querySelector('#costs'), costs.groups.slice(0, 6).map((g) => ({ label: `${g.code} ${g.name}`, value: g.value, sub: pct(g.pct), code: g.code })),
    { onClick: (it) => go(cpath(`relatorios/razao?account=${it.code}`)) });
  hbars(view.querySelector('#top'), top.rows.map((r) => ({ label: r.name, value: r.value, sub: pct(r.pct) })));
}
