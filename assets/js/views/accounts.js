// Plano de contas: árvore pesquisável, criar subcontas e renomear.
import { api } from '../api.js';
import { setCrumbs, cpath } from '../main.js';
import { invalidateRefs } from './entries.js';
import { esc, modal, formData, toast, debounce } from '../util.js';

export async function render({ view }) {
  setCrumbs('Plano de contas');
  let accounts = await api.get('accounts.list');
  view.innerHTML = `
    <div class="page-head"><div><h1>Plano de contas</h1><p class="muted">Base SNCRF, a validar pelo contabilista. Só as contas sem subcontas (de movimento) aceitam lançamentos.</p></div>
      <div class="toolbar"><label>Pesquisar<input id="q" placeholder="Código ou nome"></label>
        <label>Classe<select id="cls"><option value="">Todas</option>${[1, 2, 3, 4, 5, 6, 7, 8].map((c) => `<option>${c}</option>`).join('')}</select></label>
        <button class="primary" id="new" data-admin>Nova conta</button></div></div>
    <div class="card"><div class="table-wrap"><table>
      <thead><tr><th style="width:140px">Código</th><th>Nome</th><th>Tipo</th><th>Estado</th><th></th></tr></thead>
      <tbody id="rows"></tbody></table></div></div>`;
  const draw = () => {
    const q = view.querySelector('#q').value.trim().toLowerCase();
    const cls = view.querySelector('#cls').value;
    const rows = accounts.filter((a) => (!cls || String(a.class) === cls) && (!q || a.code.startsWith(q) || a.name.toLowerCase().includes(q)));
    view.querySelector('#rows').innerHTML = rows.map((a) => `<tr class="lvl-${Math.min(a.level, 3)}">
        <td class="mono indent-${Math.min(a.level - 1, 4)}">${esc(a.code)}</td><td>${esc(a.name)}</td>
        <td>${Number(a.movement) ? '<span class="badge info">Movimento</span>' : '<span class="badge">Razão</span>'}</td>
        <td>${Number(a.active) ? '' : '<span class="badge warn">Inativa</span>'}</td>
        <td class="num"><button class="icon-btn" data-sub="${esc(a.code)}" title="Criar subconta">+ subconta</button>
          <button class="icon-btn" data-admin data-edit="${esc(a.code)}">Editar</button>
          ${Number(a.movement) && Number(a.used) ? `<a class="small" href="#/${cpath('relatorios/razao?account=' + a.code)}">extrato</a>` : ''}</td></tr>`).join('')
      || '<tr><td colspan="5" class="empty">Nenhuma conta.</td></tr>';
    view.querySelectorAll('[data-sub]').forEach((b) => { b.onclick = () => edit({ code: b.dataset.sub, name: '' }, true); });
    view.querySelectorAll('[data-edit]').forEach((b) => { b.onclick = () => edit(accounts.find((a) => a.code === b.dataset.edit), false); });
  };
  const edit = (a, isNew) => {
    const parent = isNew && a?.code ? accounts.find((x) => x.code === a.code) : null;
    modal({
      title: isNew ? (parent ? `Nova subconta de ${parent.code} ${parent.name}` : 'Nova conta') : `Editar conta ${a.code}`,
      body: `<div class="form">
        <label>Código<input name="code" class="mono" value="${esc(isNew ? (parent ? parent.code : '') : a.code)}" ${isNew ? '' : 'disabled'}></label>
        <label class="wide">Nome<input name="name" value="${esc(isNew ? '' : a.name)}"></label>
        ${isNew ? '' : `<label><span>Ativa</span><select name="active"><option value="1" ${Number(a.active) ? 'selected' : ''}>Sim</option><option value="0" ${Number(a.active) ? '' : 'selected'}>Não</option></select></label>`}
        ${parent && Number(parent.used) ? '<p class="wide alert warn">Esta conta já tem movimentos e não pode receber subcontas.</p>' : ''}</div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Guardar', primary: true, onClick: async (el) => {
        const d = formData(el);
        if (!isNew) d.code = a.code; else d.new = 1;
        await api.post('accounts.save', d);
        accounts = await api.get('accounts.list');
        invalidateRefs();
        toast('Conta guardada.', 'good');
        draw();
      } }],
    });
  };
  view.querySelector('#q').oninput = debounce(draw, 150);
  view.querySelector('#cls').onchange = draw;
  view.querySelector('#new').onclick = () => edit(null, true);
  draw();
}
