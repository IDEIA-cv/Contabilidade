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
import * as bank from './views/bank.js';
import * as sales from './views/sales.js';
import * as purchases from './views/purchases.js';
import * as efatura from './views/efatura.js';

export const state = { company: null, info: null, year: null, companies: [] };

const NAV = [
  { group: null, items: [['painel', 'Painel']] },
  { group: 'Vendas', items: [['vendas', 'Documentos de venda'], ['dividas', 'Dívidas de clientes'], ['artigos', 'Artigos'], ['artigos?tipos=1', 'Tipos de artigo']] },
  { group: 'Compras', items: [['compras', 'Faturas de fornecedores'], ['dividas-fornecedores', 'Dívidas a fornecedores'], ['pagamentos', 'Mapa de pagamentos']] },
  { group: 'Fatura eletrónica', items: [['efatura', 'e-Fatura: estado e envio'], ['efatura/recebidos', 'Documentos recebidos'], ['efatura/conferencia', 'Conferência do IVA'], ['efatura/configurar', 'Configuração e-Fatura'], ['efatura/documentacao', 'Documentação oficial']] },
  { group: 'Contabilidade', items: [['lancamentos', 'Lançamentos'], ['contas', 'Plano de contas'], ['terceiros', 'Clientes e fornecedores'], ['reconciliacao', 'Reconciliação bancária']] },
  { group: 'Relatórios', items: [['relatorios/balancete', 'Balancete'], ['relatorios/razao', 'Extrato de conta'], ['relatorios/diario', 'Diário'], ['relatorios/balanco', 'Balanço'], ['relatorios/dr', 'Demonstração de resultados'], ['iva', 'Apuramento do IVA']] },
  { group: 'Estatísticas', items: [['estatisticas/racios', 'Rácios financeiros'], ['estatisticas/gastos', 'Estrutura de gastos'], ['estatisticas/terceiros', 'Clientes e fornecedores'], ['estatisticas/orcamento', 'Orçamento'], ['estatisticas/previsao', 'Previsão'], ['estatisticas/indicadores', 'Indicadores próprios'], ['estatisticas/relatorio', 'Relatório de gestão']] },
  { group: 'Dados', items: [['importar', 'Importar e exportar'], ['exercicios', 'Exercícios e fecho'], ['empresa', 'Empresa e cópias']] },
];

const ROUTES = {
  painel: dashboard, lancamentos: entries, lancamento: entries, contas: accounts, terceiros: parties,
  relatorios: reports, iva: vat, estatisticas: stats, importar: imports, exercicios: settings, empresa: settings, reconciliacao: bank, vendas: sales, artigos: sales, dividas: sales, cliente: sales, terceiro: sales, compras: purchases, 'dividas-fornecedores': purchases, pagamentos: purchases, efatura,
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
    `<a href="#/${cpath(p)}" class="${cur === p || (cur.startsWith(p + '/') && p !== 'painel' && p !== 'efatura') || (p === 'lancamentos' && cur.startsWith('lancamento')) ? 'active' : ''}">${label}</a>`).join('')).join('');
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
    if (err.auth) { view.innerHTML = ''; return; }
    console.error(err);
    view.innerHTML = `<div class="alert bad">${esc(err.message)}</div><p><a href="#/">Voltar às empresas</a></p>`;
  }
}
export const rerender = route_;

// ---------------------------------------------------------------- palavra-passe de acesso (opcional)
let loginShown = false;
function showLogin() {
  if (loginShown) return;
  loginShown = true;
  document.body.classList.add('locked');
  const box = document.createElement('div');
  box.className = 'login-screen';
  box.innerHTML = `<form class="card login-card" aria-label="Entrar">
      <img src="assets/img/icon.svg" alt="" width="40" height="40">
      <h1>IDEIA Contabilidade</h1>
      <p class="muted">Introduza a palavra-passe de acesso.</p>
      <label class="sr-only" for="login-pw">Palavra-passe</label>
      <input type="password" id="login-pw" autocomplete="current-password" placeholder="Palavra-passe" required>
      <p class="neg small" id="login-err" role="alert"></p>
      <button class="primary" type="submit">Entrar</button>
      <p class="small muted">Esqueceu-se? Quem instalou a aplicação pode apagar o ficheiro <span class="mono">acesso.json</span> na pasta de dados.</p></form>`;
  document.body.appendChild(box);
  const pw = box.querySelector('#login-pw');
  pw.focus();
  box.querySelector('form').onsubmit = async (e) => {
    e.preventDefault();
    const btn = box.querySelector('button');
    btn.disabled = true;
    try {
      await api.post('auth.login', { password: pw.value });
      box.remove();
      loginShown = false;
      document.body.classList.remove('locked');
      route_();
    } catch (err) {
      box.querySelector('#login-err').textContent = err.message;
      pw.select();
    } finally { btn.disabled = false; }
  };
}
window.addEventListener('ideia:auth', showLogin);
export async function logout() { await api.post('auth.logout'); showLogin(); }

// impressão sem atributos onclick (a política de segurança de conteúdos bloqueia scripts em linha)
document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) window.print(); });

window.addEventListener('hashchange', route_);
document.getElementById('menu-btn').onclick = () => document.getElementById('sidebar').classList.toggle('open');
window.addEventListener('unhandledrejection', (e) => { if (!e.reason?.auth) toast(e.reason?.message || String(e.reason), 'bad'); });
api.get('auth.status').then((a) => {
  const btn = document.getElementById('logout');
  btn.hidden = !a.enabled;
  btn.onclick = logout;
  if (a.enabled && !a.logged) showLogin(); else route_();
}).catch(() => route_());
