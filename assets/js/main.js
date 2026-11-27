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
import * as imobilizado from './views/assets.js';

export const state = { company: null, info: null, year: null, companies: [] };

// Menu lateral: cada secção é um item que abre e fecha os seus submenus.
const ICONS = {
  painel: '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>',
  vendas: '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h8M9 17h6"/>',
  compras: '<path d="M3 3h2l2.4 12.2a2 2 0 0 0 2 1.8h8.2a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="21" r="1"/><circle cx="18" cy="21" r="1"/>',
  efatura: '<path d="M4 4h16v16H4z"/><path d="M4 9h16M9 14l2 2 4-4"/>',
  contabilidade: '<path d="M4 19.5V5a2 2 0 0 1 2-2h14v16H6.5a2.5 2.5 0 0 0 0 5H20"/>',
  relatorios: '<path d="M14 2H6v20h12V6z"/><path d="M14 2v4h4M9 18v-4M12 18v-7M15 18v-2"/>',
  estatisticas: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
  dados: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
};
const NAV = [
  { key: 'painel', label: 'Painel', href: 'painel' },
  { key: 'vendas', label: 'Vendas', items: [['vendas', 'Documentos de venda'], ['dividas', 'Dívidas de clientes'], ['artigos', 'Artigos'], ['artigos?tipos=1', 'Tipos de artigo']] },
  { key: 'compras', label: 'Compras', items: [['compras', 'Faturas de fornecedores'], ['dividas-fornecedores', 'Dívidas a fornecedores'], ['pagamentos', 'Mapa de pagamentos']] },
  { key: 'efatura', label: 'Fatura eletrónica', items: [['efatura', 'Estado e envio'], ['efatura/recebidos', 'Documentos recebidos'], ['efatura/conferencia', 'Conferência do IVA'], ['efatura/configurar', 'Configuração'], ['efatura/documentacao', 'Documentação oficial']] },
  { key: 'contabilidade', label: 'Contabilidade', items: [['lancamentos', 'Lançamentos'], ['contas', 'Plano de contas'], ['terceiros', 'Clientes e fornecedores'], ['reconciliacao', 'Reconciliação bancária'], ['imobilizado', 'Imobilizado']] },
  { key: 'relatorios', label: 'Relatórios', items: [['relatorios/balancete', 'Balancete'], ['relatorios/razao', 'Extrato de conta'], ['relatorios/diario', 'Diário'], ['relatorios/balanco', 'Balanço'], ['relatorios/dr', 'Demonstração de resultados'], ['iva', 'Apuramento do IVA']] },
  { key: 'estatisticas', label: 'Estatísticas', items: [['estatisticas/racios', 'Rácios financeiros'], ['estatisticas/vendas', 'Vendas e artigos'], ['estatisticas/compras', 'Compras'], ['estatisticas/prazos', 'Prazos de pagamento'], ['estatisticas/gastos', 'Estrutura de gastos'], ['estatisticas/terceiros', 'Clientes e fornecedores'], ['estatisticas/orcamento', 'Orçamento'], ['estatisticas/previsao', 'Previsão'], ['estatisticas/indicadores', 'Indicadores próprios'], ['estatisticas/relatorio', 'Relatório de gestão']] },
  { key: 'dados', label: 'Dados e configuração', items: [['importar', 'Importar e exportar'], ['exercicios', 'Exercícios e fecho'], ['empresa', 'Empresa e cópias']] },
];
const icon = (k) => `<svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true">${ICONS[k] || ''}</svg>`;
const openGroups = () => { try { return JSON.parse(localStorage.getItem('ideia.nav') || '[]'); } catch { return []; } };
const saveGroups = (g) => { try { localStorage.setItem('ideia.nav', JSON.stringify(g)); } catch { /* sem armazenamento */ } };
/** A ligação corresponde ao ecrã atual? (com ?query: tem de coincidir exatamente) */
function navActive(p, cur, full) {
  if (p.includes('?')) return full === p;
  if (p === 'artigos' && full.startsWith('artigos?tipos=')) return false;
  return cur === p || (cur.startsWith(p + '/') && p !== 'painel' && p !== 'efatura') || (p === 'lancamentos' && cur.startsWith('lancamento'));
}

const ROUTES = {
  painel: dashboard, lancamentos: entries, lancamento: entries, contas: accounts, terceiros: parties,
  relatorios: reports, iva: vat, estatisticas: stats, importar: imports, exercicios: settings, empresa: settings, reconciliacao: bank, vendas: sales, artigos: sales, dividas: sales, cliente: sales, terceiro: sales, compras: purchases, 'dividas-fornecedores': purchases, pagamentos: purchases, efatura, imobilizado,
};

export function go(path) {
  const hash = '#/' + path.replace(/^#?\/?/, '');
  // o mesmo endereço não dispara hashchange (ex.: emitir um rascunho já aberto): volta a desenhar o ecrã
  if (location.hash === hash) route_(); else location.hash = hash;
}
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
  const full = location.hash.replace(/^#\/c\/[^/]+\//, '');
  const opened = openGroups();
  nav.innerHTML = NAV.map((g) => {
    if (!g.items) return `<a href="#/${cpath(g.href)}" class="nav-top ${navActive(g.href, cur, full) ? 'active' : ''}">${icon(g.key)}<span>${g.label}</span></a>`;
    const here = g.items.some(([p]) => navActive(p, cur, full));
    const open = here || opened.includes(g.key);
    return `<div class="nav-group ${here ? 'here' : ''}">
      <button type="button" class="nav-top" data-group="${g.key}" aria-expanded="${open}" aria-controls="nav-${g.key}">${icon(g.key)}<span>${g.label}</span><svg class="nav-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
      <div class="nav-sub" id="nav-${g.key}" ${open ? '' : 'hidden'}>${g.items.map(([p, label]) => `<a href="#/${cpath(p)}" class="${navActive(p, cur, full) ? 'active' : ''}" ${navActive(p, cur, full) ? 'aria-current="page"' : ''}>${label}</a>`).join('')}</div></div>`;
  }).join('');
  nav.querySelectorAll('[data-group]').forEach((b) => {
    b.onclick = () => {
      const open = b.getAttribute('aria-expanded') !== 'true';
      b.setAttribute('aria-expanded', String(open));
      b.nextElementSibling.hidden = !open;
      const g = openGroups().filter((k) => k !== b.dataset.group);
      saveGroups(open ? [...g, b.dataset.group] : g);
    };
  });
  const opts = state.companies.map((c) => `<option value="${esc(c.id)}" ${c.id === state.company ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
  sw.innerHTML = `<select aria-label="Empresa" id="company-select">${opts}<option value="">— Todas as empresas —</option></select>`;
  sw.querySelector('select').onchange = (e) => { e.target.value ? go(`c/${e.target.value}/painel`) : go(''); };
  if (state.info) {
    right.innerHTML = `<label class="small muted" style="display:flex;align-items:center;gap:6px">Exercício
        <select id="year-select">${state.info.years.map((y) => `<option ${Number(y.year) === state.year ? 'selected' : ''}>${y.year}</option>`).join('')}</select></label>
        ${state.auth?.role === 'leitura' ? '<span class="badge warn" title="Pode consultar, imprimir e exportar, mas não alterar">Só leitura</span>' : ''}
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
      state.company = id; api.company = id; state.year = null; state.info = null;
      // dois encaminhamentos seguidos (ex.: entrada + hashchange) esperam pelo mesmo carregamento da empresa
      companyLoad = (async () => { state.companies = await api.get('companies.list'); await refreshInfo(); })();
      companyLoad.catch(() => { state.company = null; });
    }
    await companyLoad;
    if (my !== routing) return;
    // um encaminhamento intermédio (ex.: ida rápida à lista de empresas) pode ter limpo a empresa: recarrega
    if (state.company !== id || !state.info) {
      state.company = id; api.company = id;
      await refreshInfo();
      if (my !== routing) return;
    }
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
let companyLoad = null;
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
      <p class="muted">Entre com o seu utilizador e palavra-passe.</p>
      <label class="sr-only" for="login-user">Utilizador</label>
      <input id="login-user" autocomplete="username" placeholder="Utilizador" autocapitalize="none">
      <label class="sr-only" for="login-pw">Palavra-passe</label>
      <input type="password" id="login-pw" autocomplete="current-password" placeholder="Palavra-passe" required>
      <p class="neg small" id="login-err" role="alert"></p>
      <button class="primary" type="submit">Entrar</button>
      <p class="small muted">Esqueceu-se? Peça ao administrador para lhe definir uma nova. Se ninguém se lembrar, quem instalou a aplicação pode apagar o ficheiro <span class="mono">acesso.json</span> na pasta de dados.</p></form>`;
  document.body.appendChild(box);
  const pw = box.querySelector('#login-pw');
  const user = box.querySelector('#login-user');
  try { user.value = localStorage.getItem('ideia.user') || ''; } catch { /* sem armazenamento */ }
  (user.value ? pw : user).focus();
  box.querySelector('form').onsubmit = async (e) => {
    e.preventDefault();
    const btn = box.querySelector('button');
    btn.disabled = true;
    try {
      await api.post('auth.login', { username: user.value, password: pw.value });
      try { localStorage.setItem('ideia.user', user.value); } catch { /* sem armazenamento */ }
      box.remove();
      loginShown = false;
      document.body.classList.remove('locked');
      await applyAuth(false);
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
/** Mostra quem está ligado (rodapé do menu) e o perfil (atributo data-role no <body>). Devolve o estado. */
export async function applyAuth(rerouteIfLocked = true) {
  const a = await api.get('auth.status');
  state.auth = a;
  const btn = document.getElementById('logout');
  btn.hidden = !a.enabled;
  btn.onclick = logout;
  const who = document.getElementById('who');
  if (who) who.innerHTML = a.user ? `<b>${esc(a.user.name)}</b> · ${esc(a.user.role_name)}` : '';
  document.body.dataset.role = a.role || 'admin';
  if (rerouteIfLocked && a.enabled && !a.logged) showLogin();
  return a;
}
applyAuth().then((a) => { if (!(a.enabled && !a.logged)) route_(); }).catch(() => route_());
