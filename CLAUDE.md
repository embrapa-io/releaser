# Releaser — guia para agentes de IA

Utilitário de linha de comando + _daemon_ (PHP 8.3 em Alpine, imagem `embrapa/releaser`) que faz _deploy_, _backup_, _sanitize_ e rotação de _backups_ de _builds_ do Embrapa I/O em servidores **fora** da rede de _clusters_ da plataforma. Documentação pública: https://embrapa.io/docs/releaser/ (fonte em `site/docs/releaser.md` do monorepo `embrapa.io`).

## Como o código está organizado

| Caminho | Papel |
|---|---|
| `bin/io` | _Entrypoint_ em bash. Valida `/data` montado, gera `.env`, chave SSH e `builds.json` interativamente (bloco `jq`, inclusive os defaults de `auto`) e chama `php /app/run.php "$@"`. |
| `run.php` | Roteador. Array `$_operations` (`Operation(info, método, nº de params, usage, exemplos)`) gera o menu e o `--help` automaticamente. Sufixo `:daemon` só é aceito para os comandos da _whitelist_ (`deploy`, `backup`, `sanitize`, `cleaner`). _Lock_ por operação em `/data/.lock/<op>` com _lifetime_ em `$lifetimes` — **toda operação da whitelist precisa de uma entrada em `$lifetimes`**, senão o acesso ao array lança exceção. Em modo daemon a saída é bufferizada (`ob_start`) e vira e-mail. |
| `class/Controller.php` | Um método estático por comando; resolve as _builds_ com `getBuilds($_data, $slice)` (`proj/app@stage,...` ou `--all`) e faz `require` do _script_ correspondente em `controller/`. Os controllers rodam **dentro** do método (por isso usam `self::` e as variáveis `$_builds`, `$_daemon`, `$_data`). |
| `controller/*.php` | Lógica de cada comando: laço sobre `$_builds`, leitura da versão implantada em `/data/apps/<proj>/<app>/.version/<stage>`, clone em `/data/apps/<proj>/<app>/<tag>/`, e chamada ao orquestrador. Em modo daemon a primeira linha do laço testa `auto.<comando>`. |
| `plugin/Orchestrator.php` | Classe abstrata: contrato dos orquestradores (`validate`, `deploy`, `stop`, `restart`, `backup`, `sanitize`, `cleaner`, `reference`), `CLI_SERVICES` (serviços do compose da app que **não** sobem no deploy) e a rotação de _backups_ compartilhada (`rotateBackups`, `backupVolume`, `listBackupFiles`, `deleteBackupFiles`). |
| `plugin/DockerCompose.php`, `plugin/DockerSwarm.php` | Implementações. Executam `docker`/`docker-compose` por `exec()` com caminho absoluto (`/usr/bin/...`), sempre `exec($cmd.' 2>&1', $output, $return)` + `throw` se `$return !== 0`, precedidos de `echo 'COMMAND > '...`. **Todo `docker-compose`/`docker stack` roda com `self::env ('.env.io')` (ou `'.env', '.env.sh'` etc.), que gera `env -i PATH=… HOME=… [DOCKER_*] $(cat …)`**: o `/data/.env` do Releaser é exportado no ambiente (`bin/io`, `job/*`) e o Compose dá precedência ao ambiente do processo sobre o `.env` do projeto — sem o `env -i`, variáveis como `SMTP_HOST` do Releaser vazavam para os containers das apps (bug do `--force` no `cnpgc/leantime`, set/2026). Nunca voltar a usar `env $(cat …)` direto. Validam o compose interpolado (`yaml_parse_file` do `docker-compose config`): volumes **externos** com prefixo `{proj}_{app}_{stage}_`. |
| `class/Retention.php` | Política de retenção GFS por contagem (7 diários / 4 semanais / 3 mensais), pura e testável (`Retention::plan`, `::policy`, `::timestampFromName`). |
| `class/GitLab.php`, `class/GitClient.php`, `class/Mail.php` | API do GitLab (busca de projetos/tags), clone/checkout (gera `.env.io`, `.env.sh` = `.env.io` com `COMPOSE_PROFILES=cli`, e `.env` a partir do `env` do builds.json), e-mail (`LOG_MAIL` sempre em To, `team` em CC). |
| `helper/error.php` | `set_error_handler` que **converte qualquer warning em Exception**. Consequência: ler `$_b->auto->x` inexistente aborta a execução — use `isset()`/`??`. |
| `helper/sentry.php` | Sentry (sentry.io, org `embrapa-io`, projeto `releaser`). **DSN hardcoded de propósito** (o `/data/.env` é de equipes que só fazem deploy; não deve configurar monitoramento do Releaser). `release` = versão da imagem, `environment` = `SERVER`. Um `ob_start` com callback (passthrough) transforma cada linha `PREFIXO > mensagem` em log do Sentry com atributos `operation/mode/build/project/app/stage` (contexto de build lido das linhas `=== proj/app@stage ===`). Só exceções do Releaser viram issue; `ERROR >` de build **não** (cada app tem o próprio DSN e o erro vai por e-mail). |
| `job/*` | Scripts de 13 linhas copiados pelo `Dockerfile` para `/etc/periodic/{15min,daily,monthly}` (crond do BusyBox): `deploy` (15 min), `backup` e `cleaner` (diários; ordem alfabética ⇒ backup antes de cleaner), `sanitize` (mensal). Cada um chama `run.php <cmd>:daemon --all`. |
| `Dockerfile`, `build.sh` | Imagem multi-estágio (php:8.3-cli-alpine + docker + docker-compose v2 + ext yaml). `build.sh` é interativo e publica multi-arch no Docker Hub com `--build-arg IO_RELEASER_VERSION`. |

Não há namespaces, autoload PSR-4, testes automatizados nem lint configurado. `composer` só para `vendor/` (Sentry).

## Convenções

- Estilo: `TRUE`/`FALSE`/`NULL` maiúsculos, espaço antes do parêntese de chamada (`foo ($bar)`), **tabs** em `controller/`, **4 espaços** em `class/` e `plugin/`.
- Saída: `echo` com prefixos `INFO >`, `COMMAND >`, `WARNING >`, `ERROR >`, `SUCCESS >`, `CRITICAL >`, `FINISH >`. Erros por _build_ são `echo "ERROR > ..."; continue;` — só exceções não tratadas viram e-mail `CRITICAL`.
- Versão: não há constante; vem do `--build-arg IO_RELEASER_VERSION` (formato `[0-9]+\.[0-9]{2}\.[0-9]+-[0-9]+`, ex. `1.26.9-1` = set/2026). Dev: `0.{aa}.{m}-dev.N`.
- Lint mínimo antes de commitar: `for f in run.php class/*.php plugin/*.php controller/*.php helper/*.php; do php -l $f; done`.

## Como adicionar um comando (receita usada no `cleaner`)

1. `run.php`: entrada em `$_operations`; se for rodar no daemon, adicionar à _whitelist_ do `:daemon` **e** a `$lifetimes`.
2. `class/Controller.php`: método `static public function <cmd> ($slice, ...)` que chama `getBuilds` e faz `require self::PATH.'<cmd>.php'`.
3. `controller/<cmd>.php`: copiar `backup.php`; ler `auto.<cmd>` com `isset()`; chamar `(self::singleton ()->orchestrator)::<cmd> (...)` — ou `getenv ('ORCHESTRATOR')::<cmd>` se o comando não precisar dos metadados do GitLab (caso do `cleaner`).
4. `plugin/Orchestrator.php`: método abstrato (ou concreto, se compartilhado) + implementação em `DockerCompose.php` e `DockerSwarm.php`.
5. Daemon: `job/<cmd>` + `cp` no `Dockerfile` para o diretório de `/etc/periodic` adequado; default do atributo em `bin/io` (bloco `jq` de `auto`).
6. Documentar em `site/docs/releaser.md` (comando e atributo `auto`).

## `cleaner` (rotação de backups)

- `io cleaner <builds|--all> [--dry-run]`; daemon diário com `auto.cleaner`: `false`/ausente (desligado, **padrão**), `true` (7 diários, 4 semanais, 3 mensais) ou `"undated"` (idem, e arquivos sem data no nome entram pela data de modificação).
- Acessa o volume externo de _backup_ da _build_ (`{proj}_{app}_{stage}_backup`, ou o volume `backup` do compose interpolado — atenção: `docker-compose config` omite volumes não referenciados por serviço) com `docker run --rm -v <vol>:/backup alpine:3` para listar (`find … stat -c "%Y|%s|%n"`) e apagar (`rm -f`). **Só arquivos cujo nome começa com `{proj}_{app}_{stage}_` são considerados**: o mesmo volume/diretório pode ser compartilhado por várias builds (dica das docs) e arquivos de outras builds ou sem prefixo nunca são tocados. Ao final, sumário por build com arquivos e tamanho antes/depois. Não depende do serviço `backup` da app: a data vem do nome do arquivo (`AAAA-MM-DD_HH-MM-SS` como sufixo ou `AAAA_MM_DD_HH_MM_SS` como prefixo); arquivos **sem data no nome são preservados e ignorados**, nunca apagados.
- Semântica: níveis disjuntos por **contagem** de períodos com arquivo — nunca apaga por falta de backups novos. Ver o cabeçalho de `class/Retention.php`.
