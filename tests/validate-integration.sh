#!/usr/bin/env bash
# Teste de integração da validação do docker-compose.yaml (DockerCompose::validate), em contêineres descartáveis (nada é
# instalado na máquina): imagem local do Releaser e composes montados numa pasta temporária.
#
# O que se confere: a validação continua recusando bind mounts nos serviços, com UMA exceção, o socket do Docker no
# caminho padrão dos dois lados (/var/run/docker.sock:/var/run/docker.sock), e só em build AUTORIZADA por quem
# administra o servidor ("allow": { "socket": true } no arquivo da build), quando passa com um aviso. É o que o
# boilerplate 'arcane' (painel de contêineres) precisa; nos clusters compartilhados da plataforma, o deployer recusa.
# A autorização não abre nenhum outro bind mount. Nos serviços CLI (perfil 'cli'), o socket sem autorização por ora só
# gera aviso. No fim, o deploy: recusado sem autorização (nada sobe) e, autorizado, o serviço sobe com o socket.
#
#   tests/validate-integration.sh
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE=releaser-validate-test
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-validate.XXXXXX")
NS=vtest$$_panel_alpha
PORT=$((20000 + RANDOM % 20000))
EMPTY='{}'

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); grep -E "ERROR|WARNING|RESULT|EXCEPTION" "$FIX/out" | head -6 | sed 's/^/     /'; fi; }

cleanup () {
  docker ps -aq --filter "label=com.docker.compose.project=$NS" | xargs -r docker rm -f > /dev/null 2>&1 || true
  docker volume rm -f ${NS}_data ${NS}_backup > /dev/null 2>&1 || true
  docker network rm "$NS" "${NS}_default" > /dev/null 2>&1 || true
  [ -n "${KEEP:-}" ] || rm -rf "$FIX"
}

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.11-dev.1 . > /dev/null

# $argv: pasta do clone, namespace, arquivo da build em JSON (passa pelo Controller::allow, como nos controllers) e,
# opcionalmente, 'deploy' no lugar da validação
cat > "$FIX/validate.php" <<'PHP'
<?php
chdir ('/app');
foreach ([ 'helper/error.php', 'helper/output.php', 'plugin/Orchestrator.php', 'plugin/DockerCompose.php', 'class/BackupVolume.php', 'class/Controller.php' ] as $f) require_once $f;
$allow = Controller::allow (json_decode ($argv [3] ?? '{}'));
try
{
    if (($argv [4] ?? '') === 'deploy') { DockerCompose::deploy ($argv [1], $argv [2], $allow); echo "\nDEPLOYED\n"; }
    else echo "\nRESULT: ". var_export (DockerCompose::validate ($argv [1], $argv [2], $allow), TRUE) ."\n";
}
catch (Throwable $e) { echo "\nEXCEPTION: ". $e->getMessage () ."\n"; }
PHP

YES='{"allow":{"socket":true}}'
NO='{"allow":{"socket":false}}'

# $1 = nome do caso, $2 = linhas de 'volumes:' do serviço 'panel', $3 = volumes do serviço CLI 'backup' (opcional)
compose () {
  mkdir -p "$FIX/$1/.embrapa"
  echo '{ "boilerplate": "_", "platform": "native" }' > "$FIX/$1/.embrapa/settings.json"
  cat > "$FIX/$1/docker-compose.yaml" <<YAML
services:
  panel:
    image: busybox
    command: sleep 3600
    ports: [ "$PORT:80" ]
    networks: [ stack ]
    volumes:
$2
  backup:
    image: busybox
    profiles: [ cli ]
    volumes: ${3:-[ "backup:/backup" ]}
  restore:
    image: busybox
    profiles: [ cli ]
    volumes: [ "backup:/backup:ro" ]
  sanitize:
    image: busybox
    profiles: [ cli ]
networks:
  stack: { external: true, name: $NS }
volumes:
  data: { external: true, name: ${NS}_data }
  backup: { external: true, name: ${NS}_backup }
YAML
  printf 'COMPOSE_PROJECT_NAME=%s\nCOMPOSE_PROFILES=alpha\n' "$NS" > "$FIX/$1/.env.io"
  printf 'COMPOSE_PROJECT_NAME=%s\nCOMPOSE_PROFILES=cli\n' "$NS" > "$FIX/$1/.env.sh"
}

# $1 = caso, $2 = arquivo da build (JSON), $3 = 'deploy' (opcional)
validate () { docker run --rm -v "$FIX":/fix -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" php /fix/validate.php "/fix/$1" "$NS" "${2:-$EMPTY}" ${3:-} > "$FIX/out" 2>&1 || true; }

set +e

SOCKET='      - data:/data
      - /var/run/docker.sock:/var/run/docker.sock'

# 1. sem o socket
compose plain '      - data:/data'
validate plain
grep -q '^RESULT: true' "$FIX/out" && ! grep -q 'Docker socket' "$FIX/out"; check 'só volumes externos: válido, sem aviso do socket' $?
validate plain "$YES"
grep -q '^RESULT: true' "$FIX/out" && ! grep -q 'Docker socket' "$FIX/out"; check 'só volumes externos em build autorizada: válido, sem aviso (autorização sem uso não aparece)' $?

# 2. socket sem autorização: recusado, com a orientação
compose socket "$SOCKET"
validate socket
grep -q '^RESULT: false' "$FIX/out" && grep -q "ERROR > Service 'panel' mounts the Docker socket (/var/run/docker.sock), and this build is not authorized to" "$FIX/out"; check 'socket em build sem o atributo allow: recusado' $?
grep -q '"allow": { "socket": true }' "$FIX/out" && grep -q "builds/${NS}.json" "$FIX/out"; check 'a recusa diz o que pôr e em que arquivo' $?
validate socket "$NO"
grep -q '^RESULT: false' "$FIX/out" && grep -q 'is not authorized to' "$FIX/out"; check 'socket com allow.socket = false: recusado' $?

unauthorized () {  # $1 = arquivo da build, $2 = rótulo
  validate socket "$1"
  grep -q '^RESULT: false' "$FIX/out" && grep -q 'is not authorized to' "$FIX/out"; check "$2: recusado" $?
}

unauthorized '{"allow":{"socket":"true"}}' 'allow.socket = "true" (texto)'
unauthorized '{"allow":{"socket":1}}' 'allow.socket = 1'
unauthorized '{"allow":true}' 'allow = true (sem dizer o quê)'
unauthorized '{"allow":["socket"]}' 'allow como lista'
unauthorized '{"auto":{"socket":true}}' 'socket dentro de auto'

# 3. socket com autorização: válido, com aviso
validate socket "$YES"
grep -q '^RESULT: true' "$FIX/out" && grep -q "WARNING > Service 'panel' mounts the Docker socket (/var/run/docker.sock), authorized for this build" "$FIX/out" && grep -q 'VALID, but has some warnings' "$FIX/out"; check 'socket em build autorizada: válido, com aviso' $?

compose socket-ro '      - /var/run/docker.sock:/var/run/docker.sock:ro'
validate socket-ro "$YES"
grep -q '^RESULT: true' "$FIX/out" && grep -q 'authorized for this build' "$FIX/out"; check 'socket somente leitura em build autorizada: válido, com aviso' $?
validate socket-ro
grep -q '^RESULT: false' "$FIX/out" && grep -q 'is not authorized to' "$FIX/out"; check 'socket somente leitura sem autorização: recusado' $?

# 4. a autorização não abre nenhum outro bind mount
refused () {  # $1 = caso, $2 = volumes, $3 = rótulo
  compose "$1" "$2"
  validate "$1" "$YES"
  grep -q '^RESULT: false' "$FIX/out" && grep -q 'is not declared as external' "$FIX/out"; check "$3, mesmo em build autorizada: recusado" $?
}

refused etc '      - /etc:/host-etc' 'outro bind mount (/etc)'
refused relative '      - ./conf:/conf' 'bind mount relativo'
refused run '      - /var/run:/var/run' 'a pasta /var/run inteira'
refused target '      - /var/run/docker.sock:/tmp/docker.sock' 'socket montado noutro caminho do contêiner'
refused source '      - /etc/passwd:/var/run/docker.sock' 'outro arquivo no caminho do socket'
refused alias '      - /run/docker.sock:/var/run/docker.sock' 'socket por outro caminho do host (/run)'
refused mixed '      - /var/run/docker.sock:/var/run/docker.sock
      - /root:/root' 'socket junto com outro bind mount'

# 5. serviços CLI: o socket sem autorização por ora só avisa (os volumes deles nunca foram conferidos)
compose cli '      - data:/data' '[ "backup:/backup", "/var/run/docker.sock:/var/run/docker.sock" ]'
validate cli
grep -q '^RESULT: true' "$FIX/out" && grep -q "WARNING > CLI service 'backup' mounts the Docker socket, and this build is not authorized to" "$FIX/out" && grep -q 'It will become an ERROR in a future version' "$FIX/out" && grep -q '"allow": { "socket": true }' "$FIX/out"; check 'serviço CLI com o socket, sem autorização: válido, com aviso de erro futuro' $?
grep -q 'VALID, but has some warnings' "$FIX/out"; check 'o aviso do serviço CLI entra no resultado da validação' $?
validate cli "$YES"
grep -q '^RESULT: true' "$FIX/out" && grep -q "WARNING > CLI service 'backup' mounts the Docker socket, authorized for this build" "$FIX/out" && ! grep -q 'future version' "$FIX/out"; check 'serviço CLI com o socket, em build autorizada: válido, com aviso' $?

compose cli-other '      - data:/data' '[ "backup:/backup", "/run/docker.sock:/docker.sock:ro" ]'
validate cli-other
grep -q '^RESULT: true' "$FIX/out" && grep -q "CLI service 'backup' mounts the Docker socket, and this build is not authorized to" "$FIX/out"; check 'serviço CLI com o socket por /run e noutro caminho do contêiner: avisado' $?

validate plain
! grep -q "CLI service '" "$FIX/out" && ! grep -q 'Docker socket' "$FIX/out"; check 'serviços CLI sem o socket: nenhum aviso' $?

# 6. deploy: sem autorização nada sobe; autorizado, o serviço sobe com o socket montado
docker network create "$NS" > /dev/null 2>&1
docker volume create ${NS}_data > /dev/null
docker volume create ${NS}_backup > /dev/null

validate socket "$EMPTY" deploy
grep -q '^EXCEPTION: Invalid docker-compose.yaml' "$FIX/out" && grep -q 'is not authorized to' "$FIX/out" && [ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$NS")" ]; check 'deploy sem autorização: recusado, nenhum contêiner criado' $?

validate socket "$YES" deploy
grep -q '^DEPLOYED' "$FIX/out"; check 'deploy em build autorizada: concluído' $?
ID=$(docker ps -q --filter "label=com.docker.compose.project=$NS" --filter "label=com.docker.compose.service=panel" --filter status=running)
[ -n "$ID" ] && docker inspect -f '{{range .Mounts}}{{.Source}}:{{.Destination}} {{end}}' "$ID" | grep -q '/var/run/docker.sock:/var/run/docker.sock'; check 'o serviço está no ar com o socket montado' $?

echo
[ $fail -eq 0 ] && echo "TUDO OK" || echo "$fail falha(s)"
exit $fail
