#!/usr/bin/env bash
# Teste de integração da validação do docker-compose.yaml (DockerCompose::validate), em contêineres descartáveis (nada é
# instalado na máquina): imagem local do Releaser e composes montados numa pasta temporária.
#
# O que se confere: a validação continua recusando bind mounts nos serviços, com UMA exceção, o socket do Docker no
# caminho padrão dos dois lados (/var/run/docker.sock:/var/run/docker.sock), e só em build AUTORIZADA por quem
# administra o servidor ("allow": { "socket": true } no arquivo da build), quando passa com um aviso. É o que o
# boilerplate 'arcane' (painel de contêineres) precisa; nos clusters compartilhados da plataforma, o deployer recusa.
# A autorização não abre nenhum outro bind mount. Os serviços CLI (perfil 'cli': backup, restore, sanitize e o que eles
# puxarem por depends_on) seguem as mesmas regras: nenhum caminho do servidor montado direto, volumes externos e da
# build, socket só com autorização. Pasta do servidor entra por volume externo criado por quem administra o servidor
# (é assim o de backup), e isso continua valendo. No fim, o deploy: recusado sem autorização (nada sobe) e, autorizado,
# o serviço sobe com o socket.
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

# $1 = nome do caso, $2 = linhas de 'volumes:' do serviço 'panel', $3 = volumes do serviço CLI 'backup' (opcional),
# $4 = linhas a mais em 'volumes:' da raiz (opcional), $5 = serviços a mais (opcional)
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
${5:-}
networks:
  stack: { external: true, name: $NS }
volumes:
  data: { external: true, name: ${NS}_data }
  backup: { external: true, name: ${NS}_backup }
${4:-}
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

# 5. serviços CLI: as mesmas regras dos demais serviços
DATA='      - data:/data'

compose cli "$DATA" '[ "backup:/backup", "/var/run/docker.sock:/var/run/docker.sock" ]'
validate cli
grep -q '^RESULT: false' "$FIX/out" && grep -q "ERROR > CLI service 'backup' mounts the Docker socket (/var/run/docker.sock), and this build is not authorized to" "$FIX/out" && grep -q '"allow": { "socket": true }' "$FIX/out"; check 'serviço CLI com o socket, sem autorização: recusado, com a orientação' $?
validate cli "$YES"
grep -q '^RESULT: true' "$FIX/out" && grep -q "WARNING > CLI service 'backup' mounts the Docker socket (/var/run/docker.sock), authorized for this build" "$FIX/out" && grep -q 'VALID, but has some warnings' "$FIX/out"; check 'serviço CLI com o socket, em build autorizada: válido, com aviso' $?

cli_refused () {  # $1 = caso, $2 = volumes do 'backup', $3 = rótulo, $4 = arquivo da build (opcional), $5 = volumes da raiz, $6 = serviços
  compose "$1" "$DATA" "$2" "${5:-}" "${6:-}"
  validate "$1" "${4:-$EMPTY}"
  grep -q '^RESULT: false' "$FIX/out" && grep -qE "ERROR > (CLI service '[a-z]+' mounts the server path|Volume '[a-z]+' \(used by the CLI services\))" "$FIX/out"; check "$3: recusado" $?
}

cli_refused cli-etc '[ "backup:/backup", "/etc:/host-etc:ro" ]' 'serviço CLI com uma pasta do servidor (/etc)'
grep -q "mounts the server path '/etc' directly (bind mount)" "$FIX/out" && grep -q "docker volume create --driver local --opt type=none --opt o=bind --opt device=<directory> ${NS}_<name>" "$FIX/out"; check 'a recusa diz o caminho e como trocar por um volume externo' $?
cli_refused cli-root '[ "/:/host" ]' 'serviço CLI com a raiz do servidor'
cli_refused cli-relative '[ "backup:/backup", "./docker/backup.sh:/backup.sh:ro" ]' 'serviço CLI com caminho relativo (script do repositório)'
cli_refused cli-dir '[ "/root/backup:/backup" ]' 'serviço CLI com a pasta de backups montada direto, sem volume'
cli_refused cli-other '[ "backup:/backup", "/run/docker.sock:/docker.sock" ]' 'serviço CLI com o socket por /run e noutro caminho, mesmo em build autorizada' "$YES"
cli_refused cli-mixed '[ "/var/run/docker.sock:/var/run/docker.sock", "/root:/root" ]' 'serviço CLI com o socket e outra pasta, mesmo em build autorizada' "$YES"
cli_refused cli-long '
      - type: bind
        source: /etc
        target: /host-etc' 'serviço CLI com bind mount na forma longa'

# volumes da raiz usados só pelos serviços CLI: externos e da build, como os demais
cli_refused cli-opts '[ "host:/host" ]' 'volume do compose apontando para uma pasta do servidor (driver_opts), usado só no serviço CLI' "$EMPTY" '  host: { driver: local, driver_opts: { type: none, o: bind, device: /etc } }'
cli_refused cli-named '[ "scratch:/scratch" ]' 'volume não externo usado só no serviço CLI' "$EMPTY" '  scratch: {}'
cli_refused cli-foreign '[ "foreign:/foreign" ]' 'volume externo de outra build usado só no serviço CLI' "$EMPTY" '  foreign: { external: true, name: other_app_release_db }'

# serviço de apoio no perfil 'cli' (subiria com o backup, por depends_on)
cli_refused cli-helper '[ "backup:/backup" ]' 'serviço de apoio do perfil cli com uma pasta do servidor' "$EMPTY" '' '  helper:
    image: busybox
    profiles: [ cli ]
    volumes: [ "/etc:/host-etc" ]'

# o que continua valendo: volume externo criado por quem administra o servidor, mesmo ligado a uma pasta (o de backup)
compose cli-volumes "$DATA" '[ "backup:/backup", "data:/data:ro", "extra:/extra" ]' "  extra: { external: true, name: ${NS}_extra }"
validate cli-volumes
grep -q '^RESULT: true' "$FIX/out" && ! grep -q "CLI service '" "$FIX/out" && ! grep -q 'used by the CLI services' "$FIX/out"; check 'serviço CLI só com volumes externos da build: válido, nenhum aviso sobre eles' $?

docker volume create --driver local --opt type=none --opt o=bind --opt device=/tmp ${NS}_backup > /dev/null
docker volume create ${NS}_data > /dev/null
validate plain
grep -q '^RESULT: true' "$FIX/out" && ! grep -q "CLI service '" "$FIX/out" && ! grep -q "External volume '${NS}_backup' does NOT exist" "$FIX/out"; check 'volume de backup ligado a uma pasta do servidor (type=none, o=bind), criado fora do compose: válido' $?

validate plain
! grep -q "CLI service '" "$FIX/out" && ! grep -q 'Docker socket' "$FIX/out"; check 'serviços CLI sem o socket: nenhum aviso' $?

# 6. deploy: sem autorização nada sobe; autorizado, o serviço sobe com o socket montado
docker network create "$NS" > /dev/null 2>&1

validate socket "$EMPTY" deploy
grep -q '^EXCEPTION: Invalid docker-compose.yaml' "$FIX/out" && grep -q 'is not authorized to' "$FIX/out" && [ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$NS")" ]; check 'deploy sem autorização: recusado, nenhum contêiner criado' $?

validate socket "$YES" deploy
grep -q '^DEPLOYED' "$FIX/out"; check 'deploy em build autorizada: concluído' $?
ID=$(docker ps -q --filter "label=com.docker.compose.project=$NS" --filter "label=com.docker.compose.service=panel" --filter status=running)
[ -n "$ID" ] && docker inspect -f '{{range .Mounts}}{{.Source}}:{{.Destination}} {{end}}' "$ID" | grep -q '/var/run/docker.sock:/var/run/docker.sock'; check 'o serviço está no ar com o socket montado' $?

echo
[ $fail -eq 0 ] && echo "TUDO OK" || echo "$fail falha(s)"
exit $fail
