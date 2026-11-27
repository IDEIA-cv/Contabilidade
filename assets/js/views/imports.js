// Importar (SAF-T, Excel/CSV) e exportar (SAF-T CV).
import { api } from '../api.js';
import { state, cpath, setCrumbs, refreshInfo, go } from '../main.js';
import { invalidateRefs } from './entries.js';
import { esc, money, toast } from '../util.js';

export async function render({ view }) {
  setCrumbs('Importar e exportar');
  const logs = await api.get('imports.list');
  view.innerHTML = `
    <div class="page-head"><div><h1>Importar e exportar</h1><p class="muted">Migre o histórico de outros programas. Nada é gravado sem pré-visualização e, havendo erros, nada é gravado.</p></div></div>
    <div class="grid cols-2">
      <div class="card"><h2>1. SAF-T de contabilidade</h2>
        <p class="muted">Ficheiro XML exportado do Primavera, PHC, Sage, Vendus ou outro programa (SAF-T CV ou PT). Importa contas, clientes, fornecedores e todos os lançamentos de um exercício.</p>
        <input type="file" id="saft-file" accept=".xml,.gz">
        <div class="toolbar" style="margin-top:10px"><button class="primary" id="saft-preview">Analisar ficheiro</button></div>
        <div id="saft-out" style="margin-top:12px"></div></div>
      <div class="card"><h2>2. Folhas Excel (CSV)</h2>
        <p class="muted">Guarde a folha como CSV (separador ponto e vírgula) a partir dos modelos. Importe por esta ordem: contas, terceiros, saldos de abertura, lançamentos.</p>
        <div class="form">
          <label>Tipo<select id="csv-type"><option value="contas">Plano de contas</option><option value="terceiros">Clientes e fornecedores</option><option value="saldos">Saldos de abertura</option><option value="lancamentos">Lançamentos</option></select></label>
          <label id="csv-year-wrap">Exercício (saldos)<select id="csv-year">${state.info.years.map((y) => `<option ${Number(y.year) === state.year ? 'selected' : ''}>${y.year}</option>`).join('')}</select></label>
          <label>Lançamentos importados<select id="csv-validate"><option value="1">Validados</option><option value="0">Como rascunho</option></select></label>
          <label>Modelo<a class="btn" id="csv-template" href="#">Descarregar modelo</a></label>
          <label class="wide">Ficheiro<input type="file" id="csv-file" accept=".csv,.txt"></label>
        </div>
        <div class="toolbar" style="margin-top:10px"><button id="csv-preview">Pré-visualizar</button><button class="primary" id="csv-import">Importar</button></div>
        <div id="csv-out" style="margin-top:12px"></div></div>
    </div>
    <div class="grid cols-2">
      <div class="card"><h2>Exportar SAF-T (CV) de contabilidade</h2>
        <p class="muted">Gera o ficheiro de contabilidade (C) do exercício. Valide-o no SAFTPRO (saft.efatura.cv) antes de o entregar.</p>
        <div class="alert warn small">A estrutura segue o modelo OCDE usado pelo SAF-T CV; o namespace oficial ainda tem de ser confirmado com o XSD da DNRE.</div>
        <div class="toolbar"><label>Exercício<select id="saft-year">${state.info.years.map((y) => `<option ${Number(y.year) === state.year ? 'selected' : ''}>${y.year}</option>`).join('')}</select></label>
          <a class="btn primary" id="saft-export" href="#">Descarregar SAF-T</a></div></div>
      <div class="card"><h2>Histórico de importações</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Origem</th><th>Ficheiro</th><th>Resumo</th></tr></thead><tbody>
        ${logs.map((l) => `<tr><td>${esc(l.ts)}</td><td>${esc(l.source)}</td><td>${esc(l.filename || '')}</td><td class="small mono">${esc(l.summary || '')}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">Sem importações.</td></tr>'}</tbody></table></div></div>
    </div>`;

  // ---------------- SAF-T
  const saftOut = view.querySelector('#saft-out');
  view.querySelector('#saft-preview').onclick = async () => {
    const f = view.querySelector('#saft-file').files[0];
    if (!f) return toast('Escolha o ficheiro SAF-T.', 'bad');
    saftOut.innerHTML = '<div class="loading">A analisar…</div>';
    try {
      const p = await api.upload('saft.preview', f);
      saftOut.innerHTML = `<table><tbody>
        <tr><td>Empresa</td><td><b>${esc(p.company)}</b> · NIF ${esc(p.nif)}</td></tr>
        <tr><td>Programa de origem</td><td>${esc(p.product || '—')} · versão SAF-T ${esc(p.version)}</td></tr>
        <tr><td>Exercício</td><td>${p.year} (${esc(p.start)} a ${esc(p.end)})</td></tr>
        <tr><td>Lançamentos</td><td>${p.transactions} em ${p.journals.length} diário(s)</td></tr>
        <tr><td>Totais</td><td>débito ${money(p.total_debit)} · crédito ${money(p.total_credit)}</td></tr>
        <tr><td>Contas novas</td><td>${p.new_accounts.length} ${p.new_accounts.length ? `<details><summary class="small">ver</summary><div class="small mono">${p.new_accounts.map((a) => esc(a.code + ' ' + a.name)).join('<br>')}</div></details>` : ''}</td></tr>
        <tr><td>Terceiros</td><td>${p.customers} clientes, ${p.suppliers} fornecedores</td></tr></tbody></table>
        ${p.warnings.map((w) => `<div class="alert warn small">${esc(w)}</div>`).join('')}
        ${p.conflicts.length ? `<h3 style="margin-top:12px">Contas a mapear</h3><p class="muted small">Estas contas têm subcontas no plano atual. Indique a conta de movimento de destino.</p>
          ${p.conflicts.map((c) => `<div class="form"><label>${esc(c.code)} ${esc(c.name)}<input class="mono" data-map="${esc(c.code)}" placeholder="ex.: ${esc(c.code)}8"></label></div>`).join('')}` : ''}
        ${p.opening_total ? `<label style="display:flex;gap:8px;margin-top:12px"><input type="checkbox" id="saft-opening" ${p.suggest_opening ? 'checked' : ''}> Criar lançamento de abertura a partir dos saldos iniciais das contas</label>` : ''}
        <div class="toolbar" style="margin-top:12px"><button class="primary" id="saft-commit" ${p.can_import ? '' : 'disabled'}>Importar ${p.transactions} lançamentos</button></div>`;
      const btn = saftOut.querySelector('#saft-commit');
      btn.onclick = async () => {
        btn.disabled = true;
        const map = {};
        saftOut.querySelectorAll('[data-map]').forEach((i) => { map[i.dataset.map] = i.value.trim(); });
        try {
          const r = await api.upload('saft.import', f, { map, create_opening: saftOut.querySelector('#saft-opening')?.checked ? '1' : '0' });
          toast(`Importados ${r.entries} lançamentos de ${r.year}.`, 'good');
          invalidateRefs();
          await refreshInfo();
          go(cpath('relatorios/balancete'));
        } catch (err) { toast(err.message, 'bad'); btn.disabled = false; }
      };
    } catch (err) { saftOut.innerHTML = `<div class="alert bad">${esc(err.message)}</div>`; }
  };

  // ---------------- CSV
  const type = view.querySelector('#csv-type');
  const tpl = view.querySelector('#csv-template');
  const syncType = () => {
    tpl.href = api.href('import.template', { type: type.value });
    view.querySelector('#csv-year-wrap').style.display = type.value === 'saldos' ? '' : 'none';
  };
  type.onchange = syncType;
  syncType();
  const csvOut = view.querySelector('#csv-out');
  const runCsv = async (commit) => {
    const f = view.querySelector('#csv-file').files[0];
    if (!f) return toast('Escolha o ficheiro CSV.', 'bad');
    csvOut.innerHTML = '<div class="loading">A processar…</div>';
    try {
      const r = await api.upload(commit ? 'csv.import' : 'csv.preview', f, { type: type.value, year: view.querySelector('#csv-year').value, validate: view.querySelector('#csv-validate').value });
      const cols = r.columns;
      csvOut.innerHTML = `
        ${commit ? (r.committed ? `<div class="alert good">Importação concluída: ${r.imported} registo(s).</div>` : '<div class="alert bad">Há erros: nada foi gravado. Corrija o ficheiro e repita.</div>')
          : `<div class="alert ${r.errors.length ? 'warn' : 'good'}">${r.rows} linha(s) lidas${r.entries !== undefined ? `, ${r.entries} lançamento(s)` : ''}. ${r.errors.length ? r.errors.length + ' erro(s).' : 'Sem erros: pode importar.'}</div>`}
        ${r.errors.length ? `<div class="table-wrap"><table><thead><tr><th>Linha</th><th>Erro</th></tr></thead><tbody>${r.errors.slice(0, 100).map((e) => `<tr><td>${e.line || '—'}</td><td>${esc(e.message)}</td></tr>`).join('')}</tbody></table></div>` : ''}
        <h3 style="margin-top:12px">Primeiras linhas</h3><div class="table-wrap"><table><thead><tr>${cols.map((c) => `<th>${esc(c)}</th>`).join('')}</tr></thead>
        <tbody>${r.sample.map((s) => `<tr>${cols.map((c) => `<td>${esc(s[c])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
      if (commit && r.committed) { invalidateRefs(); await refreshInfo(); }
    } catch (err) { csvOut.innerHTML = `<div class="alert bad">${esc(err.message)}</div>`; }
  };
  view.querySelector('#csv-preview').onclick = () => runCsv(false);
  view.querySelector('#csv-import').onclick = () => runCsv(true);

  // ---------------- exportar
  const ey = view.querySelector('#saft-year');
  const exp = view.querySelector('#saft-export');
  const syncExp = () => { exp.href = api.href('saft.export', { year: ey.value }); };
  ey.onchange = syncExp;
  syncExp();
}
