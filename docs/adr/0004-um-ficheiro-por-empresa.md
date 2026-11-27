# ADR 0004 — Um ficheiro `.ideia` por empresa

- **Estado:** aceite

## Decisão
Cada empresa é uma base SQLite própria em `data/<id>.ideia`, em `journal_mode=DELETE`. A cópia de segurança usa `VACUUM INTO` seguido de `PRAGMA integrity_check`; o restauro confirma o cabeçalho SQLite, a marca `meta.app` e a integridade, e cria sempre uma empresa com id novo (nunca substitui).

## Razões
- Portabilidade: mudar de computador é copiar um ficheiro.
- Isolamento: um ficheiro danificado afeta só uma empresa; gabinetes entregam ou recebem a contabilidade de um cliente num único ficheiro.
- `DELETE` em vez de `WAL` evita ficheiros auxiliares (`-wal`, `-shm`) que se perdem numa cópia manual.

## Consequências
- A carteira (multiempresa) abre cada ficheiro para calcular os indicadores; com centenas de empresas pode precisar de cache.
- Não trabalhar sobre pastas sincronizadas (risco de corrupção); sincronizar só as cópias.
