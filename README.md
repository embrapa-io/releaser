# Releaser

Script de deploy para ambientes remotos avulsos, ou seja, fora da rede de clusters do Embrapa I/O.

Documentação de uso (instalação, `builds.json`, comandos, modo _daemon_): https://embrapa.io/docs/releaser/

## Comandos

| Comando | Descrição | Daemon (`auto`) |
|---|---|---|
| `validate` | Valida as _builds_ (_dry run_) | — |
| `deploy` | Valida, prepara e faz o _deploy_ da última _tag_ (`--force` refaz) | a cada 15 min (`auto.deploy`) |
| `stop` / `restart` | Derruba / (re)inicia a _stack_ | — |
| `rollback` | Volta a _build_ para uma _tag_ anterior | — |
| `backup` | Executa o serviço `backup` da _stack_ | diário (`auto.backup`) |
| `cleaner` | Rotaciona os arquivos do volume de _backup_: mantém os últimos **7 diários, 4 semanais e 3 mensais** (`--dry-run` só mostra) | diário, após o `backup` (`auto.cleaner`: `false` padrão, `true`, ou `"undated"` para incluir arquivos sem data no nome) |
| `sanitize` | Executa o serviço `sanitize` da _stack_ | mensal (`auto.sanitize`) |
| `info` | Mostra o diretório de cada _build_ implantada e comandos úteis | — |
| `mail` | Testa o SMTP | — |

## Monitoramento

Todo Releaser reporta ao Sentry (sentry.io, projeto `releaser`, DSN embutido na imagem): exceções do próprio Releaser como issues e cada linha de saída como log (com `operation`, `build`, `project`, `app`, `stage`). `environment` é o `SERVER` do `/data/.env` e `release` é a versão da imagem. Erros de deploy/backup de uma _build_ **não** viram issue: cada app tem o próprio DSN e esses erros vão por e-mail à equipe.

## Desenvolvimento

- Mapa do código e convenções: [`CLAUDE.md`](CLAUDE.md).
- Lint: `for f in run.php class/*.php plugin/*.php controller/*.php helper/*.php; do php -l $f; done`.
- Imagem local: `docker build -t releaser-local --build-arg IO_RELEASER_VERSION=0.26.9-dev.1 .`
- Publicação (multi-arch, Docker Hub `embrapa/releaser`): `./build.sh` (interativo; pede a versão no formato `MAJOR.AA.M-N`).
