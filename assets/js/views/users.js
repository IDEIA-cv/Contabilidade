// Utilizadores e acessos (cartão na página Empresa e cópias): ligar os acessos, gerir utilizadores e perfis,
// mudar a própria palavra-passe, desligar. As permissões são verificadas no servidor (Api::authorize).
import { api } from '../api.js';
import { esc, toast, modal, confirmDialog, formData } from '../util.js';
import { applyAuth } from '../main.js';

const ROLE_HELP = {
  admin: 'Tudo, incluindo utilizadores, dados da empresa, plano de contas, exercícios e importações.',
  colaborador: 'Lançamentos, vendas, compras, bancos e e-Fatura. Não gere utilizadores, a empresa, o plano de contas nem fecha exercícios.',
  leitura: 'Consulta relatórios, listas e documentos, imprime e exporta. Não altera nada.',
};

export async function accessCard(box, onChange) {
  const auth = await api.get('auth.status');
  if (!auth.enabled) {
    box.innerHTML = `<h2>Utilizadores e acessos</h2>
      <p class="muted small">Hoje a aplicação abre sem palavra-passe. Para que cada pessoa entre com o seu nome e tenha um perfil (contabilista, colaborador ou só leitura), crie primeiro o seu utilizador de administrador.</p>
      <div class="form" id="setup">
        <label>Nome de utilizador<input name="username" autocomplete="username" placeholder="ex.: ana"></label>
        <label>Nome completo<input name="name" autocomplete="name"></label>
        <label>Palavra-passe<input type="password" name="password" autocomplete="new-password" placeholder="pelo menos 6 caracteres"></label>
        <label>Repetir<input type="password" name="password2" autocomplete="new-password"></label></div>
      <div class="toolbar" style="margin-top:10px"><button class="primary" id="setup-go">Ativar acessos</button></div>`;
    box.querySelector('#setup-go').onclick = async () => {
      const f = formData(box.querySelector('#setup'));
      if (f.password !== f.password2) return toast('As duas palavras-passe não são iguais.', 'bad');
      try {
        await api.post('auth.setup', f);
        toast('Acessos ativos. Guarde a palavra-passe num local seguro.', 'good');
        await applyAuth();
        onChange();
      } catch (err) { toast(err.message, 'bad'); }
    };
    return;
  }
  const me = auth.user;
  const isAdmin = auth.role === 'admin';
  const data = isAdmin ? await api.get('auth.users') : null;
  box.innerHTML = `<h2>Utilizadores e acessos</h2>
    <p class="small">Entrou como <b>${esc(me.name)}</b> (${esc(me.username)}) · perfil <b>${esc(me.role_name)}</b>.</p>
    ${isAdmin ? `<div class="table-wrap"><table id="users"><caption class="sr-only">Utilizadores</caption><thead><tr><th>Utilizador</th><th>Nome</th><th>Perfil</th><th>Última entrada</th><th></th></tr></thead><tbody>
      ${data.users.map((u) => `<tr><td class="mono">${esc(u.username)}${u.active ? '' : ' <span class="badge">Desativado</span>'}</td><td>${esc(u.name)}</td><td>${esc(u.role_name)}</td><td class="small">${esc(u.last_login || '—')}</td>
        <td><button data-edit="${esc(u.username)}">Alterar</button>${u.username !== me.username ? ` <button class="danger" data-del="${esc(u.username)}">Apagar</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
      <div class="toolbar" style="margin-top:10px"><button class="primary" id="u-new">Novo utilizador</button><button id="pw-mine">Mudar a minha palavra-passe</button><button class="danger" id="acc-off">Desligar acessos</button></div>
      <ul class="small muted" style="margin-top:10px">${Object.entries(data.roles).map(([k, r]) => `<li><b>${esc(r.name)}</b>: ${esc(r.help || ROLE_HELP[k])}</li>`).join('')}</ul>`
      : `<p class="muted small">${esc(ROLE_HELP[auth.role] || '')} Os utilizadores são geridos pelo administrador.</p><div class="toolbar"><button id="pw-mine">Mudar a minha palavra-passe</button></div>`}`;

  box.querySelector('#pw-mine').onclick = () => modal({
    title: 'Mudar a minha palavra-passe',
    body: `<div class="form"><label class="wide">Palavra-passe atual<input type="password" name="current" autocomplete="current-password"></label>
      <label>Nova palavra-passe<input type="password" name="password" autocomplete="new-password" placeholder="pelo menos 6 caracteres"></label><label>Repetir<input type="password" name="password2" autocomplete="new-password"></label></div>`,
    actions: [{ label: 'Cancelar' }, { label: 'Guardar', primary: true, onClick: async (el) => {
      const f = formData(el);
      if (f.password !== f.password2) throw new Error('As duas palavras-passe não são iguais.');
      await api.post('auth.password', f);
      toast('Palavra-passe alterada.', 'good');
    } }],
  });
  if (!isAdmin) return;

  const userDialog = (u) => {
    const roles = Object.entries(data.roles);
    modal({
      title: u ? `Alterar ${u.username}` : 'Novo utilizador',
      body: `<div class="form">
        <label>Nome de utilizador<input name="username" value="${esc(u?.username || '')}" ${u ? 'readonly' : ''} autocomplete="off" placeholder="sem espaços, ex.: rui"></label>
        <label>Nome completo<input name="name" value="${esc(u?.name || '')}"></label>
        <label class="wide">Perfil<select name="role">${roles.map(([k, r]) => `<option value="${k}" ${(u?.role || 'colaborador') === k ? 'selected' : ''}>${esc(r.name)}</option>`).join('')}</select></label>
        <p class="small muted wide" id="role-help"></p>
        <label>${u ? 'Nova palavra-passe (vazio = manter)' : 'Palavra-passe'}<input type="password" name="password" autocomplete="new-password" placeholder="pelo menos 6 caracteres"></label>
        ${u ? `<label class="check"><input type="checkbox" name="active" ${u.active ? 'checked' : ''}> Ativo (pode entrar)</label>` : ''}</div>`,
      actions: [{ label: 'Cancelar' }, { label: 'Guardar', primary: true, onClick: async (el) => {
        const f = formData(el);
        if (u) { f.existing = 1; f.active = el.querySelector('[name=active]').checked ? 1 : 0; }
        await api.post('auth.user_save', f);
        toast('Utilizador guardado.', 'good');
        onChange();
      } }],
    });
    const sel = document.querySelector('.modal [name=role]');
    const help = document.querySelector('.modal #role-help');
    const sync = () => { help.textContent = ROLE_HELP[sel.value] || ''; };
    sel.onchange = sync;
    sync();
  };
  box.querySelector('#u-new').onclick = () => userDialog(null);
  box.querySelectorAll('[data-edit]').forEach((b) => { b.onclick = () => userDialog(data.users.find((u) => u.username === b.dataset.edit)); });
  box.querySelectorAll('[data-del]').forEach((b) => {
    b.onclick = async () => {
      if (!await confirmDialog('Apagar utilizador?', `O utilizador ${esc(b.dataset.del)} deixa de poder entrar. O que já fez continua no registo de auditoria.`, 'Apagar')) return;
      try { await api.post('auth.user_delete', { username: b.dataset.del }); toast('Utilizador apagado.'); onChange(); } catch (err) { toast(err.message, 'bad'); }
    };
  });
  box.querySelector('#acc-off').onclick = () => modal({
    title: 'Desligar acessos?',
    body: '<p>Todos os utilizadores são apagados e a aplicação volta a abrir sem palavra-passe neste computador.</p><div class="form"><label class="wide">A sua palavra-passe<input type="password" name="password" autocomplete="current-password"></label></div>',
    actions: [{ label: 'Cancelar' }, { label: 'Desligar acessos', danger: true, onClick: async (el) => {
      await api.post('auth.disable', formData(el));
      toast('Acessos desligados.');
      await applyAuth();
      onChange();
    } }],
  });
}
