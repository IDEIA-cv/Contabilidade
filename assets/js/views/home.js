// Página inicial: carteira de empresas (vista de gabinete), criar e restaurar empresas.
import { api } from '../api.js';
import { go } from '../main.js';
import { esc, money, modal, formData, toast, signClass } from '../util.js';

export async function render({ view }) {
  view.innerHTML = '<div class="loading">A carregar empresas…</div>';
  const [ping, list] = await Promise.all([api.get('ping'), api.get('portfolio')]);
  view.innerHTML = `
    <div class="page-head">
      <div><h1>Empresas</h1><p class="muted">Cada empresa é um ficheiro <span class="mono">.ideia</span> guardado neste computador, em <span class="mono">${esc(ping.data_dir)}</span>.</p></div>
      <div class="toolbar">
        <button id="restore">Abrir cópia (.ideia)</button>
        <button class="primary" id="create">Nova empresa</button>
      </div>
    </div>
    ${list.length ? `<div class="card"><h2>Carteira <span class="muted small">${list.length} empresa(s)</span></h2>
      <div class="table-wrap"><table>
        <thead><tr><th>Empresa</th><th>NIF</th><th>Exercício</th><th class="num">Volume de negócios</th><th class="num">Resultado</th><th>Último lançamento</th><th>Alertas</th></tr></thead>
        <tbody>${list.map((c) => `<tr class="clickable" data-id="${esc(c.id)}">
          <td><b>${esc(c.name)}</b></td><td>${esc(c.nif || '')}</td>
          <td>${c.year ? `${c.year}${c.to ? ` <span class="muted small">até mês ${c.to}</span>` : ''}` : ''}</td>
          <td class="num">${c.vn !== undefined ? money(c.vn) : ''}</td>
          <td class="num ${signClass(c.rl)}">${c.rl !== undefined ? money(c.rl) : ''}</td>
          <td>${esc(c.last_entry || '—')}</td>
          <td>${(c.alerts || []).map((a) => `<span class="badge warn">${esc(a)}</span>`).join(' ') || '<span class="badge good">Em dia</span>'}</td>
        </tr>`).join('')}</tbody></table></div></div>`
      : `<div class="card empty"><h2>Ainda não há empresas</h2><p>Crie a primeira empresa ou abra um ficheiro .ideia de outro computador.</p></div>`}
    <div class="card"><h2>Como funciona</h2>
      <div class="grid cols-3 small">
        <div><h3>Sem cloud</h3><p class="muted">Os dados ficam no ficheiro da empresa. A internet só será usada para comunicar faturas à e-Fatura.</p></div>
        <div><h3>Portável</h3><p class="muted">Para mudar de computador, faça uma cópia em "Empresa e cópias" e abra-a no outro computador.</p></div>
        <div><h3>Migração</h3><p class="muted">Importe o histórico por SAF-T (Primavera, PHC, Sage, Vendus…) ou por folhas Excel/CSV.</p></div>
      </div></div>`;
  view.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = () => go(`c/${tr.dataset.id}/painel`); });
  view.querySelector('#create').onclick = createDialog;
  view.querySelector('#restore').onclick = restoreDialog;
}

function createDialog() {
  modal({
    title: 'Nova empresa',
    body: `<div class="form">
      <label class="wide">Nome da empresa<input name="name" required placeholder="Ex.: Empresa Exemplo, Lda"></label>
      <label>NIF<input name="nif" inputmode="numeric" maxlength="9" placeholder="9 dígitos"></label>
      <label>Primeiro exercício<input name="year" type="number" value="${new Date().getFullYear()}"></label>
      <label>Morada<input name="address"></label>
      <label>Cidade<input name="city" placeholder="Praia"></label>
      <label class="wide">Regime<select name="regime"><option value="contabilidade_organizada">Contabilidade organizada</option><option value="rempe">REMPE</option></select></label>
      <p class="wide muted small">É criado o plano de contas base SNCRF, os diários e os códigos de IVA (15%). Pode alterá-los depois.</p></div>`,
    actions: [
      { label: 'Cancelar' },
      { label: 'Criar empresa', primary: true, onClick: async (el) => {
        const r = await api.post('companies.create', formData(el));
        toast('Empresa criada.', 'good');
        go(`c/${r.id}/painel`);
      } },
    ],
  });
}

function restoreDialog() {
  modal({
    title: 'Abrir cópia de uma empresa',
    body: `<p class="muted">Escolha um ficheiro <span class="mono">.ideia</span> (por exemplo, uma cópia trazida de outro computador). É adicionado como uma nova empresa; nada é substituído.</p>
      <input type="file" name="file" accept=".ideia">`,
    actions: [
      { label: 'Cancelar' },
      { label: 'Abrir', primary: true, onClick: async (el) => {
        const f = el.querySelector('input[type=file]').files[0];
        if (!f) throw new Error('Escolha um ficheiro.');
        const r = await api.upload('companies.restore', f);
        toast(`Empresa "${r.name}" aberta.`, 'good');
        go(`c/${r.id}/painel`);
      } },
    ],
  });
}
