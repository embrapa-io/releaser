#!/usr/bin/env bash
# Teste de integração do `io update` (e do `update:daemon` com `auto.update`), em contêineres descartáveis (nada é
# instalado na máquina): imagem local do Releaser e uma app falsa com dois serviços de imagem "flutuante" (tags locais,
# trocadas de conteúdo durante o teste para simular a imagem nova no registro) e um serviço `backup`.
#
# O que se garante: sem imagem nova nada é tocado (nem backup); com imagem nova, o backup roda antes e só o serviço
# dela é recriado, sem build nem --force-recreate; se o backup falha, nada é recriado; o --dry-run só mostra; a build
# parada com `io stop` não é atualizada; o daemon só age com "auto": { "update": true } e manda e-mail só quando
# atualizou.
#
#   tests/update-integration.sh        (KEEP=1 mantém a fixture)
set -euo pipefail

cd "$(dirname "$0")/.."

ID=$$
NET=releaser-update-$ID
CORE=releaser-update-core-$ID
IMAGE=releaser-update-test
WEB=releaser-update-web-$ID:floating
DB=releaser-update-db-$ID:floating
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-update.XXXXXX")
P=demo; A=upd; S=alpha; NS=${P}_${A}_${S}; TAG=1.26.11-alpha.1; B=$P/$A@$S

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); grep -E "ERROR|CRITICAL|WARNING|FAIL" "$FIX/out" 2>/dev/null | head -8 | sed 's/^/     /'; fi; }

cleanup () {
  docker compose -p "$NS" down --remove-orphans > /dev/null 2>&1 || true
  docker rm -f $(docker ps -aq --filter "label=com.docker.compose.project=$NS") "$CORE" > /dev/null 2>&1 || true
  docker volume rm -f ${NS}_data ${NS}_backup > /dev/null 2>&1 || true
  docker network rm "$NET" > /dev/null 2>&1 || true
  docker rmi -f "$WEB" "$DB" > /dev/null 2>&1 || true
  if [ -z "${KEEP:-}" ]; then docker run --rm -v "$FIX":/fix busybox rm -rf /fix/data /fix/core > /dev/null 2>&1 || true; rm -rf "$FIX"; fi
}

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.11-dev.1 . > /dev/null

docker network create "$NET" > /dev/null

# core falso: só os catálogos de /metadata que o Releaser lê ao iniciar (o relato ao core fica desligado pelo stats)
mkdir -p "$FIX/core"
cat > "$FIX/core/core.py" <<'EOF'
import json
from http.server import BaseHTTPRequestHandler, HTTPServer

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def reply(self, body):
        data = json.dumps(body).encode()
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def do_GET(self):
        meta = {'/metadata/boilerplates.json': [], '/metadata/clusters.json': {}, '/metadata/orchestrators.json': [{'type': 'DockerCompose', 'variables': []}]}
        if self.path in meta: return self.reply(meta[self.path])
        self.send_response(404); self.end_headers()
    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length', 0)))
        self.reply({'user': 'tester', 'accepted': [], 'rejected': []})

HTTPServer(('0.0.0.0', 8080), H).serve_forever()
EOF
docker run -d --name "$CORE" --network "$NET" -v "$FIX/core":/core python:3-alpine python /core/core.py > /dev/null

# "imagem nova no registro": a mesma tag passa a apontar para outro conteúdo
publish () { printf 'FROM busybox\nLABEL releaser.update.test=%s\n' "$2" | docker build -q -t "$1" - > /dev/null; }

publish "$WEB" 1
publish "$DB" 1

docker volume create ${NS}_data > /dev/null
docker volume create ${NS}_backup > /dev/null
docker run --rm -v ${NS}_data:/data busybox sh -c 'echo externo > /data/state'

mkdir -p "$FIX/data/builds" "$FIX/data/apps/$P/$A/.version" "$FIX/data/apps/$P/$A/$TAG"
echo "$TAG" > "$FIX/data/apps/$P/$A/.version/$S"

# stats desligado: sem relato ao core nem varredura do Trivy (fora do escopo deste teste)
# (gravado à parte e movido: reescrever o arquivo no lugar pode ser lido pela metade através do compartilhamento do Docker)
build () { echo "{\"project\":\"$P\",\"app\":\"$A\",\"stage\":\"$S\",\"team\":[\"dev@example.com\"],\"stats\":false$1}" > "$FIX/build.json"; mv -f "$FIX/build.json" "$FIX/data/builds/${NS}.json"; }

build ''

# o backup registra cada execução em /backup/runs e falha enquanto existir /data/fail-backup
cat > "$FIX/data/apps/$P/$A/$TAG/docker-compose.yaml" <<EOF
services:
  web:
    image: $WEB
    command: sleep 3600
    volumes: [ "data:/data" ]
  db:
    image: $DB
    command: sleep 3600
  backup:
    image: busybox
    profiles: [ cli ]
    volumes: [ "data:/data:ro", "backup:/backup" ]
    command: sh -c 'test ! -f /data/fail-backup && echo run >> /backup/runs'
volumes:
  data: { external: true, name: ${NS}_data }
  backup: { external: true, name: ${NS}_backup }
EOF

printf 'COMPOSE_PROJECT_NAME=%s\n' "$NS" > "$FIX/data/apps/$P/$A/$TAG/.env.io"
printf 'COMPOSE_PROJECT_NAME=%s\nCOMPOSE_PROFILES=cli\n' "$NS" > "$FIX/data/apps/$P/$A/$TAG/.env.sh"

cat > "$FIX/env" <<EOF
SERVER=update-test
ORCHESTRATOR=DockerCompose
GITLAB_TOKEN=test
IO_API_URL=http://$CORE:8080
SMTP_HOST=127.0.0.1
SMTP_PORT=1025
SMTP_FROM=releaser@example.com
SMTP_TIMEOUT=1
LOG_MAIL=ops@example.com
BACKUP_DIR=/root/backup
EOF

io () {
  docker run --rm -i --network "$NET" --add-host o1289077.ingest.sentry.io:127.0.0.1 --env-file "$FIX/env" \
    -v "$FIX/data":/data -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" php /app/run.php "$@" 2>&1 < /dev/null
}

running () { docker ps -q --filter "label=com.docker.compose.project=$NS" --filter status=running | wc -l | tr -d ' '; }
cid () { docker ps -q --no-trunc --filter "label=com.docker.compose.project=$NS" --filter "label=com.docker.compose.service=$1"; }
runs () { docker inspect --format '{{.Image}}' "$(cid "$1")"; }
points () { docker image inspect --format '{{.Id}}' "$1"; }
backups () { docker run --rm -v ${NS}_backup:/b busybox sh -c 'cat /b/runs 2> /dev/null | wc -l' | tr -d ' '; }
mails () { docker run --rm -v "$FIX/data":/data busybox sh -c 'grep -l "'"$1"'" /data/.mail/*.json 2> /dev/null | wc -l' | tr -d ' '; }

set +e

# 0. sobe a app
io restart $B > "$FIX/out"; [ "$(running)" = "2" ]; check 'io restart sobe os dois serviços' $?
W=$(cid web); D=$(cid db)

# 1. sem imagem nova: nada é tocado
io update $B > "$FIX/out"
grep -q 'are up to date. Nothing to do' "$FIX/out"; check 'sem imagem nova: nada a fazer' $?
[ "$(cid web)" = "$W" ] && [ "$(cid db)" = "$D" ]; check 'sem imagem nova: nenhum contêiner recriado' $?
[ "$(backups)" = "0" ]; check 'sem imagem nova: o backup não roda' $?
grep -q -- 'pull --ignore-pull-failures --ignore-buildable' "$FIX/out"; check 'o pull ignora falhas e serviços com build' $?

# 2. imagem nova do web: --dry-run só mostra
publish "$WEB" 2
io update $B --dry-run > "$FIX/out"
grep -q "Service 'web': image '$WEB' has new content" "$FIX/out"; check 'dry run: aponta o serviço e a imagem' $?
grep -q 'would be recreated: web' "$FIX/out"; check 'dry run: diz o que seria recriado' $?
[ "$(cid web)" = "$W" ] && [ "$(backups)" = "0" ]; check 'dry run: nenhum contêiner recriado e nenhum backup' $?

# 3. update de verdade: backup antes e só o web recriado
io update $B > "$FIX/out"
grep -q 'was updated: 1 service(s) recreated with a new image (web)' "$FIX/out"; check 'io update termina com sucesso' $?
[ "$(cid web)" != "$W" ] && [ "$(runs web)" = "$(points "$WEB")" ]; check 'o web foi recriado e roda a imagem nova' $?
[ "$(cid db)" = "$D" ]; check 'o db, sem imagem nova, não foi tocado' $?
[ "$(backups)" = "1" ]; check 'o backup rodou uma vez, antes de recriar' $?
[ "$(grep -n 'Backup service ran successfully' "$FIX/out" | cut -d: -f1)" -lt "$(grep -n 'up -d --no-build --no-deps' "$FIX/out" | head -1 | cut -d: -f1)" ]; check 'o backup vem antes do up' $?
grep -q -- "up -d --no-build --no-deps 'web'" "$FIX/out"; check 'só o serviço com imagem nova vai para o up' $?
! grep -E 'COMMAND >' "$FIX/out" | grep -q -E 'force-recreate|--build|build --pull|--volumes|down'; check 'nenhum comando com --force-recreate, build ou down' $?
[ "$(docker run --rm -v ${NS}_data:/data busybox cat /data/state)" = "externo" ]; check 'dado do volume intacto' $?
[ "$(running)" = "2" ]; check 'os dois serviços seguem no ar' $?
grep -q 'CVE scan skipped' "$FIX/out"; check 'a varredura de CVEs respeita o stats.cve' $?
W=$(cid web)

# 4. de novo: nada a fazer
io update $B > "$FIX/out"
grep -q 'are up to date. Nothing to do' "$FIX/out" && [ "$(cid web)" = "$W" ] && [ "$(backups)" = "1" ]; check 'rodar de novo não recria nem faz backup' $?

# 5. opções
io update $B --force > "$FIX/out"; grep -q "Unknown parameter '--force'" "$FIX/out"; check 'opção desconhecida é recusada' $?
io update --dry-run > "$FIX/out"; grep -q "Specify the builds" "$FIX/out"; check '--dry-run sem as builds é recusado' $?

# 6. backup que falha: nada é recriado
docker run --rm -v ${NS}_data:/data busybox touch /data/fail-backup
publish "$WEB" 3
io update $B > "$FIX/out"
grep -q 'The backup before the update failed' "$FIX/out" && grep -q 'no container was recreated' "$FIX/out"; check 'backup que falha: erro e aviso' $?
[ "$(cid web)" = "$W" ] && [ "$(running)" = "2" ]; check 'backup que falha: nenhum contêiner recriado' $?
docker run --rm -v ${NS}_data:/data busybox rm -f /data/fail-backup

# 7. daemon: só com "auto": { "update": true }; e-mail só quando atualizou
io update:daemon --all > "$FIX/out"
[ "$(cid web)" = "$W" ] && [ "$(mails UPDATE)" = "0" ]; check 'daemon sem auto.update: build ignorada, sem e-mail' $?
build ',"auto":{"update":true}'
io update:daemon --all > "$FIX/out"
[ "$(cid web)" != "$W" ] && [ "$(runs web)" = "$(points "$WEB")" ]; check 'daemon com auto.update: o web foi recriado com a imagem nova' $?
[ "$(mails "$TAG - UPDATE SUCCESS")" = "1" ]; check 'daemon: e-mail UPDATE SUCCESS com a versão' $?
[ "$(mails 'dev@example.com')" = "1" ]; check 'daemon: e-mail em cópia para a equipe da build' $?
W=$(cid web)
io update:daemon --all > "$FIX/out"
[ "$(cid web)" = "$W" ] && [ "$(mails UPDATE)" = "1" ]; check 'daemon sem imagem nova: nada recriado e nenhum e-mail' $?
docker run --rm -v ${NS}_data:/data busybox touch /data/fail-backup
publish "$DB" 2
io update:daemon --all > "$FIX/out"
[ "$(cid db)" = "$D" ] && [ "$(mails 'UPDATE ERROR')" = "1" ]; check 'daemon com backup que falha: nada recriado e e-mail UPDATE ERROR' $?
docker run --rm -v ${NS}_data:/data busybox rm -f /data/fail-backup
[ ! -e "$FIX/data/.lock/update" ] && [ -z "$(ls "$FIX/data/.busy" 2> /dev/null)" ]; check 'nenhuma trava fica para trás' $?

# 8. build parada com `io stop`: não é atualizada (subiria de novo)
io stop $B > "$FIX/out"; [ "$(running)" = "0" ]; check 'io stop para a build' $?
io update $B > "$FIX/out"
grep -q "Stopped with 'io stop': nothing to update" "$FIX/out" && [ "$(running)" = "0" ]; check 'build parada: não é atualizada nem sobe' $?

# 9. o `restart --upgrade` (que divide o pull com o update) segue funcionando e traz a build de volta com a imagem nova
publish "$WEB" 9
io restart $B --upgrade > "$FIX/out"
grep -q 'was successfully upgraded and restarted' "$FIX/out" && [ "$(running)" = "2" ]; check 'io restart --upgrade traz a build de volta' $?
grep -q -- 'build --pull --no-cache' "$FIX/out" && grep -q -- 'up -d --force-recreate' "$FIX/out"; check 'restart --upgrade: pull, build sem cache e --force-recreate, como antes' $?
[ "$(runs web)" = "$(points "$WEB")" ]; check 'restart --upgrade: o web roda a imagem nova' $?

echo
if [ "$fail" -eq 0 ]; then echo "update-integration: all ok"; else echo "update-integration: $fail FAIL"; exit 1; fi
