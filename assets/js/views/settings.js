// Exercícios (criar, bloquear, fechar, abrir seguinte) e Empresa (dados, cópias de segurança, integridade, auditoria).
import { api } from '../api.js';
import { state, setCrumbs, refreshInfo, rerender, cpath } from '../main.js';
import { esc, toast, confirmDialog, formData, fmtDate, modal, money, toCents } from '../util.js';
import { accessCard } from './users.js';

export async function render(ctx) {
  return ctx.section === 'exercicios' ? years(ctx) : company(ctx);
}

async function years({ view }) {
  setCrumbs('Exercícios e fecho');
  const info = await refreshInfo();
  view.innerHTML = `
    <div class="page-head"><div><h1>Exercícios e fecho</h1><p class="muted">O fecho gera o apuramento de resultados (classes 6 e 7 → 811 → 818) e bloqueia o exercício. A abertura do seguinte transporta os saldos do balanço e passa o resultado para 56.</p></div>
      <div class="toolbar"><button id="new" data-admin>Novo exercício</button></div></div>
    <div class="card"><div class="table-wrap" tabindex="0" role="region" aria-label="Exercícios"><table><thead><tr><th>Exercício</th><th>Período</th><th>Estado</th><th>Bloqueado até</th><th class="num">Lançamentos</th><th></th></tr></thead><tbody>
      ${info.years.map((y) => `<tr><td><b>${y.year}</b></td><td>${fmtDate(y.start_date)} a ${fmtDate(y.end_date)}</td>
        <td>${y.status === 'aberto' ? '<span class="badge good">Aberto</span>' : '<span class="badge">Fechado</span>'}</td>
        <td>${y.locked_until ? fmtDate(y.locked_until) : '—'}</td><td class="num">${y.entries}</td>
        <td class="num">${y.status === 'aberto' ? `<button data-admin data-lock="${y.year}">Bloquear período</button> <button data-admin data-close="${y.year}" class="primary">Fechar exercício</button>` : `<button data-admin data-open="${y.year}">Abrir ${Number(y.year) + 1}</button>`}</td></tr>`).join('')}
    </tbody></table></div></div>
    <div class="card" id="inv"><div class="loading">A carregar…</div></div>`;
  inventoryCard(view.querySelector('#inv'), info.years.find((y) => Number(y.year) === Number(state.year)));
  const act = (sel, fn) => view.querySelectorAll(sel).forEach((b) => { b.onclick = async () => { try { await fn(b); } catch (err) { toast(err.message, 'bad'); } }; });
  view.querySelector('#new').onclick = () => modal({
    title: 'Novo exercício', body: '<div class="form"><label>Ano<input name="year" type="number" value="' + (Math.max(...info.years.map((y) => Number(y.year))) + 1) + '"></label></div>',
    actions: [{ label: 'Cancelar' }, { label: 'Criar', primary: true, onClick: async (el) => { await api.post('years.create', formData(el)); toast('Exercício criado.', 'good'); await rerender(); } }],
  });
  act('[data-lock]', async (b) => modal({
    title: `Bloquear período de ${b.dataset.lock}`,
    body: '<p class="muted">Depois de entregar uma declaração, bloqueie o período para impedir novos lançamentos nessas datas.</p><div class="form"><label>Bloquear até (inclusive)<input type="date" name="until"></label></div>',
    actions: [{ label: 'Cancelar' }, { label: 'Bloquear', primary: true, onClick: async (el) => { await api.post('years.lock', { year: b.dataset.lock, ...formData(el) }); toast('Período bloqueado.', 'good'); await rerender(); } }],
  }));
  act('[data-close]', async (b) => {
    if (!(await confirmDialog(`Fechar o exercício ${b.dataset.close}`, 'São criados os lançamentos de apuramento e o exercício fica fechado. Esta operação não se desfaz. Faça antes uma cópia de segurança.', 'Fechar exercício'))) return;
    await api.post('years.close', { year: b.dataset.close });
    toast('Exercício fechado.', 'good');
    await rerender();
  });
  act('[data-open]', async (b) => {
    const r = await api.post('years.open_next', { year: b.dataset.open });
    toast(`Exercício ${r.year} aberto${r.entry ? ' com lançamento de abertura' : ''}.`, 'good');
    state.year = r.year;
    localStorage.setItem('ideia.year.' + state.company, r.year);
    await refreshInfo();
    await rerender();
  });
}

async function company({ view }) {
  setCrumbs('Empresa e cópias');
  const info = await refreshInfo();
  const [chain, audit, series, salesChain] = await Promise.all([api.get('chain.verify'), api.get('audit.list'), api.get('sales.series'), api.get('sales.verify')]);
  view.innerHTML = `
    <div class="page-head"><div><h1>Empresa e cópias</h1><p class="muted">Ficheiro: <span class="mono">${esc(info.path)}</span></p></div></div>
    <div class="grid cols-2">
      <div class="card"><h2>Dados da empresa</h2><div class="form" id="f">
        <label class="wide">Nome<input name="name" value="${esc(info.name || '')}"></label>
        <label>NIF<input name="nif" class="mono" maxlength="9" value="${esc(info.nif || '')}"></label>
        <label>Regime<select name="regime"><option value="contabilidade_organizada" ${info.regime === 'contabilidade_organizada' ? 'selected' : ''}>Contabilidade organizada</option><option value="rempe" ${info.regime === 'rempe' ? 'selected' : ''}>REMPE</option></select></label>
        <label class="wide">Morada<input name="address" value="${esc(info.address || '')}"></label>
        <label>Cidade<input name="city" value="${esc(info.city || '')}"></label>
        <label>Telefone<input name="phone" value="${esc(info.phone || '')}"></label>
        <label>Email<input name="email" type="email" value="${esc(info.email || '')}"></label>
        <label class="wide">IBAN / NIB (aparece nas faturas)<input name="bank" value="${esc(info.bank || '')}"></label>
        <label class="wide">Rodapé dos documentos de venda<input name="sales_footer" value="${esc(info.sales_footer || '')}" placeholder="Ex.: Capital social 1 000 000 ECV · Matrícula n.º …"></label></div>
        <div class="toolbar" style="margin-top:12px"><button class="primary" id="save" data-admin>Guardar</button></div>
        <h3 style="margin-top:16px">Logótipo nas faturas</h3>
        <div class="toolbar">${info.logo ? `<img src="${esc(info.logo)}" alt="Logótipo atual" style="max-height:48px;max-width:160px;border:1px solid var(--border);border-radius:6px;padding:4px;background:#fff">` : '<span class="muted small">Sem logótipo.</span>'}
          <label class="btn">Escolher imagem<input type="file" id="logo" accept="image/png,image/jpeg,image/webp" hidden></label>
          ${info.logo ? '<button id="logo-rm" data-admin>Retirar</button>' : ''}<span class="muted small">PNG ou JPG, até 300 KB</span></div></div>
      <div class="card"><h2>Cópias de segurança e mudança de computador</h2>
        <p class="muted">A cópia é um ficheiro <span class="mono">.ideia</span> completo e verificado. Para usar noutro computador (PC ou Mac), instale a aplicação, vá a Empresas → "Abrir cópia" e escolha o ficheiro.</p>
        <div class="toolbar"><a class="btn primary" href="${api.href('backup.download')}">Descarregar cópia (.ideia)</a><button id="backup">Guardar cópia na pasta de cópias</button></div>
        <div class="alert info small" style="margin-top:12px">Não trabalhe com o ficheiro dentro de pastas sincronizadas (Google Drive, OneDrive, Dropbox): guarde lá apenas as cópias.</div>
        <h3 style="margin-top:16px">Integridade</h3>
        ${chain.ok ? `<div class="alert good">Cadeia de integridade válida: ${chain.count} lançamento(s) validados verificados.</div>` : `<div class="alert bad">A cadeia de integridade está quebrada no lançamento ${chain.broken_at}. O ficheiro pode ter sido alterado fora da aplicação.</div>`}
      </div>
    </div>
    <div class="card" id="access"></div>
    <div class="card"><h2>Numeração dos documentos de venda</h2>
      <p class="muted small">Cada tipo de documento tem a sua numeração, que recomeça em cada ano (série). Os números são atribuídos na emissão, por ordem, sem saltos.</p>
      ${series.length ? `<div class="table-wrap"><table><thead><tr><th>Documento</th><th>Série</th><th class="num">Último número</th><th>Última data</th></tr></thead><tbody>
        ${series.map((s) => `<tr><td>${esc(s.name)} (${esc(s.type)})</td><td>${esc(s.series)}</td><td class="num">${s.last_number}</td><td>${esc(s.last_date || '')}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Ainda não foi emitido nenhum documento de venda.</p>'}
      ${salesChain.documents ? (salesChain.ok ? `<div class="alert good small">Numeração e assinaturas dos ${salesChain.documents} documentos emitidos estão corretas.</div>` : `<div class="alert bad small">${salesChain.errors.map(esc).join('<br>')}</div>`) : ''}
    </div>
    <div class="card"><h2>Registo de auditoria <span class="muted small">últimas 300 operações</span></h2><div class="table-wrap" style="max-height:420px;overflow:auto" tabindex="0" role="region" aria-label="Registo de auditoria"><table>
      <thead><tr><th>Data</th><th>Utilizador</th><th>Operação</th><th>Detalhes</th></tr></thead><tbody>
      ${audit.map((a) => `<tr><td class="small">${esc(a.ts)}</td><td class="small">${esc(a.user || '—')}</td><td>${esc(a.action)}</td><td class="small mono">${esc(a.details || '')}</td></tr>`).join('')}</tbody></table></div></div>`;
  view.querySelector('#save').onclick = async () => {
    try { await api.post('company.update', formData(view.querySelector('#f'))); toast('Dados guardados.', 'good'); await refreshInfo(); } catch (err) { toast(err.message, 'bad'); }
  };
  accessCard(view.querySelector('#access'), () => company({ view }));
  view.querySelector('#logo').onchange = async (e) => {
    const f = e.target.files[0];
    if (!f) return;
    try { await api.upload('company.logo', f); toast('Logótipo guardado.', 'good'); company({ view }); } catch (err) { toast(err.message, 'bad'); }
  };
  view.querySelector('#logo-rm')?.addEventListener('click', async () => { await api.post('company.logo', { remove: true }); toast('Logótipo retirado.'); company({ view }); });
  view.querySelector('#backup').onclick = async () => {
    try { const r = await api.post('backup.create'); toast('Cópia guardada em ' + r.file, 'good'); } catch (err) { toast(err.message, 'bad'); }
  };
}

/** Inventário periódico: compras (31) → inventário (32/33) → custo das mercadorias vendidas (611/612). */
async function inventoryCard(box, fy, date = '') {
  if (!fy) { box.remove(); return; }
  const y = Number(fy.year);
  let st;
  try { st = await api.get('inventory.status', { year: y, date }); } catch (err) { box.innerHTML = `<div class="alert bad">${esc(err.message)}</div>`; return; }
  const kinds = Object.values(st.kinds).filter((k) => k.purchases || k.stock || k.cost_ytd);
  const open = fy.status === 'aberto';
  const last = st.postings[st.postings.length - 1];
  box.innerHTML = `<h2>Inventário e custo das mercadorias vendidas · ${y}</h2>
    <p class="muted">As faturas de fornecedor de mercadorias e matérias ficam na conta 31 (Compras). Para a margem bruta e a demonstração de resultados ficarem certas, conte o inventário e lance o apuramento: as compras passam para o inventário (32/33) e o que foi vendido ou consumido passa para gasto (611/612), com a fórmula <b>inventário inicial + compras − inventário final</b>. Pode fazê-lo todos os meses ou só no fim do ano; é obrigatório antes de fechar o exercício.</p>
    ${kinds.length ? `${open ? `<div class="form" style="max-width:320px"><label>Data da contagem<input type="date" name="date" value="${esc(st.date)}" min="${esc(fy.start_date)}" max="${esc(fy.end_date)}"></label></div>` : ''}
      <div class="table-wrap" tabindex="0" role="region" aria-label="Apuramento do CMVMC"><table><thead><tr><th>Natureza</th><th class="num">Inventário na contabilidade</th><th class="num">Compras por passar ao inventário</th>${open ? '<th class="num">Inventário final contado</th><th class="num">Custo a lançar</th>' : ''}<th class="num">Custo já lançado no ano</th></tr></thead><tbody>
      ${kinds.map((k) => `<tr data-k="${k.key}" data-stock="${k.stock}" data-purchases="${k.purchases}"><td>${esc(k.label)}<div class="small muted mono">${k.accounts.purchases} → ${k.accounts.stock} → ${k.accounts.cost}</div></td>
        <td class="num">${money(k.stock)}</td><td class="num">${money(k.purchases)}</td>
        ${open ? `<td class="num"><input name="final_${k.key}" class="num" inputmode="decimal" aria-label="Inventário final de ${esc(k.label)}" placeholder="0,00" style="max-width:140px"></td><td class="num cost"><span class="muted">—</span></td>` : ''}
        <td class="num">${money(k.cost_ytd)}</td></tr>`).join('')}</tbody></table></div>
      ${open ? `<div class="toolbar" style="margin-top:10px"><button class="primary" id="inv-post" data-admin>Lançar apuramento</button><span class="small muted">Indique o valor contado de cada natureza (vazio = nada em armazém). Lançamento validado no diário de operações diversas, com a data da contagem.</span></div>` : ''}`
      : '<p class="empty">Sem compras de mercadorias ou matérias (contas 311 e 312) neste exercício. Se só presta serviços, não precisa de fazer nada aqui.</p>'}
    ${st.postings.length ? `<h3 style="margin-top:14px">Apuramentos de ${y}</h3><table><tbody>${st.postings.map((p) => `<tr><td>${fmtDate(p.date)}</td><td><a href="#/${cpath('lancamento/' + p.id)}">${esc(p.journal)} / ${p.number}</a></td><td class="num">custo ${money(p.cost_cents)}</td>
      <td class="num">${open && p === last ? `<button data-admin data-inv-undo="${p.id}">Anular</button>` : ''}</td></tr>`).join('')}</tbody></table>` : ''}`;
  box.querySelector('[name=date]')?.addEventListener('change', (e) => inventoryCard(box, fy, e.target.value));
  const recalc = () => box.querySelectorAll('tr[data-k]').forEach((tr) => {
    const v = tr.querySelector('input')?.value || '';
    const c = v ? toCents(v) : 0;
    const cell = tr.querySelector('.cost');
    if (!cell) return;
    if (!v) { cell.innerHTML = '<span class="muted">—</span>'; return; }
    const cost = Number(tr.dataset.stock) + Number(tr.dataset.purchases) - c;
    cell.innerHTML = Number.isNaN(c) ? '<span class="neg">valor inválido</span>' : `<span class="${cost < 0 ? 'neg' : ''}">${money(cost)}</span>`;
  });
  box.querySelectorAll('tr[data-k] input').forEach((i) => i.addEventListener('input', recalc));
  box.querySelector('#inv-post')?.addEventListener('click', async () => {
    const body = { year: y, date: box.querySelector('[name=date]').value };
    box.querySelectorAll('tr[data-k] input').forEach((i) => { body[i.name] = i.value; });
    try {
      const p = await api.get('inventory.preview', body);
      if (!(await confirmDialog('Lançar apuramento do CMVMC', `Em ${fmtDate(p.date)}: ${p.rows.map((r) => `${r.label.toLowerCase()} — custo ${money(r.cost)} (inventário final ${money(r.final)})`).join('; ')}.`, 'Lançar'))) return;
      await api.post('inventory.post', body);
      toast('Apuramento lançado.', 'good');
      await refreshInfo();
      inventoryCard(box, fy);
    } catch (err) { toast(err.message, 'bad'); }
  });
  box.querySelector('[data-inv-undo]')?.addEventListener('click', async (e) => {
    if (!(await confirmDialog('Anular o apuramento?', 'O lançamento é estornado e as compras voltam a ficar por passar ao inventário.', 'Anular'))) return;
    try { await api.post('inventory.unpost', { year: y, entry: e.target.dataset.invUndo }); toast('Apuramento anulado.'); inventoryCard(box, fy); } catch (err) { toast(err.message, 'bad'); }
  });
}

