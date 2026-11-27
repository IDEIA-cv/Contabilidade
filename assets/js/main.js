// Arranque da interface: rotas (hash), barra lateral, seletor de empresa e de exercício.
import { api } from './api.js';
import { esc, toast } from './util.js';
import * as home from './views/home.js';
import * as dashboard from './views/dashboard.js';
import * as entries from './views/entries.js';
import * as accounts from './views/accounts.js';
import * as parties from './views/parties.js';
import * as reports from './views/reports.js';
import * as vat from './views/vat.js';
import * as stats from './views/stats.js';
import * as imports from './views/imports.js';
import * as settings from './views/settings.js';

export const state = { company: null, info: null, year: null, companies: [] };

const NAV = [
  { group: null, items: [['painel', 'Painel']] },
  { group: 'Contabilidade', items: [['lancamentos', 'Lançamentos'], ['contas', 'Plano de contas'], ['terceiros', 'Clientes e fornecedores']] },
  { group: 'Relatórios', items: [['relatorios/balancete', 'Balancete'], ['relatorios/razao', 'Extrato de conta'], ['relatorios/diario', 'Diário'], ['relatorios/balanco', 'Balanço'], ['relatorios/dr', 'Demonstração de resultados'], ['iva', 'Apuramento do IVA']] },
  { group: 'Estatísticas', items: [['estatisticas/racios', 'Rácios financeiros'], ['estatisticas/gastos', 'Estrutura de gastos'], ['estatisticas/terceiros', 'Clientes e fornecedores'], ['estatisticas/orcamento', 'Orçamento'], ['estatisticas/previsao', 'Previsão'], ['estatisticas/indicadores', 'Indicadores próprios'], ['estatisticas/relatorio', 'Relatório de gestão']] },
  { group: 'Dados', items: [['importar', 'Importar e exportar'], ['exercicios', 'Exercícios e fecho'], ['empresa', 'Empresa e cópias']] },
];

const ROUTES = {
  painel: dashboard, lancamentos: entries, lancamento: entries, contas: accounts, terceiros: parties,
  relatorios: reports, iva: vat, estatisticas: stats, importar: imports, exercicios: settings, empresa: settings,
};

export function go(path) { location.hash = '#/' + path.replace(/^#?\/?/, ''); }
export function cpath(path) { return `c/${state.company}/${path}`; }

export async function refreshInfo() {
  state.info = await api.get('company.info');
  const years = state.info.years.map((y) => Number(y.year));
  const saved = Number(localStorage.getItem('ideia.year.' + state.company));
  if (!state.year || !years.includes(state.year)) state.year = years.includes(saved) ? saved : Math.max(...years);
  renderChrome();
  return state.info;
}

function parseHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [path, qs] = raw.split('?');
  const parts = path.split('/').filter(Boolean);
  return { parts, query: Object.fromEntries(new URLSearchParams(qs || '')) };
}

function renderChrome(route = '') {
  const nav = document.getElementById('nav');
  const sw = document.getElementById('company-switch');
  const right = document.getElementById('topbar-right');
  if (!state.company) {
    nav.innerHTML = '<a href="#/" class="active">Empresas</a>';
    sw.innerHTML = '';
    right.innerHTML = '';
    return;
  }
  const cur = route || parseHash().parts.slice(2).join('/');
  nav.innerHTML = NAV.map((g) => (g.group ? `<div class="group">${g.group}</div>` : '') + g.items.map(([p, label]) =>
    `<a href="#/${cpath(p)}" class="${cur === p || (cur.startsWith(p + '/') && p !== 'painel') || (p === 'lancamentos' && cur.startsWith('lancamento')) ? 'active' : ''}">${label}</a>`).join('')).join('');
  const opts = state.companies.map((c) => `<option value="${esc(c.id)}" ${c.id === state.company ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
  sw.innerHTML = `<select aria-label="Empresa" id="company-select">${opts}<option value="">— Todas as empresas —</option></select>`;
  sw.querySelector('select').onchange = (e) => { e.target.value ? go(`c/${e.target.value}/painel`) : go(''); };
  if (state.info) {
    right.innerHTML = `<label class="small muted" style="display:flex;align-items:center;gap:6px">Exercício
        <select id="year-select">${state.info.years.map((y) => `<option ${Number(y.year) === state.year ? 'selected' : ''}>${y.year}</option>`).join('')}</select></label>
        ${state.info.drafts ? `<a class="badge warn" href="#/${cpath('lancamentos?status=rascunho')}">${state.info.drafts} rascunho(s)</a>` : ''}`;
    right.querySelector('#year-select').onchange = (e) => {
      state.year = Number(e.target.value);
      localStorage.setItem('ideia.year.' + state.company, state.year);
      route_();
    };
  }
}

export function setCrumbs(...items) {
  document.getElementById('crumbs').innerHTML = [state.info ? `<b>${esc(state.info.name)}</b>` : '<b>IDEIA Contabilidade</b>', ...items.map(esc)].join(' · ');
  document.title = [items[items.length - 1], state.info?.name, 'IDEIA Contabilidade'].filter(Boolean).join(' · ');
}

let routing = 0;
async function route_() {
  const my = ++routing;
  const { parts, query } = parseHash();
  const view = document.getElementById('view');
  document.getElementById('sidebar').classList.remove('open');
  try {
    if (parts[0] !== 'c' || !parts[1]) {
      state.company = null; state.info = null; api.company = null;
      state.companies = await api.get('companies.list');
      renderChrome();
      setCrumbs('Empresas');
      await home.render({ view, query, state });
      return;
    }
    const id = parts[1];
    if (state.company !== id) {
      state.company = id; api.company = id; state.year = null;
      state.companies = await api.get('companies.list');
      await refreshInfo();
    }
    if (my !== routing) return;
    const section = parts[2] || 'painel';
    const mod = ROUTES[section];
    renderChrome(parts.slice(2).join('/'));
    view.innerHTML = '<div class="loading">A carregar…</div>';
    if (!mod) { view.innerHTML = '<div class="empty">Página não encontrada.</div>'; return; }
    await mod.render({ view, section, params: parts.slice(3), query, state });
    view.focus({ preventScroll: true });
  } catch (err) {
    console.error(err);
    view.innerHTML = `<div class="alert bad">${esc(err.message)}</div><p><a href="#/">Voltar às empresas</a></p>`;
  }
}
export const rerender = route_;

window.addEventListener('hashchange', route_);
document.getElementById('menu-btn').onclick = () => document.getElementById('sidebar').classList.toggle('open');
window.addEventListener('unhandledrejection', (e) => toast(e.reason?.message || String(e.reason), 'bad'));
route_();
