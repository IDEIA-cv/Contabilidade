# ADR 0001 — PHP + SQLite servido pelo htdocs na fase 1

- **Estado:** aceite (2026-11)
- **Contexto:** o plano de ação propunha uma aplicação de ambiente de trabalho em Tauri (Rust + interface web) com SQLite. No arranque da execução foi pedido criar o projeto numa pasta do `htdocs` (XAMPP) e testá-lo no browser.

## Decisão

Implementar a fase 1 como aplicação web **local**: PHP 8 sem dependências + PDO SQLite + SPA em JavaScript nativo, servida pelo Apache do XAMPP (ou pelo servidor embutido do PHP), acessível só em `localhost`.

## Razões

- Corre já no ambiente que o utilizador tem (XAMPP no Mac/Windows) e testa-se diretamente no browser.
- PHP e SQLite são conhecidos pela maioria dos programadores em Cabo Verde, o que facilita contribuições open-source.
- Mantém os objetivos centrais do plano: offline, base de dados local num ficheiro portátil, multiplataforma.
- O domínio (`src/`) e a interface (`assets/`) não dependem do XAMPP: a API é JSON simples. Empacotar mais tarde num executável (Tauri a chamar um PHP embebido, ou reescrever o domínio em Rust usando os testes como especificação) não obriga a refazer a interface nem o formato dos dados.

## Consequências

- O utilizador final precisa de XAMPP (ou equivalente) até existir um instalador — aceitável para piloto com gabinetes, não para o mercado geral.
- Segurança depende de a API só aceitar pedidos locais (`allow_remote = false`) e do cabeçalho anti-CSRF.
- O formato `.ideia` (SQLite) é o contrato estável entre esta versão e as futuras.
