#!/usr/bin/env bash
# Teste de integração da configuração do bucket S3 por projeto (`io cloud --setup <projeto>`,
# /data/s3/<projeto>.json), em contêineres descartáveis (nada é instalado na máquina):
#
#   - imagem local do Releaser, Silo (S3 com Object Lock), MailPit e duas apps falsas (busybox), de dois
#     projetos, no mesmo Releaser;
#   - no Silo, uma chave por projeto, cada uma restrita à pasta do seu projeto (a política da documentação);
#   - só com a chave de um projeto no .env, a cópia do outro é recusada e o `io check` acusa; com o arquivo do
#     outro projeto, os dois copiam, cada um com a sua chave; arquivo inválido nunca cai na chave do servidor;
#     assistente do `bin/io` com as respostas pela entrada padrão; `--list`, `--verify`, `io info`, `io restore
#     --list` e o daemon.
#
#   tests/cloud-integration.sh        (TMPDIR numa pasta que o Docker enxergue; KEEP=1 mantém a fixture)
set -euo pipefail

cd "$(dirname "$0")/.."

ID=$$
NET=releaser-cloud-$ID
SILO=releaser-cloud-silo-$ID
MAIL=releaser-cloud-mail-$ID
IMAGE=releaser-cloud-test
SILO_IMAGE=pgsty/silo:RELEASE.2026-10-18T00-00-00Z
ROOT_PASSWORD=$(openssl rand -hex 16)
KEY_A=$(openssl rand -hex 20)
KEY_B=$(openssl rand -hex 20)
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-cloud.XXXXXX")
PA=alfa; AA=web; PB=beta; AB=api; S=alpha; TAG=1.26.11-alpha.1
NSA=${PA}_${AA}_${S}; NSB=${PB}_${AB}_${S}

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); grep -E "ERROR|CRITICAL|WARNING|FAIL|Error" "$FIX/out" | head -8 | sed 's/^/     /'; fi; }

cleanup () {
  docker rm -f "$SILO" "$MAIL" > /dev/null 2>&1 || true
  docker volume rm -f ${NSA}_backup ${NSB}_backup > /dev/null 2>&1 || true
  docker network rm "$NET" > /dev/null 2>&1 || true
  [ -n "${KEEP:-}" ] || docker run --rm -v "$FIX":/fix busybox sh -c 'rm -rf /fix/data' > /dev/null 2>&1 || true
  [ -n "${KEEP:-}" ] || rm -rf "$FIX"
}

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.11-dev.1 . > /dev/null

docker network create "$NET" > /dev/null
docker run -d --name "$SILO" --network "$NET" -e MINIO_ROOT_USER=root -e MINIO_ROOT_PASSWORD="$ROOT_PASSWORD" "$SILO_IMAGE" server /data > /dev/null
docker run -d --name "$MAIL" --network "$NET" axllent/mailpit > /dev/null

mc () { docker run --rm -i --network "$NET" -v "$FIX":/fix -e MC_HOST_silo="http://root:${ROOT_PASSWORD}@${SILO}:9000" --entrypoint mc "$SILO_IMAGE" "$@"; }

for i in $(seq 1 30); do mc ready silo > /dev/null 2>&1 && break; sleep 1; done

mc mb --with-lock --with-versioning silo/backup > /dev/null

# uma chave por projeto, restrita à pasta dele (a política de https://embrapa.io/docs/s3#keys)
policy () {
  cat > "$FIX/$1.json" <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": ["s3:GetBucketLocation", "s3:ListBucket", "s3:ListBucketVersions", "s3:ListBucketMultipartUploads"], "Resource": ["arn:aws:s3:::backup"] },
    { "Effect": "Allow", "Action": ["s3:GetObject", "s3:GetObjectVersion", "s3:PutObject", "s3:DeleteObject", "s3:DeleteObjectVersion", "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts"], "Resource": ["arn:aws:s3:::backup/$1/*"] }
  ]
}
EOF
  mc admin policy create silo "$1" "/fix/$1.json" > /dev/null
  mc admin user add silo "$1" "$2" > /dev/null
  mc admin policy attach silo "$1" --user "$1" > /dev/null
}

policy $PA "$KEY_A"
policy $PB "$KEY_B"

# /data do Releaser: uma build de cada projeto, com a cópia em nuvem ligada, o clone da versão implantada e
# um arquivo de backup no volume
mkdir -p "$FIX/data/builds"

app () {
  local p=$1 a=$2 ns=${1}_${2}_${S}

  docker volume create ${ns}_backup > /dev/null
  docker run --rm -v ${ns}_backup:/backup busybox sh -c "echo $ns | gzip > /backup/${ns}_${TAG}_2026-11-10_02-00-00.tar.gz"

  mkdir -p "$FIX/data/apps/$p/$a/.version" "$FIX/data/apps/$p/$a/$TAG"
  echo "$TAG" > "$FIX/data/apps/$p/$a/.version/$S"
  echo "{\"project\":\"$p\",\"app\":\"$a\",\"stage\":\"$S\",\"team\":[],\"auto\":{\"cloud\":true}}" > "$FIX/data/builds/${ns}.json"

  cat > "$FIX/data/apps/$p/$a/$TAG/docker-compose.yaml" <<EOF
services:
  app:
    image: busybox
    command: sleep 3600
  backup:
    image: busybox
    profiles: [ cli ]
    volumes: [ "backup:/backup" ]
    command: "true"
volumes:
  backup: { external: true, name: ${ns}_backup }
EOF

  printf 'COMPOSE_PROJECT_NAME=%s\n' "$ns" > "$FIX/data/apps/$p/$a/$TAG/.env.io"
  printf 'COMPOSE_PROJECT_NAME=%s\nCOMPOSE_PROFILES=cli\n' "$ns" > "$FIX/data/apps/$p/$a/$TAG/.env.sh"
}

app $PA $AA
app $PB $AB

# configuração do servidor: a chave do projeto alfa, que só grava na pasta dele
cat > "$FIX/env" <<EOF
SERVER=cloud-test
ORCHESTRATOR=DockerCompose
GITLAB_TOKEN=test
SMTP_HOST=$MAIL
SMTP_PORT=1025
SMTP_FROM=releaser@example.com
LOG_MAIL=ops@example.com
RETENTION=7/4/3/1
BACKUP_DIR=/root/backup
S3_ENDPOINT=http://$SILO:9000
S3_BUCKET=backup
S3_ACCESS_KEY=$PA
S3_SECRET_KEY=$KEY_A
EOF

cp "$FIX/env" "$FIX/data/.env"

run () {
  docker run --rm -i --network "$NET" --add-host o1289077.ingest.sentry.io:127.0.0.1 --env-file "$FIX/env" \
    -v "$FIX/data":/data -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" "$@" 2>&1
}

io () { run php /app/run.php "$@"; }

# assistente do bin/io (lê o /data/.env), com as respostas pela entrada padrão
setup () { run io cloud --setup "$@"; }

inside () { docker run --rm -v "$FIX/data":/data busybox sh -c "$1"; }

bucket () { mc ls --recursive silo/backup/ 2> /dev/null | awk '{print $NF}'; }

FILE_A=$PA/$AA/$S/${NSA}_${TAG}_2026-11-10_02-00-00.tar.gz
FILE_B=$PB/$AB/$S/${NSB}_${TAG}_2026-11-10_02-00-00.tar.gz

# daqui em diante, cada verificação registra o resultado sem abortar o roteiro
set +e

# 1. só a chave do alfa no .env: o check acusa a gravação recusada na pasta do beta
io check > "$FIX/out"
grep -q "ERROR > S3: listing ok, but writing denied" "$FIX/out" && grep -q "backup/$PB/$AB/$S/" "$FIX/out"; check 'check: chave do servidor não grava na pasta do outro projeto' $?
grep -q "io cloud --setup <project>" "$FIX/out"; check 'check: indica a configuração por projeto' $?

# 2. a cópia do alfa passa e a do beta é recusada, com a indicação do que fazer
io cloud --all > "$FIX/out"
grep -q "SUCCESS > All done! Build '$PA/$AA@$S': 1 file(s) uploaded" "$FIX/out"; check 'cloud: projeto da chave do servidor copia' $?
grep -q "ERROR > Unable to copy the backups of build to the bucket" "$FIX/out" && grep -q "io cloud --setup $PB" "$FIX/out"; check 'cloud: outro projeto recusado, com a indicação do --setup' $?
bucket > "$FIX/ls"; grep -qx "$FILE_A" "$FIX/ls" && ! grep -q "^$PB/" "$FIX/ls"; check 'bucket: só o arquivo do alfa' $?

# 3. assistente: projeto sem build no servidor e nome inválido são recusados
setup gama < /dev/null > "$FIX/out"; grep -q "no build of project 'gama'" "$FIX/out" && [ ! -e "$FIX/data/s3/gama.json" ]; check 'setup: projeto sem build é recusado' $?
setup 'Beta_X' < /dev/null > "$FIX/out"; grep -q "Invalid project name" "$FIX/out"; check 'setup: nome inválido é recusado' $?

# 4. assistente do beta: endpoint e bucket do servidor como sugestão (Enter), chave e segredo do projeto
printf 'y\n\n\n%s\n%s\n\n\n' "$PB" "$KEY_B" | setup $PB > "$FIX/out"
grep -q "Testing access to the bucket... .*OK" "$FIX/out"; check 'setup: teste de listagem e gravação na pasta do projeto passa' $?
grep -q "were saved in '/data/s3/$PB.json'" "$FIX/out"; check 'setup: arquivo do projeto gravado' $?
! grep -q "$KEY_B" "$FIX/out"; check 'setup: segredo não aparece na saída' $?
[ "$(inside "stat -c %a /data/s3/$PB.json")" = "600" ] && [ "$(inside 'stat -c %a /data/s3')" = "700" ]; check 'arquivo do projeto com modo 600, pasta 700' $?
inside "cat /data/s3/$PB.json" > "$FIX/json"
grep -q "\"access_key\": \"$PB\"" "$FIX/json" && grep -q "\"bucket\": \"backup\"" "$FIX/json" && ! grep -q retention "$FIX/json"; check 'arquivo: chave do projeto, bucket do servidor, retenção herdada' $?
cmp -s "$FIX/env" "$FIX/data/.env"; check '.env do servidor intacto' $?

# 5. com a chave errada, o assistente recusa e nada é gravado por cima
printf 'y\n\n\n%s\n%s\n\n\nn\n' "$PB" "chave-errada-0000" | setup $PB > "$FIX/out"
grep -q "access denied" "$FIX/out" && inside "cat /data/s3/$PB.json" | grep -q "$KEY_B"; check 'setup: chave errada é acusada e o arquivo anterior fica' $?

# 6. check: as duas configurações, cada uma testada na pasta do seu projeto
io check > "$FIX/out"
grep -q "settings of the server, S3_\* in the '.env' file" "$FIX/out" && grep -q "settings of project '$PB', /data/s3/$PB.json" "$FIX/out"; check 'check: uma linha por configuração' $?
[ "$(grep -c 'SUCCESS > S3: ok (bucket backup, listing and writing' "$FIX/out")" = "2" ]; check 'check: as duas configurações gravam' $?
grep -q "S3 ok (2 settings: server, project '$PB')" "$FIX/out"; check 'check: resumo com as duas' $?

# 7. cópia: os dois projetos, cada um com a sua chave
io cloud --all > "$FIX/out"
grep -q "SUCCESS > All done! Build '$PB/$AB@$S': 1 file(s) uploaded" "$FIX/out" && grep -q "SUCCESS > All done! Build '$PA/$AA@$S': 0 file(s) uploaded" "$FIX/out"; check 'cloud: os dois projetos copiam' $?
bucket > "$FIX/ls"; grep -qx "$FILE_A" "$FIX/ls" && grep -qx "$FILE_B" "$FIX/ls"; check 'bucket: um arquivo na pasta de cada projeto' $?
! grep -q "$KEY_A\|$KEY_B" "$FIX/out"; check 'cloud: nenhum segredo na saída' $?

# 8. --list, --verify, info e restore --list com a configuração do projeto
io cloud $PB/$AB@$S --list > "$FIX/out"; grep -q "${NSB}_${TAG}_2026-11-10_02-00-00.tar.gz" "$FIX/out" && ! grep -q "ERROR" "$FIX/out"; check '--list lê a pasta do projeto' $?
io cloud --all --verify > "$FIX/out"; [ "$(grep -c '1 file(s) verified in the bucket' "$FIX/out")" = "2" ]; check '--verify confere os dois projetos' $?
io info > "$FIX/out"; grep -q "S3 settings: own of project '$PB' (/data/s3/$PB.json, bucket 'backup' at http://$SILO:9000)" "$FIX/out" && [ "$(grep -c 'S3 settings:' "$FIX/out")" = "1" ]; check 'io info mostra a configuração própria só no projeto que a tem' $?
io restore $PB/$AB@$S --list > "$FIX/out"; grep -q "Bucket: 's3://backup/$PB/$AB/'" "$FIX/out" && grep -q "local+s3\|s3" "$FIX/out" && ! grep -q "WARNING > .*Only the local volume" "$FIX/out"; check 'restore --list lê o bucket com a chave do projeto' $?

# 9. arquivo inválido: o beta fica sem configuração e NÃO cai na chave do servidor; o alfa segue
inside "cp /data/s3/$PB.json /data/s3/$PB.keep && echo '{ \"endpoint\": ' > /data/s3/$PB.json"
docker run --rm -v ${NSB}_backup:/backup busybox sh -c "echo novo | gzip > /backup/${NSB}_${TAG}_2026-11-11_02-00-00.tar.gz"
io cloud --all > "$FIX/out"
grep -q "ERROR > The S3 bucket is not configured! Set in the file '/data/s3/$PB.json' (settings of project '$PB'): 'endpoint', 'bucket', 'access_key', 'secret_key'" "$FIX/out"; check 'arquivo inválido: acusado como não configurado' $?
grep -q "SUCCESS > All done! Build '$PA/$AA@$S'" "$FIX/out" && ! grep -q "=== $PB/$AB@$S ===" "$FIX/out"; check 'arquivo inválido: o outro projeto segue e o do arquivo fica de fora' $?
bucket > "$FIX/ls"; ! grep -q "2026-11-11" "$FIX/ls"; check 'arquivo inválido: nada enviado com a chave do servidor' $?

# 10. daemon: sem configuração é só aviso; retenção inválida do projeto vira e-mail, e o outro projeto segue
io cloud:daemon --all > "$FIX/out"
grep -q "WARNING > Cloud copy is on ('auto.cloud') for 1 build(s), but the S3 bucket is not configured in the file '/data/s3/$PB.json'" "$FIX/out"; check 'daemon: projeto sem configuração é aviso' $?
inside "sed 's/\"bucket\": \"backup\"/\"bucket\": \"backup\", \"retention\": \"sete\"/' /data/s3/$PB.keep > /data/s3/$PB.json"
io cloud:daemon --all > "$FIX/out"
grep -q "Fix 'retention' in the file '/data/s3/$PB.json'. Nothing was uploaded or deleted for the 1 build(s) that use these settings" "$FIX/out"; check 'daemon: retenção inválida do projeto acusada no arquivo dele' $?
sleep 2
docker run --rm --network "$NET" busybox wget -qO- "http://$MAIL:8025/api/v1/messages" | grep -q 'INVALID CLOUD SETTINGS'; check 'daemon: e-mail INVALID CLOUD SETTINGS' $?
bucket > "$FIX/ls"; ! grep -q "2026-11-11" "$FIX/ls"; check 'daemon: nada enviado com a configuração inválida' $?

# 11. retenção própria do projeto: 1 diário; o arquivo mais antigo sai do bucket (versão retida pelo Object Lock)
inside "sed 's/\"bucket\": \"backup\"/\"bucket\": \"backup\", \"retention\": \"1\/0\/0\/0\"/' /data/s3/$PB.keep > /data/s3/$PB.json"
io cloud $PB/$AB@$S --dry-run > "$FIX/out"
grep -q "would upload 1 file(s)" "$FIX/out" && grep -q "would delete 1 file(s)" "$FIX/out"; check 'retenção própria do projeto vale na rotação' $?
io cloud $PA/$AA@$S --dry-run > "$FIX/out"; grep -q "would delete 0 file(s)" "$FIX/out"; check 'retenção do servidor segue no outro projeto' $?

# 12. assistente: responder não e remover devolve o projeto à configuração do servidor
printf 'n\ny\n' | setup $PB > "$FIX/out"
grep -q "was removed" "$FIX/out" && [ ! -e "$FIX/data/s3/$PB.json" ]; check 'setup: remove a configuração do projeto' $?
io check > "$FIX/out"; grep -q "ERROR > S3: listing ok, but writing denied" "$FIX/out"; check 'sem o arquivo, o check volta a acusar a pasta do beta' $?

echo
[ "$fail" = "0" ] && echo "todos os testes passaram" || { echo "$fail teste(s) falharam"; cat "$FIX/out"; exit 1; }
