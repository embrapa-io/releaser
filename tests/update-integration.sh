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
# Também o backup que antecede a troca de imagens nos dois comandos (`io update` e `io restart --upgrade`): falha fora
# de terminal para e aponta o `--no-backup`; em terminal, pergunta (resposta não = nada recriado; sim = segue); o
# `--no-backup` pula o backup; o `restart --upgrade` de uma build sem contêiner no ar volta sem backup, com aviso; o
# `restart` simples não faz backup.
#
# Os casos de terminal usam um pty aberto pelo Python padrão da máquina (só a biblioteca padrão); sem `python3`, são
# pulados com aviso.
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

# Terminal de verdade (pty) para os casos em que o comando pergunta: roda o comando num pty, espera a pergunta aparecer
# na saída e só então digita a resposta (digitada antes, ela seria consumida pelo `docker compose run` do backup).
cat > "$FIX/tty.py" <<'EOF'
import os, pty, select, sys, time

answer, marker, cmd = sys.argv[1], sys.argv[2].encode(), sys.argv[3:]

pid, fd = pty.fork()

if pid == 0:
    os.execvp(cmd[0], cmd)

seen, sent, deadline = b'', False, time.time() + 900

while time.time() < deadline:
    ready, _, _ = select.select([fd], [], [], 1)

    if fd not in ready: continue

    try: data = os.read(fd, 4096)
    except OSError: break

    if not data: break

    seen += data

    sys.stdout.buffer.write(data)
    sys.stdout.buffer.flush()

    if not sent and marker in seen:
        os.write(fd, (answer + '\n').encode())
        sent = True
else:
    os.kill(pid, 9)

os.waitpid(pid, 0)
EOF

HAS_TTY=1; command -v python3 > /dev/null 2>&1 || HAS_TTY=

# io_tty RESPOSTA comando...: o mesmo `io`, num terminal, respondendo à pergunta do backup que falhou
io_tty () {
  local answer=$1; shift
  python3 -I "$FIX/tty.py" "$answer" 'Continue WITHOUT a backup' \
    docker run --rm -it --network "$NET" --add-host o1289077.ingest.sentry.io:127.0.0.1 --env-file "$FIX/env" \
      -v "$FIX/data":/data -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" php /app/run.php "$@" 2>&1
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
grep -q "run the command again with '--no-backup'" "$FIX/out" && ! grep -q 'Continue WITHOUT a backup' "$FIX/out"; check 'backup que falha, fora de terminal: aponta o --no-backup e não pergunta' $?

# 6b. backup que falha, em terminal: a pergunta decide
N=$(backups)
if [ -n "$HAS_TTY" ]; then
  io_tty n update $B > "$FIX/out"
  grep -q 'Continue WITHOUT a backup' "$FIX/out" && grep -q 'no container was recreated' "$FIX/out" && [ "$(cid web)" = "$W" ]; check 'update em terminal, resposta não: nada recriado' $?
  io_tty y update $B > "$FIX/out"
  grep -q 'Going on without a backup, as confirmed' "$FIX/out" && [ "$(cid web)" != "$W" ] && [ "$(runs web)" = "$(points "$WEB")" ]; check 'update em terminal, resposta sim: o web é recriado com a imagem nova' $?
  [ "$(backups)" = "$N" ] && [ "$(cid db)" = "$D" ]; check 'update em terminal, resposta sim: sem backup novo e o db intacto' $?
  W=$(cid web)
else
  echo 'skip casos de terminal do update (sem python3 na máquina)'
fi

# 6c. --no-backup: recria sem rodar o backup (que continuaria falhando)
publish "$WEB" 4
io update $B --no-backup > "$FIX/out"
grep -q "'--no-backup': the containers will be recreated" "$FIX/out" && ! grep -q 'Trying to execute backup service' "$FIX/out"; check 'update --no-backup: avisa e não roda o backup' $?
[ "$(cid web)" != "$W" ] && [ "$(runs web)" = "$(points "$WEB")" ] && [ "$(backups)" = "$N" ]; check 'update --no-backup: o web é recriado com a imagem nova' $?
io update --no-backup > "$FIX/out"; grep -q "Specify the builds" "$FIX/out"; check 'update: --no-backup sem as builds é recusado' $?
W=$(cid web)
docker run --rm -v ${NS}_data:/data busybox rm -f /data/fail-backup
publish "$WEB" 5

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
N=$(backups)
io restart $B --upgrade > "$FIX/out"
grep -q 'No container of the build is running' "$FIX/out" && [ "$(backups)" = "$N" ]; check 'restart --upgrade de build parada: volta sem backup, com aviso' $?
grep -q 'was successfully upgraded and restarted' "$FIX/out" && [ "$(running)" = "2" ]; check 'io restart --upgrade traz a build de volta' $?
grep -q -- 'build --pull --no-cache' "$FIX/out" && grep -q -- 'up -d --force-recreate' "$FIX/out"; check 'restart --upgrade: pull, build sem cache e --force-recreate, como antes' $?
[ "$(runs web)" = "$(points "$WEB")" ]; check 'restart --upgrade: o web roda a imagem nova' $?

# 10. restart --upgrade com a build no ar: backup depois do pull e do build, antes de recriar
W=$(cid web); D=$(cid db); N=$(backups)
io restart $B --upgrade > "$FIX/out"
[ "$(backups)" = "$((N + 1))" ] && [ "$(cid web)" != "$W" ] && [ "$(cid db)" != "$D" ] && [ "$(running)" = "2" ]; check 'restart --upgrade: backup feito e todos os contêineres recriados' $?
line () { grep -n -- "$1" "$FIX/out" | head -1 | cut -d: -f1; }
[ "$(line 'build --pull --no-cache')" -lt "$(line 'Backup service ran successfully')" ] && [ "$(line 'Backup service ran successfully')" -lt "$(line 'up -d --force-recreate')" ]; check 'restart --upgrade: o backup vem depois do build e antes do up' $?
W=$(cid web); D=$(cid db); N=$(backups)
io restart $B > "$FIX/out"
grep -q 'was successfully restarted' "$FIX/out" && ! grep -q 'Backing up' "$FIX/out" && [ "$(backups)" = "$N" ]; check 'restart simples: sem backup' $?

# 11. restart --upgrade com backup que falha
docker run --rm -v ${NS}_data:/data busybox touch /data/fail-backup
io restart $B --upgrade > "$FIX/out"
grep -q 'The backup before the upgrade failed' "$FIX/out" && grep -q 'no container was recreated' "$FIX/out"; check 'restart --upgrade com backup que falha: erro e aviso' $?
grep -q "run the command again with '--no-backup'" "$FIX/out" && ! grep -q 'Continue WITHOUT a backup' "$FIX/out"; check 'restart --upgrade fora de terminal: aponta o --no-backup e não pergunta' $?
[ "$(cid web)" = "$W" ] && [ "$(cid db)" = "$D" ] && [ "$(running)" = "2" ]; check 'restart --upgrade com backup que falha: nenhum contêiner recriado' $?
! grep -q -- 'up -d --force-recreate' "$FIX/out"; check 'restart --upgrade com backup que falha: o up não roda' $?
if [ -n "$HAS_TTY" ]; then
  io_tty n restart $B --upgrade > "$FIX/out"
  grep -q 'Continue WITHOUT a backup' "$FIX/out" && [ "$(cid web)" = "$W" ] && [ "$(running)" = "2" ]; check 'restart --upgrade em terminal, resposta não: nada recriado' $?
  io_tty y restart $B --upgrade > "$FIX/out"
  grep -q 'was successfully upgraded and restarted' "$FIX/out" && [ "$(cid web)" != "$W" ] && [ "$(backups)" = "$N" ]; check 'restart --upgrade em terminal, resposta sim: recriado sem backup' $?
  W=$(cid web)
else
  echo 'skip casos de terminal do restart --upgrade (sem python3 na máquina)'
fi
io restart $B --upgrade --no-backup > "$FIX/out"
grep -q "'--no-backup': the containers will be recreated" "$FIX/out" && ! grep -q 'Trying to execute backup service' "$FIX/out" && [ "$(cid web)" != "$W" ] && [ "$(backups)" = "$N" ]; check 'restart --upgrade --no-backup: recria sem rodar o backup' $?
W=$(cid web)
io restart $B --no-backup --upgrade > "$FIX/out"
[ "$(cid web)" != "$W" ] && [ "$(running)" = "2" ]; check 'as opções valem em qualquer ordem' $?
docker run --rm -v ${NS}_data:/data busybox rm -f /data/fail-backup
[ "$(docker run --rm -v ${NS}_data:/data busybox cat /data/state)" = "externo" ]; check 'dado do volume intacto depois de tudo' $?

# 12. opções do restart
W=$(cid web)
io restart $B --no-backup > "$FIX/out"; grep -q "only makes sense with '--upgrade'" "$FIX/out" && [ "$(cid web)" = "$W" ]; check 'restart: --no-backup sem --upgrade é recusado' $?
io restart --no-backup > "$FIX/out"; grep -q "Specify the builds" "$FIX/out"; check 'restart: --no-backup sem as builds é recusado' $?
io restart $B --upgrade --force > "$FIX/out"; grep -q "Unknown parameter '--force'" "$FIX/out" && [ "$(cid web)" = "$W" ]; check 'restart: opção desconhecida é recusada' $?
[ -z "$(ls "$FIX/data/.busy" 2> /dev/null)" ]; check 'nenhuma trava por build fica para trás' $?

# 13. backup do daemon (desde a 1.26.11-9): e-mail só quando o backup da noite NÃO foi feito
errmails () { docker run --rm -v "$FIX/data":/data busybox sh -c 'grep -l "BACKUP ERROR" /data/.mail/*.json 2> /dev/null | xargs -r grep -l "'"$1"'" | wc -l' | tr -d ' '; }
build ''
N=$(backups)
io backup:daemon --all > "$FIX/out"
[ "$(backups)" = "$N" ] && [ "$(mails 'BACKUP ERROR')" = "0" ] && [ ! -e "$FIX/data/.lock/backup" ]; check 'backup do daemon com a build sem o atributo auto: ignorada, sem e-mail e sem trava para trás' $?
build ',"auto":{"backup":true}'
io backup:daemon --all > "$FIX/out"
[ "$(backups)" = "$((N + 1))" ] && [ "$(mails 'BACKUP ERROR')" = "0" ]; check 'backup do daemon que dá certo: sem e-mail' $?
docker run --rm -v ${NS}_data:/data busybox touch /data/fail-backup
io backup $B > "$FIX/out"
grep -q 'Unable to back up build' "$FIX/out" && [ "$(mails 'BACKUP ERROR')" = "0" ]; check 'backup manual que falha: erro na tela, sem e-mail' $?
io backup:daemon --all > "$FIX/out"
[ "$(mails "$B - BACKUP ERROR")" = "1" ]; check 'backup do daemon que falha: e-mail BACKUP ERROR com a build no assunto' $?
[ "$(errmails 'dev@example.com')" = "1" ]; check 'backup do daemon que falha: e-mail em cópia para a equipe da build' $?
[ "$(errmails 'Unable to back up build')" = "1" ] && [ "$(errmails 'was NOT done')" = "1" ]; check 'backup do daemon que falha: o corpo traz o erro e o aviso de que a noite ficou sem backup' $?
io backup:daemon --all > "$FIX/out"
[ "$(mails 'BACKUP ERROR')" = "2" ]; check 'backup do daemon que falha de novo: um e-mail por execução' $?
docker run --rm -v ${NS}_data:/data busybox rm -f /data/fail-backup
io backup:daemon --all > "$FIX/out"
[ "$(mails 'BACKUP ERROR')" = "2" ]; check 'backup do daemon volta a dar certo: sem e-mail novo' $?
docker run --rm -v "$FIX/data":/data busybox mv /data/apps/$P/$A/$TAG /data/apps/$P/$A/$TAG.away
io backup:daemon --all > "$FIX/out"
[ "$(errmails 'is missing')" = "1" ]; check 'backup do daemon com o clone da versão ausente: e-mail BACKUP ERROR' $?
docker run --rm -v "$FIX/data":/data busybox mv /data/apps/$P/$A/$TAG.away /data/apps/$P/$A/$TAG
[ -z "$(ls "$FIX/data/.busy" 2> /dev/null)" ]; check 'backup do daemon: nenhuma trava por build fica para trás' $?

echo
if [ "$fail" -eq 0 ]; then echo "update-integration: all ok"; else echo "update-integration: $fail FAIL"; exit 1; fi
