#!/bin/bash
# IDEIA Contabilidade — lançador para Mac. Duplo clique (na primeira vez: botão direito → Abrir).
cd "$(dirname "$0")" || exit 1
# Ficheiros descarregados da internet ficam "em quarentena"; retira a marca só desta pasta.
xattr -dr com.apple.quarantine . 2>/dev/null
case "$(uname -m)" in
  arm64) PHP="php/mac-arm64/php" ;;
  *)     PHP="php/mac-x64/php" ;;
esac
if [ -x "$PHP" ] || chmod +x "$PHP" 2>/dev/null && [ -x "$PHP" ]; then
  exec "$PHP" app/tools/portable/launcher.php "$@"
elif command -v php >/dev/null 2>&1; then
  exec php app/tools/portable/launcher.php "$@"
else
  echo "  ERRO: falta o PHP para este Mac ($PHP). Descarregue de novo o pacote para Mac."
  read -r -p "Carregue em Enter para fechar."
fi
