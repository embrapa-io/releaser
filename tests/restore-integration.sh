#!/usr/bin/env bash
# Teste de integração do `io restore`, em contêineres descartáveis (nada é instalado na máquina):
#
#   - imagem local do Releaser, Silo (S3 com Object Lock), MailPit e uma app falsa (busybox) com os serviços
#     'backup' e 'restore' no contrato da plataforma (BACKUP_FILE_TO_RESTORE);
#   - restore do volume local, do bucket (upload/), de outro estágio, --check, SHA-256 errado, tar.gz corrompido,
#     --yes sem --file e sem as flags de aceite; Pré-restore marcado, enviado ao bucket e arquivo baixado apagado.
#
#   tests/restore-integration.sh
set -euo pipefail

cd "$(dirname "$0")/.."

ID=$$
NET=releaser-restore-$ID
SILO=releaser-restore-silo-$ID
MAIL=releaser-restore-mail-$ID
IMAGE=releaser-restore-test
SILO_IMAGE=pgsty/silo:RELEASE.2026-10-18T00-00-00Z
ROOT_PASSWORD=$(openssl rand -hex 16)
FIX=$(mktemp -d "${TMPDIR:-/tmp}/releaser-restore.XXXXXX")
P=demo; A=web; S=alpha; NS=${P}_${A}_${S}; TAG=1.26.10-alpha.1

fail=0
check () { if [ "$2" = "0" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=$((fail + 1)); sed -n '1,400p' "$FIX/out" | grep -E "ERROR|CRITICAL|WARNING|FAIL" | head -8 | sed 's/^/     /'; fi; }

cleanup () {
  docker rm -f "$SILO" "$MAIL" > /dev/null 2>&1 || true
  docker volume rm -f ${NS}_data ${NS}_backup ${P}_${A}_beta_backup > /dev/null 2>&1 || true
  docker network rm "$NET" > /dev/null 2>&1 || true
  [ -n "${KEEP:-}" ] || rm -rf "$FIX"
}

trap cleanup EXIT

docker build -q -t "$IMAGE" --build-arg IO_RELEASER_VERSION=0.26.10-dev.1 . > /dev/null

docker network create "$NET" > /dev/null
docker run -d --name "$SILO" --network "$NET" -e MINIO_ROOT_USER=root -e MINIO_ROOT_PASSWORD="$ROOT_PASSWORD" "$SILO_IMAGE" server /data > /dev/null
docker run -d --name "$MAIL" --network "$NET" axllent/mailpit > /dev/null

mc () { docker run --rm -i --network "$NET" -v "$FIX":/fix -e MC_HOST_silo="http://root:${ROOT_PASSWORD}@${SILO}:9000" --entrypoint mc "$SILO_IMAGE" "$@"; }

for i in $(seq 1 30); do mc ready silo > /dev/null 2>&1 && break; sleep 1; done

mc mb --with-lock --with-versioning silo/backup > /dev/null

# volumes da app (dados e backup) e o dado inicial
docker volume create ${NS}_data > /dev/null
docker volume create ${NS}_backup > /dev/null

data () { docker run --rm -v ${NS}_data:/data busybox sh -c "$1"; }
volume () { docker run --rm -v ${NS}_backup:/backup busybox sh -c "$1"; }

data 'echo v1 > /data/state'

# /data do Releaser: .env, build e o clone da versão implantada com o compose da app
mkdir -p "$FIX/data/builds" "$FIX/data/apps/$P/$A/.version" "$FIX/data/apps/$P/$A/$TAG"
echo "$TAG" > "$FIX/data/apps/$P/$A/.version/$S"
echo "{\"project\":\"$P\",\"app\":\"$A\",\"stage\":\"$S\",\"team\":[]}" > "$FIX/data/builds/${NS}.json"

cat > "$FIX/data/apps/$P/$A/$TAG/docker-compose.yaml" <<EOF
services:
  app:
    image: busybox
    command: sleep 3600
    volumes: [ "data:/data" ]
  backup:
    image: busybox
    profiles: [ cli ]
    volumes: [ "data:/data:ro", "backup:/backup" ]
    command: sh -c 'tar -czf /backup/${NS}_${TAG}_\$\$(date -u +%Y-%m-%d_%H-%M-%S).tar.gz -C /data .'
  restore:
    image: busybox
    profiles: [ cli ]
    volumes: [ "data:/data", "backup:/backup:ro" ]
    command: sh -c 'test -f "/backup/\$\${BACKUP_FILE_TO_RESTORE}" && rm -rf /data/* && tar -xzf "/backup/\$\${BACKUP_FILE_TO_RESTORE}" -C /data && echo "Restored=\$\$(cat /data/state)"'
volumes:
  data: { external: true, name: ${NS}_data }
  backup: { external: true, name: ${NS}_backup }
EOF

printf 'COMPOSE_PROJECT_NAME=%s\n' "$NS" > "$FIX/data/apps/$P/$A/$TAG/.env.io"
printf 'COMPOSE_PROJECT_NAME=%s\nCOMPOSE_PROFILES=cli\n' "$NS" > "$FIX/data/apps/$P/$A/$TAG/.env.sh"

cat > "$FIX/env" <<EOF
SERVER=restore-test
ORCHESTRATOR=DockerCompose
GITLAB_TOKEN=test
SMTP_HOST=$MAIL
SMTP_PORT=1025
SMTP_FROM=releaser@example.com
LOG_MAIL=ops@example.com
S3_ENDPOINT=http://$SILO:9000
S3_BUCKET=backup
S3_ACCESS_KEY=root
S3_SECRET_KEY=$ROOT_PASSWORD
EOF

io () {
  docker run --rm -i --network "$NET" --add-host o1289077.ingest.sentry.io:127.0.0.1 --env-file "$FIX/env" \
    -v "$FIX/data":/data -v /var/run/docker.sock:/var/run/docker.sock "$IMAGE" php /app/run.php "$@" 2>&1
}

# daqui em diante, cada verificação registra o resultado sem abortar o roteiro
set +e

# 1. backup inicial (v1) e mudança dos dados (v2)
io backup $P/$A@$S > "$FIX/out" ; grep -q 'successfully backed up' "$FIX/out"; check 'io backup gera o arquivo v1' $?
V1=$(volume 'ls /backup' | grep "^${NS}_" | head -1)
data 'echo v2 > /data/state'

# 2. --list mostra o arquivo local
io restore $P/$A@$S --list > "$FIX/out"; grep -q "$V1" "$FIX/out"; check '--list mostra o backup do volume' $?

# 3. --yes sem --file é recusado
io restore $P/$A@$S --yes > "$FIX/out"; grep -q "requires '--file'" "$FIX/out"; check '--yes sem --file é recusado' $?

# 4. restore local: dados voltam a v1, Pré-restore marcado no volume e no bucket
io restore $P/$A@$S --file "$V1" --yes > "$FIX/out" || true
grep -q 'SUCCESS > Restored from alpha/' "$FIX/out"; check 'restore do volume local termina com SUCCESS' $?
[ "$(data 'cat /data/state')" = "v1" ]; check 'restore local devolve os dados v1' $?
grep -q "Restored=v1" "$FIX/out"; check 'saída do serviço restore aparece' $?
BEFORE=$(volume 'ls /backup' | grep '_before-restore_' | head -1)
[ -n "$BEFORE" ]; check 'Pré-restore marcado no volume' $?
mc ls silo/backup/$P/$A/$S/ | grep -q "$BEFORE"; check 'Pré-restore enviado ao bucket' $?
docker run --rm -v "$FIX/data":/d busybox cat /d/.restore/${NS}.json | grep -q '"status": "DONE"'; check 'estado local DONE' $?
[ ! -e "$FIX/data/.restore/${NS}.lock" ]; check 'trava removida' $?

# 5. upload/: migração pelo bucket
docker run --rm -v ${NS}_backup:/backup -v "$FIX":/fix busybox cp "/backup/$V1" /fix/legacy.tar.gz
mc cp /fix/legacy.tar.gz silo/backup/$P/$A/upload/legacy.tar.gz > /dev/null
data 'echo v3 > /data/state'
io restore $P/$A@$S --file upload/legacy.tar.gz --yes > "$FIX/out" || true
grep -q "requires '--accept-upload'" "$FIX/out"; check 'upload/ com --yes exige --accept-upload' $?
SHA=$(shasum -a 256 "$FIX/legacy.tar.gz" | cut -d ' ' -f 1)
io restore $P/$A@$S --file upload/legacy.tar.gz --sha256 "$SHA" --yes --accept-upload > "$FIX/out" || true
grep -q 'SUCCESS > Restored from upload/legacy.tar.gz (s3)' "$FIX/out"; check 'restore de upload/ (bucket) termina com SUCCESS' $?
[ "$(data 'cat /data/state')" = "v1" ]; check 'restore de upload/ devolve os dados' $?
! volume 'ls /backup' | grep -q 'legacy'; check 'arquivo baixado apagado do volume' $?

# 6. --check com SHA-256 errado: nada restaurado, nem Pré-restore
data 'echo v4 > /data/state'
N=$(volume 'ls /backup' | grep -c '_before-restore_')
io restore $P/$A@$S --file upload/legacy.tar.gz --check --sha256 "$(printf '0%.0s' $(seq 1 64))" --yes --accept-upload > "$FIX/out" || true
grep -q 'SHA-256 does not match' "$FIX/out"; check '--check com SHA-256 errado falha' $?
[ "$(data 'cat /data/state')" = "v4" ] && [ "$(volume 'ls /backup' | grep -c '_before-restore_')" = "$N" ]; check '--check não restaura nem faz Pré-restore' $?

# 7. tar.gz corrompido no bucket
head -c $(( $(wc -c < "$FIX/legacy.tar.gz") / 2 )) "$FIX/legacy.tar.gz" > "$FIX/bad.tar.gz"
mc cp /fix/bad.tar.gz silo/backup/$P/$A/upload/bad.tar.gz > /dev/null
io restore $P/$A@$S --file upload/bad.tar.gz --yes --accept-upload > "$FIX/out" || true
grep -q 'not a valid tar.gz' "$FIX/out"; check 'tar.gz truncado é recusado no verify' $?
[ "$(data 'cat /data/state')" = "v4" ]; check 'dados intactos depois da falha' $?

# 8. outro estágio no mesmo volume (BACKUP_DIR compartilhado)
volume "cp /backup/$V1 /backup/${P}_${A}_beta_${TAG}_2026-10-01_02-00-00.tar.gz"
io restore $P/$A@$S --file ${P}_${A}_beta_${TAG}_2026-10-01_02-00-00.tar.gz --yes > "$FIX/out" || true
grep -q "requires '--accept-cross-stage'" "$FIX/out"; check 'outro estágio com --yes exige --accept-cross-stage' $?

# 9. interativo: número do arquivo e nome da build digitados (entrada padrão)
data 'echo v5 > /data/state'
printf '9\n' | io restore $P/$A@$S > "$FIX/out"; grep -q 'Nothing done' "$FIX/out"; check 'interativo: número inválido cancela' $?
printf '1\nerrado\n' | io restore $P/$A@$S > "$FIX/out"; grep -q 'Nothing done' "$FIX/out" && [ "$(data 'cat /data/state')" = "v5" ]; check 'interativo: nome errado da build cancela' $?
printf '1\n%s\n' "$P/$A@$S" | io restore $P/$A@$S > "$FIX/out"; grep -q 'SUCCESS > Restored from' "$FIX/out"; check 'interativo: escolha e confirmação restauram' $?

# 10. io info mostra o último restore
io info > "$FIX/out"; grep -q 'Last restore: ok' "$FIX/out"; check 'io info mostra o último restore' $?

# 11. e-mails
sleep 2
COUNT=$(docker run --rm --network "$NET" busybox wget -qO- "http://$MAIL:8025/api/v1/messages" | grep -o '"Subject":"[^"]*RESTORE[^"]*"' | wc -l | tr -d ' ')
[ "$COUNT" -ge 3 ]; check "e-mails de RESTORE enviados ($COUNT)" $?

echo
[ "$fail" = "0" ] && echo "todos os testes passaram" || { echo "$fail teste(s) falharam"; cat "$FIX/out"; exit 1; }
