# 17. Utilizadores e perfis

Por omissão a aplicação abre sem palavra-passe (um computador, uma pessoa). Em **Dados → Empresa e cópias → Utilizadores e acessos** pode ativar os acessos: cria-se o primeiro utilizador, que é sempre contabilista (administrador). Código em `src/Auth.php` e `assets/js/views/users.js`.

## Perfis

| Perfil | Pode |
|--------|------|
| Contabilista (administrador) | Tudo, incluindo utilizadores, dados da empresa, plano de contas, exercícios (criar, bloquear, fechar), apuramento do IVA, importações, configuração da e-Fatura, depreciações e abates |
| Colaborador | Lançamentos, vendas, compras, bancos, imobilizado (fichas) e envio para a e-Fatura. Não gere utilizadores, a empresa, o plano de contas nem fecha exercícios |
| Só leitura | Consulta relatórios, listas e documentos, imprime e exporta. Não altera nada |

As permissões são verificadas no servidor (`Api::authorize`) antes de abrir a empresa; o menu do browser é só uma ajuda. Há sempre pelo menos um contabilista ativo.

## Entrada e sessão

- Utilizador e palavra-passe (o browser lembra o último utilizador). Com um só utilizador, o nome pode ficar vazio.
- Palavras-passe com hash (`password_hash`), sessão HttpOnly/SameSite, fecho após 4 h sem uso, bloqueio de 5 minutos após 5 tentativas falhadas.
- Cada utilizador pode mudar a sua palavra-passe. O contabilista cria, altera (perfil, nome, ativo, nova palavra-passe) e apaga utilizadores, e pode **desligar os acessos** (apaga todos os utilizadores e volta a abrir sem palavra-passe).

## Registo de autor

Cada operação do registo de auditoria (`audit_log.user`, esquema v11) guarda quem a fez. A tabela de auditoria em **Empresa e cópias** mostra a coluna **Utilizador**. Sem acessos ativos fica vazio.

## Ficheiro

`<pasta de dados>/acesso.json` (fechado ao browser pelo `.htaccess`): `{users: [{username, name, role, hash, active, created_at, last_login, failures, locked_until}]}`. O formato antigo (`{hash}`, só palavra-passe) é lido como o utilizador `admin`, contabilista.

Os utilizadores são da instalação (todas as empresas da pasta de dados), não de cada empresa.
