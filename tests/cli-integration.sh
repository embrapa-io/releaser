#!/usr/bin/env bash
# Teste de integração do `io cli` ANTES da configuração inicial (sem /data/.env), pelo `bin/io` da imagem local, em
# contêineres descartáveis. Nada é gravado no host: só o `--print` e a recusa da instalação (resposta "n") são
# exercitados; a gravação em /usr/local/bin fica com os casos de tests/cli.php.
#
# O que se garante: sem o `.env`, o `io cli` não abre o assistente nem acusa variáveis faltando (o servidor pode nascer
# com o comando `io` instalado por um script de preparação); todo outro comando continua abrindo o assistente.
#
#   tests/cli-integration.sh
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE=releaser-cli-test
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-cli.XXXXXX")

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); head -8 "$FIX/out" 2>/dev/null | sed 's/^/     /'; fi; }

cleanup () { docker run --rm -v "$FIX":/fix busybox rm -rf /fix/data > /dev/null 2>&1 || true; rm -rf "$FIX"; }

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.11-dev.1 . > /dev/null

mkdir -p "$FIX/data"

io () { docker run --rm -i -v "$FIX/data":/data -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" io "$@" 2>&1; }

set +e

io cli --print < /dev/null > "$FIX/out"
[ "$(head -1 "$FIX/out")" = "#!/bin/sh" ]; check 'io cli --print sem .env: só o script, desde a primeira linha' $?
grep -q 'Embrapa I/O Releaser host wrapper' "$FIX/out" && grep -q 'docker exec' "$FIX/out"; check 'o script é o wrapper do Releaser' $?
! grep -q -E 'CRITICAL|WARNING|Warning|Server name' "$FIX/out"; check 'sem assistente nem aviso de variável faltando' $?

echo n | io cli > "$FIX/out"
grep -q "Install (or update) '/usr/local/bin/io' on this server?" "$FIX/out"; check 'io cli sem .env chega à pergunta da instalação' $?
grep -q 'Nothing done' "$FIX/out" && ! grep -q -E 'CRITICAL|Server name' "$FIX/out"; check 'resposta "n": nada é gravado, sem assistente' $?

[ ! -e "$FIX/data/.env" ]; check 'o io cli não cria o .env' $?

io < /dev/null > "$FIX/out"
grep -q 'Environment variables file (.env) not found' "$FIX/out" && grep -q 'Server name' "$FIX/out"; check 'io sem comando continua abrindo o assistente' $?

io deploy --all < /dev/null > "$FIX/out"
grep -q 'Environment variables file (.env) not found' "$FIX/out"; check 'outro comando sem .env continua abrindo o assistente' $?

echo
if [ "$fail" -eq 0 ]; then echo "cli-integration: all ok"; else echo "cli-integration: $fail FAIL"; exit 1; fi
