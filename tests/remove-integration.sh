#!/usr/bin/env bash
# Teste de integração do `io stop`, do `io restart` de uma build parada e do `io remove`, em contêineres descartáveis
# (nada é instalado na máquina): imagem local do Releaser, um core falso (Python, registra cada requisição) e uma app
# falsa (busybox) com três tipos de dado:
#
#   - volume externo `<ns>_data` (padrão da plataforma);
#   - volume nomeado do Compose, sem `external` (`cache`): é o que um `down -v` apagaria;
#   - bind mount de uma pasta do host.
#
# O que se garante: o `stop` avisa o core (state: stopped) e os daemons pulam a build; o `restart` tira a marca e avisa
# (state: running); o `remove` derruba os contêineres, pede ao core que esqueça a build (DELETE), desativa o arquivo
# (.json → .bkp) e NÃO APAGA NENHUM DADO: os três arquivos continuam lá, com o mesmo conteúdo, e os volumes existem.
#
#   tests/remove-integration.sh        (KEEP=1 mantém a fixture)
set -euo pipefail

cd "$(dirname "$0")/.."

ID=$$
NET=releaser-remove-$ID
CORE=releaser-remove-core-$ID
IMAGE=releaser-remove-test
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-remove.XXXXXX")
P=demo; A=web; S=alpha; NS=${P}_${A}_${S}; TAG=1.26.11-alpha.1; B=$P/$A@$S

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); grep -E "ERROR|CRITICAL|WARNING|FAIL" "$FIX/out" 2>/dev/null | head -8 | sed 's/^/     /'; fi; }

cleanup () {
  docker compose -p "$NS" down --remove-orphans > /dev/null 2>&1 || true
  docker rm -f $(docker ps -aq --filter "label=com.docker.compose.project=$NS") "$CORE" > /dev/null 2>&1 || true
  docker volume rm -f ${NS}_data ${NS}_cache > /dev/null 2>&1 || true
  docker network rm "$NET" > /dev/null 2>&1 || true
  if [ -z "${KEEP:-}" ]; then docker run --rm -v "$FIX":/fix busybox rm -rf /fix/files /fix/data > /dev/null 2>&1 || true; rm -rf "$FIX"; fi
}

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.11-dev.1 . > /dev/null

docker network create "$NET" > /dev/null

# core falso: catálogos de /metadata, POST /releaser/report e DELETE /releaser/build; cada requisição vai para requests.log
mkdir -p "$FIX/core"
cat > "$FIX/core/core.py" <<'EOF'
import json, re
from http.server import BaseHTTPRequestHandler, HTTPServer

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def reply(self, body):
        data = json.dumps(body).encode()
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def record(self, body=''):
        with open('/core/requests.log', 'a') as f: f.write(json.dumps({'method': self.command, 'path': self.path, 'auth': self.headers.get('Authorization', ''), 'body': body}) + '\n')
    def do_GET(self):
        meta = {'/metadata/boilerplates.json': [], '/metadata/clusters.json': {}, '/metadata/orchestrators.json': [{'type': 'DockerCompose', 'variables': []}]}
        if self.path in meta: return self.reply(meta[self.path])
        self.send_response(404); self.end_headers()
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0))).decode()
        self.record(body)
        builds = json.loads(body).get('builds', [])
        self.reply({'user': 'tester', 'accepted': [{'build': b['project'] + '/' + b['app'] + '@' + b['stage'], 'done': [k for k in ('version', 'services', 'cve', 'state') if k in b]} for b in builds], 'rejected': []})
    def do_DELETE(self):
        self.record()
        m = re.match(r'^/releaser/build/([^/]+)/([^/]+)/([^/?]+)', self.path)
        self.reply({'build': m.group(1) + '/' + m.group(2) + '@' + m.group(3), 'forgotten': True, 'server': 'remove-test'})

HTTPServer(('0.0.0.0', 8080), H).serve_forever()
EOF
: > "$FIX/core/requests.log"
docker run -d --name "$CORE" --network "$NET" -v "$FIX/core":/core python:3-alpine python /core/core.py > /dev/null

# dados: volume externo, volume nomeado do Compose (criado no primeiro up) e bind mount do host
docker volume create ${NS}_data > /dev/null
docker run --rm -v ${NS}_data:/data busybox sh -c 'echo externo > /data/state'
mkdir -p "$FIX/files"
echo bind > "$FIX/files/state"

mkdir -p "$FIX/data/builds" "$FIX/data/apps/$P/$A/.version" "$FIX/data/apps/$P/$A/$TAG"
echo "$TAG" > "$FIX/data/apps/$P/$A/.version/$S"
# stats.cve desligado: o restart de uma build parada varreria as CVEs com o Trivy (fora do escopo deste teste)
echo "{\"project\":\"$P\",\"app\":\"$A\",\"stage\":\"$S\",\"team\":[],\"stats\":{\"cve\":false}}" > "$FIX/data/builds/${NS}.json"

cat > "$FIX/data/apps/$P/$A/$TAG/docker-compose.yaml" <<EOF
services:
  app:
    image: busybox
    command: sleep 3600
    volumes: [ "data:/data", "cache:/cache", "$FIX/files:/files" ]
volumes:
  data: { external: true, name: ${NS}_data }
  cache: {}
EOF

printf 'COMPOSE_PROJECT_NAME=%s\n' "$NS" > "$FIX/data/apps/$P/$A/$TAG/.env.io"
printf 'COMPOSE_PROJECT_NAME=%s\nCOMPOSE_PROFILES=cli\n' "$NS" > "$FIX/data/apps/$P/$A/$TAG/.env.sh"

cat > "$FIX/env" <<EOF
SERVER=remove-test
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
existing () { docker ps -aq --filter "label=com.docker.compose.project=$NS" | wc -l | tr -d ' '; }
requests () { grep -c "$1" "$FIX/core/requests.log" || true; }

set +e

# 0. sobe a app (o restart faz `up -d` dos serviços sem contêiner) e grava o dado do volume nomeado
io restart $B > "$FIX/out"; [ "$(running)" = "1" ]; check 'io restart sobe a app' $?
docker volume inspect ${NS}_cache > /dev/null 2>&1; check 'o Compose criou o volume nomeado (sem external)' $?
docker run --rm -v ${NS}_cache:/cache busybox sh -c 'echo nomeado > /cache/state'

# 1. stop: marca local, aviso ao core com state stopped e a versão
: > "$FIX/core/requests.log"
io stop $B > "$FIX/out"
grep -q 'is UNDEPLOYED and OFFLINE' "$FIX/out"; check 'io stop termina com sucesso' $?
[ "$(running)" = "0" ]; check 'io stop: nenhum contêiner rodando' $?
[ -f "$FIX/data/.stopped/${NS}.json" ]; check 'io stop grava /data/.stopped/<ns>.json' $?
grep '"method": "POST"' "$FIX/core/requests.log" | grep -q '\\"state\\":\\"stopped\\"'; check 'io stop avisa o core: state stopped' $?
grep '"method": "POST"' "$FIX/core/requests.log" | grep -q "\\\\\"version\\\\\":\\\\\"$TAG\\\\\""; check 'o aviso leva a versão parada' $?
grep -q '"auth": "Bearer test"' "$FIX/core/requests.log"; check 'autenticado com o GITLAB_TOKEN' $?
grep -q 'io remove' "$FIX/out"; check 'io stop sugere o io remove' $?

# 2. com a build parada, saúde e CVEs não são relatadas (nem pelo daemon)
: > "$FIX/core/requests.log"
io health $B > "$FIX/out"; grep -q "stopped with 'io stop'" "$FIX/out"; check 'io health pula a build parada' $?
io health:daemon --all > "$FIX/out"; [ "$(requests POST)" = "0" ] && ! grep -q CRITICAL "$FIX/out"; check 'health:daemon não relata a build parada' $?
io cve $B > "$FIX/out"; grep -q "stopped with 'io stop'" "$FIX/out"; check 'io cve pula a build parada' $?

# 3. restart: tira a marca e avisa o core
: > "$FIX/core/requests.log"
io restart $B > "$FIX/out"
[ "$(running)" = "1" ]; check 'io restart traz a build de volta' $?
[ ! -f "$FIX/data/.stopped/${NS}.json" ]; check 'io restart apaga a marca de parada' $?
grep '"method": "POST"' "$FIX/core/requests.log" | grep -q '\\"state\\":\\"running\\"'; check 'io restart avisa o core: state running' $?
io health $B > "$FIX/out"; grep -q 'Reported demo/web@alpha' "$FIX/out"; check 'depois do restart, a saúde volta a ser relatada' $?

# 4. remove: recusas antes de qualquer mudança
: > "$FIX/core/requests.log"
io remove $B > "$FIX/out"; grep -q "use '--yes'" "$FIX/out" && [ "$(running)" = "1" ] && [ "$(requests DELETE)" = "0" ]; check 'io remove fora de terminal sem --yes: recusa e não mexe em nada' $?
io remove --all --yes > "$FIX/out"; grep -q "does not accept '--all'" "$FIX/out" && [ "$(running)" = "1" ]; check 'io remove --all é recusado' $?
io remove $B --force > "$FIX/out"; grep -q "Unknown parameter" "$FIX/out" && [ "$(running)" = "1" ]; check 'opção desconhecida é recusada' $?

# 5. remove de verdade
io remove $B --yes > "$FIX/out"
cp "$FIX/out" "$FIX/remove.out"
grep -q 'was removed from this server and from the platform' "$FIX/out"; check 'io remove termina com sucesso' $?
[ "$(existing)" = "0" ]; check 'io remove: nenhum contêiner da build sobrou' $?
grep 'COMMAND >' "$FIX/out" | grep -E -q -- ' (-v|--volumes)( |$)| volume rm| prune'; [ $? -ne 0 ]; check 'nenhum comando com -v, --volumes, volume rm ou prune' $?
[ "$(requests DELETE)" = "1" ] && grep -q '"path": "/releaser/build/demo/web/alpha?server=remove-test"' "$FIX/core/requests.log"; check 'io remove pede ao core que esqueça a build (DELETE)' $?
[ ! -f "$FIX/data/builds/${NS}.json" ] && [ -f "$FIX/data/builds/${NS}.bkp" ]; check 'arquivo da build desativado (.json → .bkp)' $?
docker volume inspect ${NS}_data > /dev/null 2>&1 && docker volume inspect ${NS}_cache > /dev/null 2>&1; check 'os dois volumes continuam existindo' $?
[ "$(docker run --rm -v ${NS}_data:/data busybox cat /data/state)" = "externo" ]; check 'dado do volume externo intacto' $?
[ "$(docker run --rm -v ${NS}_cache:/cache busybox cat /cache/state)" = "nomeado" ]; check 'dado do volume nomeado (sem external) intacto' $?
[ "$(cat "$FIX/files/state")" = "bind" ]; check 'dado do bind mount intacto' $?
[ -f "$FIX/data/apps/$P/$A/$TAG/docker-compose.yaml" ] && [ -f "$FIX/data/apps/$P/$A/.version/$S" ]; check 'clone da versão e .version intactos' $?
grep -q "volume '${NS}_cache'" "$FIX/out" && grep -q "volume '${NS}_data'" "$FIX/out"; check 'a saída lista os volumes que ficaram' $?
grep -q "rename '/data/builds/${NS}.bkp'" "$FIX/out"; check 'a saída diz como trazer a build de volta' $?

# 6. a build removida sai dos daemons e do menu de builds
docker run -d --name ${NS}-intruso --label com.docker.compose.project=$NS busybox sleep 60 > /dev/null
: > "$FIX/core/requests.log"
io health:daemon --all > "$FIX/out"; [ "$(requests POST)" = "0" ]; check 'a build removida não é relatada pelo daemon, mesmo com contêiner dela no host' $?
docker rm -f ${NS}-intruso > /dev/null

# 7. remover de novo: a build já não está configurada
io remove $B --yes > "$FIX/out"; grep -q 'No builds' "$FIX/out"; check 'remover de novo: build não configurada' $?

echo
if [ "$fail" -eq 0 ]; then echo "remove-integration: all ok"; else echo "remove-integration: $fail FAIL"; exit 1; fi
