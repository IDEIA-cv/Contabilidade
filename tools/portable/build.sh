#!/bin/bash
# Monta os pacotes portáteis a partir do repositório e de binários PHP já descarregados.
# Uso: tools/portable/build.sh <pasta-php> <pasta-saída>
#   <pasta-php> contém subpastas windows/ (php.exe, ext/, dll), mac-arm64/php, mac-x64/php, linux-x64/php
#   (as que existirem entram no pacote "todos"; windows e mac geram também pacotes próprios).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHPDIR="$(cd "$1" && pwd)"
OUT="$(mkdir -p "$2" && cd "$2" && pwd)"
VERSION="$(sed -n "s/.*IDEIA_VERSION', '\([^']*\)'.*/\1/p" "$ROOT/src/bootstrap.php")"
NAME="IDEIA Contabilidade"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Aplicação: ficheiros do git, sem testes nem ferramentas de desenvolvimento.
mkdir -p "$WORK/app"
(cd "$ROOT" && git archive --format=tar HEAD) | tar -xf - -C "$WORK/app"
rm -rf "$WORK/app/tests" "$WORK/app/.github" "$WORK/app/tools/bench.php" "$WORK/app/data"/*.ideia 2>/dev/null || true

make_pkg() { # make_pkg <sufixo> <plataformas...>
  local suffix="$1"; shift
  local dir="$WORK/pkg-$suffix/$NAME"
  mkdir -p "$dir/php" "$dir/dados"
  cp -R "$WORK/app" "$dir/app"
  cp "$ROOT/tools/portable/LEIA-ME.txt" "$dir/"
  for p in "$@"; do
    [ -d "$PHPDIR/$p" ] || continue
    cp -R "$PHPDIR/$p" "$dir/php/$p"
    case "$p" in
      windows) cp "$ROOT/tools/portable/Abrir IDEIA.bat" "$dir/"; cp "$ROOT/tools/portable/php.ini" "$dir/php/windows/php.ini" ;;
      mac-*)   cp "$ROOT/tools/portable/Abrir IDEIA.command" "$dir/"; chmod +x "$dir/Abrir IDEIA.command" "$dir/php/$p/php" ;;
      linux-*) cp "$ROOT/tools/portable/abrir-ideia.sh" "$dir/"; chmod +x "$dir/abrir-ideia.sh" "$dir/php/$p/php" ;;
    esac
  done
  local zip="$OUT/IDEIA-Contabilidade-$VERSION-$suffix.zip"
  rm -f "$zip"
  (cd "$WORK/pkg-$suffix" && zip -qr -X "$zip" "$NAME")
  echo "$zip ($(du -h "$zip" | cut -f1))"
}

make_pkg windows windows
make_pkg mac mac-arm64 mac-x64
make_pkg todos windows mac-arm64 mac-x64 linux-x64
