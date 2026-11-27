// Exercícios (criar, bloquear, fechar, abrir seguinte) e Empresa (dados, cópias de segurança, integridade, auditoria).
import { api } from '../api.js';
import { state, setCrumbs, refreshInfo, rerender } from '../main.js';
import { esc, toast, confirmDialog, formData, fmtDate, modal } from '../util.js';
import { accessCard } from './users.js';

export async function render(ctx) {
  return ctx.section === 'exercicios' ? years(ctx) : company(ctx);
}

async function years({ view }) {
  setCrumbs('Exercícios e fecho');
  const info = await refreshInfo();
  view.innerHTML = `
    <div class="page-head"><div><h1>Exercícios e fecho</h1><p class="muted">O fecho gera o apuramento de resultados (classes 6 e 7 → 811 → 818) e bloqueia o exercício. A abertura do seguinte transporta os saldos do balanço e passa o resultado para 56.</p></div>
      <div class="toolbar"><button id="new">Novo exercício</button></div></div>
    <div class="card"><table><thead><tr><th>Exercício</th><th>Período</th><th>Estado</th><th>Bloqueado até</th><th class="num">Lançamentos</th><th></th></tr></thead><tbody>
      ${info.years.map((y) => `<tr><td><b>${y.year}</b></td><td>${fmtDate(y.start_date)} a ${fmtDate(y.end_date)}</td>
        <td>${y.status === 'aberto' ? '<span class="badge good">Aberto</span>' : '<span class="badge">Fechado</span>'}</td>
        <td>${y.locked_until ? fmtDate(y.locked_until) : '—'}</td><td class="num">${y.entries}</td>
        <td class="num">${y.status === 'aberto' ? `<button data-lock="${y.year}">Bloquear período</button> <button data-close="${y.year}" class="primary">Fechar exercício</button>` : `<button data-open="${y.year}">Abrir ${Number(y.year) + 1}</button>`}</td></tr>`).join('')}
    </tbody></table></div>`;
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
        <div class="toolbar" style="margin-top:12px"><button class="primary" id="save">Guardar</button></div>
        <h3 style="margin-top:16px">Logótipo nas faturas</h3>
        <div class="toolbar">${info.logo ? `<img src="${esc(info.logo)}" alt="Logótipo atual" style="max-height:48px;max-width:160px;border:1px solid var(--border);border-radius:6px;padding:4px;background:#fff">` : '<span class="muted small">Sem logótipo.</span>'}
          <label class="btn">Escolher imagem<input type="file" id="logo" accept="image/png,image/jpeg,image/webp" hidden></label>
          ${info.logo ? '<button id="logo-rm">Retirar</button>' : ''}<span class="muted small">PNG ou JPG, até 300 KB</span></div></div>
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
