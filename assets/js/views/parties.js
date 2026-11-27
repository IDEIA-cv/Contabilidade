// Clientes, fornecedores e outros terceiros.
import { api } from '../api.js';
import { setCrumbs, cpath } from '../main.js';
import { invalidateRefs } from './entries.js';
import { esc, modal, formData, toast, debounce } from '../util.js';

const KINDS = { C: 'Cliente', F: 'Fornecedor', A: 'Cliente e fornecedor', O: 'Outro' };

export async function render({ view }) {
  setCrumbs('Clientes e fornecedores');
  let rows = await api.get('thirdparties.list');
  view.innerHTML = `
    <div class="page-head"><div><h1>Clientes e fornecedores</h1><p class="muted">O NIF é validado (9 dígitos para Cabo Verde) e usado no IVA, no SAF-T e na e-Fatura.</p></div>
      <div class="toolbar"><label>Pesquisar<input id="q" placeholder="Nome ou NIF"></label>
        <label>Tipo<select id="kind"><option value="">Todos</option>${Object.entries(KINDS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
        <button class="primary" id="new">Novo terceiro</button></div></div>
    <div class="card"><div class="table-wrap"><table>
      <thead><tr><th>Nome</th><th>NIF</th><th>Tipo</th><th>Cidade</th><th>Conta</th><th>Contacto</th></tr></thead>
      <tbody id="rows"></tbody></table></div></div>`;
  const draw = () => {
    const q = view.querySelector('#q').value.trim().toLowerCase();
    const k = view.querySelector('#kind').value;
    const list = rows.filter((r) => (!k || r.kind === k) && (!q || r.name.toLowerCase().includes(q) || (r.nif || '').includes(q)));
    view.querySelector('#rows').innerHTML = list.map((r) => `<tr class="clickable" data-id="${r.id}">
      <td><b>${esc(r.name)}</b>${r.kind === 'C' || r.kind === 'A' ? ` <a class="small" data-ficha href="#/${cpath('cliente/' + r.id)}">ficha e dívida</a>` : ''}</td><td class="mono">${esc(r.nif || '')}</td><td>${KINDS[r.kind]}</td><td>${esc(r.city || '')}</td>
      <td class="mono">${esc(r.account || '')}</td><td class="small">${esc([r.email, r.phone].filter(Boolean).join(' · '))}</td></tr>`).join('')
      || '<tr><td colspan="6" class="empty">Nenhum terceiro.</td></tr>';
    view.querySelectorAll('tr[data-id]').forEach((tr) => { tr.onclick = (e) => { if (!e.target.closest('[data-ficha]')) edit(rows.find((r) => String(r.id) === tr.dataset.id)); }; });
  };
  const edit = (r = {}) => {
    modal({
      title: r.id ? `Editar ${r.name}` : 'Novo terceiro',
      body: `<div class="form">
        <label class="wide">Nome<input name="name" value="${esc(r.name || '')}"></label>
        <label>NIF<input name="nif" class="mono" maxlength="15" value="${esc(r.nif || '')}"></label>
        <label>Tipo<select name="kind">${Object.entries(KINDS).map(([k, v]) => `<option value="${k}" ${(r.kind || 'C') === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        <label>País<input name="country" maxlength="2" value="${esc(r.country || 'CV')}"></label>
        <label>Conta corrente<input name="account" class="mono" value="${esc(r.account || '')}" placeholder="211 ou 221"></label>
        <label class="wide">Morada<input name="address" value="${esc(r.address || '')}"></label>
        <label>Cidade<input name="city" value="${esc(r.city || '')}"></label>
        <label>Email<input name="email" type="email" value="${esc(r.email || '')}"></label>
        <label>Telefone<input name="phone" value="${esc(r.phone || '')}"></label></div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Guardar', primary: true, onClick: async (el) => {
        await api.post('thirdparties.save', { ...formData(el), id: r.id || null });
        rows = await api.get('thirdparties.list');
        invalidateRefs();
        toast('Terceiro guardado.', 'good');
        draw();
      } }],
    });
  };
  view.querySelector('#q').oninput = debounce(draw, 150);
  view.querySelector('#kind').onchange = draw;
  view.querySelector('#new').onclick = () => edit();
  draw();
}
