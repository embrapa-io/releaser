#!/usr/bin/env bash
# Teste de integração do 'io install' diante do socket do Docker, em contêineres descartáveis (nada é instalado na
# máquina): imagem local do Releaser e um servidor falso (Python) que faz de core (GET /releaser/build, catálogos) e de
# GitLab (busca do repositório e o docker-compose.yaml da referência).
#
# O que se confere: quando o compose da app monta o socket, o 'io install' explica o risco e pergunta se autoriza (padrão:
# não); a resposta vai para "allow": { "socket": … } no arquivo da build. Com --yes nada é perguntado e o socket NÃO é
# autorizado, a não ser com --allow-socket. App que não monta o socket não gera pergunta. Sem conseguir ler o compose,
# não autoriza e avisa. No fim, o 'io info' mostra a autorização e o 'io validate' (clone de um repositório Git local,
# pela mesma leitura do arquivo da build que o deploy usa) recusa a build sem autorização, aceita a autorizada e passa a
# aceitar a primeira depois de o arquivo dela ser editado.
#
#   tests/install-integration.sh
set -euo pipefail

cd "$(dirname "$0")/.."

ID=$$
IMAGE=releaser-install-test
NET=releaser-install-$ID
FAKE=releaser-install-fake-$ID
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-install.XXXXXX")
P=demo

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); tail -12 "$FIX/out" | cut -c1-240 | sed 's/^/     /'; fi; }

cleanup () {
  docker rm -f "$FAKE" > /dev/null 2>&1 || true
  docker network rm "$NET" > /dev/null 2>&1 || true
  [ -n "${KEEP:-}" ] || rm -rf "$FIX"
}

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.11-dev.1 . > /dev/null

docker network create "$NET" > /dev/null

# Apps: as de nome 'panel…' montam o socket; as 'web…' não; 'ghost' existe no core, mas o GitLab não devolve o
# repositório. Cada caso usa uma build própria: nenhum arquivo é apagado entre os casos.
mkdir -p "$FIX/fake" "$FIX/data/builds" "$FIX/git"
cat > "$FIX/fake/fake.py" <<'PY'
import json, re
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs, unquote

def repo(path):
    app = path.split('/')[-1]
    return 1 if path.startswith('demo/panel') else 2 if path.startswith('demo/web') else None
COMPOSE = {
    1: "services:\n  panel:\n    image: busybox\n    volumes:\n      - data:/data\n      - /var/run/docker.sock:/var/run/docker.sock\nvolumes:\n  data: { external: true, name: x }\n",
    2: "services:\n  web:\n    image: busybox\n    volumes:\n      - data:/data\nvolumes:\n  data: { external: true, name: x }\n",
}

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, status, data, kind='application/json'):
        self.send_response(status); self.send_header('Content-Type', kind); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def reply(self, body, status=200): self.send(status, json.dumps(body).encode())
    def do_GET(self):
        url = urlparse(self.path); query = parse_qs(url.query); path = unquote(url.path)
        with open('/fake/requests.log', 'a') as f: f.write(self.path + '\n')
        meta = {'/metadata/boilerplates.json': [], '/metadata/clusters.json': {}, '/metadata/orchestrators.json': [{'type': 'DockerCompose', 'variables': {'COMPOSE_PROJECT_NAME': '%PROJECT_UNIX%_%APP_UNIX%_%STAGE%', 'COMPOSE_PROFILES': '%STAGE%'}}]}
        if path in meta: return self.reply(meta[path])
        m = re.match(r'^/releaser/build/([^/]+)/([^/]+)/([^/]+)$', path)
        if m:
            return self.reply({'team': [{'email': 'dev@example.com'}], 'sentry': {'dsn': 'https://' + 'a' * 32 + '@bug.embrapa.io/1'}, 'matomo': {'id': 7, 'token': 't'},
                               'settings': {'ref': '1.26.11-alpha.1', 'variables': [{'name': 'ADMIN_PASSWORD', 'type': 'PASSWORD'}]}})
        if path == '/api/v4/groups/demo': return self.reply({'id': 9, 'path': 'demo', 'name': 'demo'})
        if path == '/api/v4/projects':
            wanted = (query.get('search') or [''])[0]
            return self.reply([{'id': repo(wanted), 'path': wanted.split('/')[1], 'path_with_namespace': wanted}] if repo(wanted) else [])
        m = re.match(r'^/api/v4/projects/(\d+)/repository/files/docker-compose\.yaml/raw$', path)
        if m and int(m.group(1)) in COMPOSE and (query.get('ref') or [''])[0] == '1.26.11-alpha.1':
            return self.send(200, COMPOSE[int(m.group(1))].encode(), 'text/plain')
        self.reply({'message': '404 Not Found'}, 404)

HTTPServer(('0.0.0.0', 8080), H).serve_forever()
PY
: > "$FIX/fake/requests.log"
docker run -d --name "$FAKE" --network "$NET" -v "$FIX/fake":/fake python:3-alpine python /fake/fake.py > /dev/null

cat > "$FIX/env" <<ENV
SERVER=install-test
ORCHESTRATOR=DockerCompose
GITLAB_TOKEN=test
GITLAB_URL=http://$FAKE:8080
GITLAB_SSH=file:///git
IO_API_URL=http://$FAKE:8080
SMTP_HOST=127.0.0.1
SMTP_PORT=1025
SMTP_FROM=releaser@example.com
SMTP_TIMEOUT=1
LOG_MAIL=ops@example.com
BACKUP_DIR=/root/backup
ENV

for i in $(seq 1 30); do docker run --rm --network "$NET" busybox wget -q -O /dev/null "http://$FAKE:8080/metadata/clusters.json" 2> /dev/null && break; sleep 1; done

# a entrada padrão do 'io' é a do chamador (as respostas das perguntas)
io () {
  docker run --rm -i --network "$NET" --add-host o1289077.ingest.sentry.io:127.0.0.1 --env-file "$FIX/env" \
    -v "$FIX/data":/data -v "$FIX/git":/git -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" php /app/run.php "$@" > "$FIX/out" 2>&1
}

file () { echo "$FIX/data/builds/${P}_$1_$2.json"; }
# valor de allow.socket no arquivo da build: true, false ou 'missing'
socket () { python3 -I -c 'import json, sys; d = json.load(open(sys.argv[1])); print(json.dumps(d.get("allow", {}).get("socket", "missing")))' "$(file "$1" "$2")" 2> /dev/null || echo 'no file'; }

# repositório Git da app 'panel' (ramos alpha e beta, como o 'io validate' clona), criado num contêiner
docker run --rm --entrypoint sh -v "$FIX/git":/git "$IMAGE" -c '
  set -e
  mkdir -p /tmp/w/.embrapa /git/demo && cd /tmp/w
  git init -q -b alpha . && git config user.email test@example.com && git config user.name test
  echo "{ \"boilerplate\": \"_\", \"platform\": \"native\" }" > .embrapa/settings.json
  printf "services:\n  panel:\n    image: busybox\n    command: sleep 3600\n    ports: [ \"49999:80\" ]\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n" > docker-compose.yaml
  git add -A && git commit -q -m init && git branch beta
  git clone -q --bare /tmp/w /git/demo/panel.git' > "$FIX/git.log" 2>&1 || { echo "FALHA ao criar o repositório de teste"; cat "$FIX/git.log"; exit 1; }

set +e

# $1 = app, $2 = estágio, $3 = valor esperado de allow.socket, $4 = rótulo
expect () {
  local got; got=$(socket "$1" "$2")
  [ "$got" = "$3" ]; check "$4: allow.socket = $3 (obtido: $got)" $?
}

# trecho do 'io info' de uma build (o comando lista todas)
section () { awk -v b="=== $1 ===" 'index ($0, b) { on = 1; next } /^=== / { on = 0 } on' "$FIX/out"; }

# 1. --yes: nada é perguntado e o socket não é autorizado
io install $P/panel@alpha --yes < /dev/null
expect panel alpha false '--yes com app que monta o socket'
grep -q 'mounts the Docker socket (/var/run/docker.sock)' "$FIX/out" && grep -q 'Not authorized: while the app mounts the socket, its deploy will be refused' "$FIX/out"; check '--yes: avisa que a app monta o socket e que o deploy será recusado' $?
grep -q "SUCCESS > Build '$P/panel@alpha' configured" "$FIX/out" && ! grep -q 'is authorized to mount' "$FIX/out"; check '--yes: a build é configurada, sem o aviso de build autorizada' $?

# 2. --yes --allow-socket, nas duas ordens
io install $P/panel@beta --yes --allow-socket < /dev/null
expect panel beta true '--yes --allow-socket'
grep -q "WARNING > '--allow-socket': this build is authorized to mount the Docker socket" "$FIX/out" && grep -q 'WARNING > This build is authorized to mount the Docker socket' "$FIX/out"; check '--allow-socket: avisa do risco no começo e no fim' $?
io install --allow-socket $P/panel@release --yes < /dev/null
expect panel release true 'opções antes da build (--allow-socket build --yes)'

# 3. perguntas (sem --yes): a do socket vem antes da das senhas
printf 'y\n\n' | io install $P/panel-yes@alpha
expect panel-yes alpha true "resposta 'y'"
grep -q 'Authorize this build to mount the Docker socket? (y/N)' "$FIX/out" && grep -q 'controls this whole server, as root' "$FIX/out"; check 'a pergunta explica o risco e tem o não como padrão' $?

printf '\n\n' | io install $P/panel-enter@alpha
expect panel-enter alpha false 'Enter (padrão)'

printf 'n\n\n' | io install $P/panel-no@alpha
expect panel-no alpha false "resposta 'n'"

printf 'talvez\nsim\n\n' | io install $P/panel-retry@alpha
expect panel-retry alpha true "resposta inválida repete a pergunta; 'sim' autoriza"

io install $P/panel-eof@alpha < /dev/null
expect panel-eof alpha false 'entrada encerrada, sem --yes'

io install $P/panel@alpha --yes < /dev/null
grep -q "is already configured" "$FIX/out"; check 'build já configurada: recusada, como antes' $?
expect panel alpha false 'build já configurada continua como estava'

# 4. app que não monta o socket: sem pergunta
printf 'y\n' | io install $P/web@alpha
expect web alpha false 'app sem o socket'
! grep -q 'Authorize this build' "$FIX/out" && ! grep -q 'Docker socket' "$FIX/out"; check 'app sem o socket: nenhuma pergunta nem aviso' $?
io install $P/web@beta --yes --allow-socket < /dev/null
expect web beta true '--allow-socket em app sem o socket'
grep -q 'does not seem to mount the Docker socket' "$FIX/out"; check '--allow-socket em app sem o socket: avisa que a autorização não é precisa agora' $?

# 5. compose ilegível (repositório fora do alcance do token): não autoriza e avisa
printf 'y\n' | io install $P/ghost@alpha
expect ghost alpha false 'sem conseguir ler o compose'
grep -q 'Unable to read the docker-compose.yaml of this app' "$FIX/out" && ! grep -q 'Authorize this build' "$FIX/out"; check 'sem conseguir ler o compose: avisa e não pergunta' $?

# 6. opções inválidas não criam nada
io install $P/web@release --allow-everything < /dev/null
grep -q "Unknown parameter '--allow-everything'" "$FIX/out" && [ ! -e "$(file web release)" ]; check 'opção desconhecida: recusada, nenhum arquivo criado' $?
io install $P/web@release $P/panel@release --yes < /dev/null
grep -q "Unknown parameter '$P/panel@release'" "$FIX/out" && [ ! -e "$(file web release)" ]; check 'duas builds: recusado, nenhum arquivo criado' $?

# 7. o arquivo gravado continua completo e legível
python3 -I -c 'import json, sys; d = json.load(open(sys.argv[1])); assert d["project"] == "demo" and d["app"] == "panel" and d["stage"] == "beta" and d["team"] == ["dev@example.com"] and d["auto"]["deploy"] is True and d["stats"]["cve"] is True and len(d["env"]["ADMIN_PASSWORD"]) == 32 and d["allow"] == {"socket": True}, d' "$(file panel beta)" 2> "$FIX/out"; check 'arquivo da build: demais atributos intactos e allow = { socket: true }' $?

# 8. io info mostra a autorização (o mesmo Controller::allow que o deploy e o validate passam à validação)
io info --all < /dev/null
section "$P/panel@beta" | grep -q 'Docker socket: this build is AUTHORIZED to mount it (allow.socket = true)'; check 'io info de build autorizada: mostra a autorização' $?
section "$P/panel@alpha" | grep -q 'Configured in' && ! section "$P/panel@alpha" | grep -q 'Docker socket'; check 'io info de build não autorizada: nada sobre o socket' $?

# 9. io validate: o caminho real (arquivo da build -> Controller::allow -> validação), com o clone do ramo do estágio
io validate $P/panel@alpha < /dev/null
grep -q "ERROR > Service 'panel' mounts the Docker socket (/var/run/docker.sock), and this build is not authorized to" "$FIX/out" && grep -q "build '$P/panel@alpha' is INVALID" "$FIX/out"; check 'io validate de build não autorizada: INVALID, com a orientação' $?
io validate $P/panel@beta < /dev/null
grep -q "WARNING > Service 'panel' mounts the Docker socket (/var/run/docker.sock), authorized for this build" "$FIX/out" && grep -q "Build '$P/panel@beta' is VALID" "$FIX/out"; check 'io validate de build autorizada: VALID, com aviso' $?

# autorizar depois, editando o arquivo da build no servidor (num contêiner, como root, que é como o arquivo é mantido)
docker run --rm -v "$FIX/data":/data python:3-alpine python -c 'import json; f = "/data/builds/demo_panel_alpha.json"; d = json.load(open(f)); d["allow"] = {"socket": True}; json.dump(d, open(f, "w"), indent=4)'
io validate $P/panel@alpha < /dev/null
grep -q 'authorized for this build' "$FIX/out" && grep -q "Build '$P/panel@alpha' is VALID" "$FIX/out"; check 'depois de pôr allow.socket = true no arquivo: io validate passa' $?

echo
[ $fail -eq 0 ] && echo "TUDO OK" || echo "$fail falha(s)"
exit $fail
