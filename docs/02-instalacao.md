# 2. Instalação e configuração

## Requisitos

- PHP **8.0 ou superior** com a extensão **pdo_sqlite** (vem ativa no XAMPP, MAMP e na maioria das instalações).
- Um browser recente (Chrome, Edge, Firefox ou Safari).
- Não precisa de MySQL, Node.js nem internet.

## Opção recomendada — versão portátil (sem instalar nada)

É a forma pensada para os utilizadores: **copiar uma pasta e abrir no browser**. O pacote traz o PHP dentro, por isso não precisa de XAMPP nem de instalar nada.

1. Descarregue o zip do seu sistema na página de lançamentos do GitHub (`IDEIA-Contabilidade-<versão>-windows.zip`, `-mac.zip` ou `-todos.zip`). O pré-lançamento **portatil-develop** é refeito a cada alteração no ramo develop.
2. Descompacte para qualquer pasta (Documentos, Ambiente de trabalho, pen USB).
3. Abra o programa:
   - **Windows:** duplo clique em `Abrir IDEIA.bat`.
   - **Mac:** duplo clique em `Abrir IDEIA.command`. Na primeira vez o macOS bloqueia ficheiros descarregados: botão direito → **Abrir** → confirmar. O lançador retira a marca de quarentena da pasta para as vezes seguintes.
   - **Linux:** `./abrir-ideia.sh`.

Abre-se uma janela de consola (o servidor) e o browser em `http://127.0.0.1:<porta>/`. Fechar a janela fecha o programa.

Estrutura do pacote:

```
IDEIA Contabilidade/
  Abrir IDEIA.bat / Abrir IDEIA.command / abrir-ideia.sh
  LEIA-ME.txt
  app/      a aplicação (substitui-se nas atualizações)
  php/      PHP portátil: windows/, mac-arm64/, mac-x64/, linux-x64/
  dados/    empresas .ideia, backups/, acesso.json (nunca se substitui)
```

Como funciona (`tools/portable/`):

- `launcher.php` escolhe a primeira porta livre entre 8765 e 8799, arranca `php -S 127.0.0.1:<porta>` com `router.php`, espera que responda ao `ping` e abre o browser. Se o programa já estiver aberto com a mesma pasta de dados, só abre o browser (estado em `dados/.servidor.json`, registo em `dados/servidor.log`).
- A pasta de dados chega à aplicação pela variável de ambiente `IDEIA_DATA_DIR`, que tem prioridade sobre `config.php`.
- O `php -S` não lê `.htaccess`; `router.php` só serve `index.php`, `api.php` e `assets/`, e responde 404 ao resto (`src/`, `data/`, `config.php`...).
- Só escuta em `127.0.0.1`. No Mac e no Linux usa 4 processos (`PHP_CLI_SERVER_WORKERS`).
- PHP incluído: Windows, a versão oficial de windows.php.net (8.3 NTS x64) com o runtime do Visual C++ ao lado; Mac e Linux, os binários estáticos do projeto static-php-cli (extensões "common").

Os zips são montados pelo GitHub Actions (`.github/workflows/portatil.yml`): descarrega o PHP, verifica as extensões, corre os testes com esse PHP e testa o lançador. Localmente: `tools/portable/build.sh <pasta-php> dist`.

Para usar o lançador com o PHP do sistema, a partir do repositório: `php tools/portable/launcher.php` (usa a pasta de dados do `config.php`).

## Opção A — XAMPP no Mac

1. Copie a pasta do projeto para `/Applications/XAMPP/xamppfiles/htdocs/contabilidade`.
2. Dê permissão de escrita ao Apache na pasta de dados (o Apache do XAMPP corre como `daemon`):

   ```bash
   cd /Applications/XAMPP/xamppfiles/htdocs/contabilidade
   sudo chown -R daemon data
   sudo chmod -R u+rwX data
   ```

3. Inicie o Apache no XAMPP e abra <http://localhost/contabilidade/>.

## Opção B — XAMPP no Windows

1. Copie a pasta para `C:\xampp\htdocs\contabilidade`.
2. Inicie o Apache no painel do XAMPP e abra <http://localhost/contabilidade/>.
3. Confirme em `C:\xampp\php\php.ini` que a linha `extension=pdo_sqlite` não está comentada.

## Opção C — servidor embutido do PHP (desenvolvimento)

```bash
php -S 127.0.0.1:8765 tools/portable/router.php
```

Com o router, as pastas internas ficam fechadas como no Apache.

Abra <http://127.0.0.1:8765/>.

## Configuração (`config.php`)

Copie `config.sample.php` para `config.php` (este ficheiro não vai para o Git) e ajuste:

| Chave | Por omissão | Significado |
|-------|-------------|-------------|
| `data_dir` | `./data` | Pasta onde ficam os ficheiros `.ideia` das empresas |
| `backup_dir` | `./data/backups` | Pasta das cópias de segurança |
| `auto_backup` | `true` | Cópia automática diária ao abrir a empresa, só se houve alterações; mantém as 14 mais recentes (`<id>_<data>_auto.ideia`). As cópias manuais nunca são apagadas |
| `allow_remote` | `false` | Se `false`, a API só responde a pedidos de `127.0.0.1`/`::1` |
| `currency` | `CVE` | Moeda (informativa nesta versão) |
| `country` | `CV` | País |

Sem `config.php`, usam-se os valores por omissão.

## Segurança

- `data/.htaccess` nega o acesso direto aos ficheiros das empresas pelo Apache.
- A API recusa pedidos que não venham da própria máquina (a não ser com `allow_remote`) e exige o cabeçalho `X-Requested-With: IDEIA` em todos os POST (proteção contra CSRF de outros sites abertos no browser).
- **Não trabalhe com a pasta `data/` dentro de Google Drive, OneDrive ou Dropbox**: o SQLite pode corromper-se com sincronização a meio de uma escrita. Sincronize apenas as cópias de segurança.

## Empresa de demonstração

```bash
php tools/seed-demo.php            # usa data_dir do config.php
php tools/seed-demo.php /outra/pasta
```

Cria "Empresa Demonstração Lda" com 2024 fechado e 2025 aberto (até outubro), clientes, fornecedores, salários, depreciações, orçamento e indicadores próprios.

## Mudar de computador

1. No computador antigo: Empresa e cópias → **Descarregar cópia (.ideia)**.
2. No novo: instale a aplicação (passos acima) → Empresas → **Abrir cópia** → escolha o ficheiro.

Em alternativa, copie diretamente os ficheiros `data/*.ideia` com a aplicação parada.

## Problemas frequentes

**"attempt to write a readonly database"** ou **"Sem permissão para escrever na pasta de dados"**: o servidor web não consegue escrever na pasta `data/`. No Mac (XAMPP o Apache corre como `daemon`):

```bash
sudo chown -R daemon /Applications/XAMPP/xamppfiles/htdocs/contabilidade/data
sudo chmod -R u+rwX /Applications/XAMPP/xamppfiles/htdocs/contabilidade/data
```

No Windows, nas propriedades da pasta `data`, dê permissão de escrita ao utilizador que corre o Apache. Depois recarregue a página.

## Atualizar a aplicação

Substitua todos os ficheiros **exceto** `config.php` e a pasta `data/`. O esquema da base de dados tem versão (`meta.schema_version`); migrações futuras serão aplicadas ao abrir cada empresa.
