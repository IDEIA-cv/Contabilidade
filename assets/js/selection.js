// Seleção de documentos numa lista (caixas de verificação) e exportação para ZIP: os escolhidos, ou todos os filtrados.
// A seleção mantém-se ao mudar de página e é limpa quando os filtros mudam.
import { api } from './api.js';
import { esc, toast, confirmDialog } from './util.js';

const STORE = new Map();

export const selHead = '<th class="sel"><input type="checkbox" id="sel-page" aria-label="Selecionar todos desta página"></th>';
export const selCell = (id, label) => `<td class="sel"><input type="checkbox" data-sel="${id}" aria-label="Selecionar ${esc(label)}"></td>`;
export const selBox = '<span class="toolbar" id="sel-box"></span>';

/** key: 'vendas' / 'compras'; filters: filtros atuais (sem offset); total: n.º de documentos filtrados. */
export function bindSelection(view, { key, action, filters, total }) {
  const fkey = key + JSON.stringify(filters);
  let entry = STORE.get(key);
  if (!entry || entry.fkey !== fkey) { entry = { fkey, ids: new Set() }; STORE.set(key, entry); }
  const sel = entry.ids;
  const box = view.querySelector('#sel-box');
  const boxes = [...view.querySelectorAll('[data-sel]')];
  const all = view.querySelector('#sel-page');

  const refresh = () => {
    boxes.forEach((b) => { b.checked = sel.has(b.dataset.sel); b.closest('tr').classList.toggle('selected', b.checked); });
    if (all) { const n = boxes.filter((b) => b.checked).length; all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    box.innerHTML = sel.size
      ? `<span class="small"><b>${sel.size}</b> selecionado(s)</span><button id="sel-clear">Limpar seleção</button><button class="primary" id="sel-zip">Exportar ${sel.size} em ZIP</button>`
      : `<button id="sel-zip" ${total ? '' : 'disabled'} title="Exporta todos os documentos que cumprem os filtros. Para escolher, marque as caixas da lista.">Exportar ZIP (${total})</button>`;
    box.querySelector('#sel-clear')?.addEventListener('click', () => { sel.clear(); refresh(); });
    box.querySelector('#sel-zip').onclick = exportZip;
  };

  const exportZip = async (e) => {
    const btn = e.currentTarget;
    let params;
    if (sel.size) params = { ids: [...sel].join(',') };
    else {
      if (!(await confirmDialog('Exportar documentos', `Exportar para um ZIP os ${total} documento(s) que cumprem os filtros atuais?`, 'Exportar'))) return;
      params = { ...filters };
    }
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = 'A preparar o ZIP…';
    try {
      const name = await api.download(action, params, 'documentos.zip');
      toast(`ZIP descarregado: ${name}`, 'good');
    } catch (err) { toast(err.message, 'bad'); }
    btn.disabled = false;
    btn.textContent = old;
  };

  boxes.forEach((b) => {
    b.closest('td').addEventListener('click', (e) => e.stopPropagation());
    b.onchange = () => { if (b.checked) sel.add(b.dataset.sel); else sel.delete(b.dataset.sel); refresh(); };
  });
  if (all) all.onchange = () => { boxes.forEach((b) => (all.checked ? sel.add(b.dataset.sel) : sel.delete(b.dataset.sel))); refresh(); };
  refresh();
}
