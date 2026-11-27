#!/bin/sh
# IDEIA Contabilidade — lançador para Linux (usa php/linux-x64/php se existir, senão o php do sistema com pdo_sqlite).
cd "$(dirname "$0")" || exit 1
PHP=php/linux-x64/php
[ -x "$PHP" ] || PHP=php
exec "$PHP" app/tools/portable/launcher.php "$@"
