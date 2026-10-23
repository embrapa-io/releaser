# Releaser

Script de deploy para ambientes remotos avulsos, ou seja, fora da rede de clusters do Embrapa I/O.

Documentação de uso (instalação, `builds.json`, comandos, modo _daemon_): https://embrapa.io/docs/releaser/

## Comandos

| Comando | Descrição | Daemon (`auto`) |
|---|---|---|
| `validate` | Valida as _builds_ (_dry run_) | — |
| `deploy` | Valida, prepara e faz o _deploy_ da última _tag_ (`--force` refaz) | a cada 15 min (`auto.deploy`) |
| `stop` | Para a _stack_ (`--down` remove os containers; os volumes ficam) | — |
| `restart` | (Re)inicia a _stack_. Com `--upgrade` (ou `--update`), renova as imagens como o _deployer_ da plataforma (`pull` das imagens, inclusive _floating_ como `latest`; `build --pull` dos serviços `build:`; recria os containers) e, em seguida, varre a _build_ por CVEs e relata ao _core_ (se `stats.cve` estiver ligado). Não há `--remove` | — |
| `rollback` | Volta a _build_ para uma _tag_ anterior | — |
| `backup` | Executa o serviço `backup` da _stack_ | diário (`auto.backup`) |
| `cleaner` | Rotaciona os arquivos do volume de _backup_: mantém os últimos **7 diários, 4 semanais, 3 mensais e 1 anual** (ou o que `RETENTION` definir no `.env`, `D/S/M/A`; `--dry-run` só mostra) | diário, após o `backup` (`auto.cleaner`: `false` padrão, `true`, ou `"undated"` para incluir arquivos sem data no nome) |
| `cloud` | Copia os arquivos de _backup_ para um _bucket_ S3 compatível (AWS, R2, B2, MinIO; `S3_*` no `.env`), em `<bucket>/<projeto>/<app>/<estágio>/`, e os rotaciona lá com `S3_RETENTION` (`--dry-run` só mostra; `--list` lista o _bucket_; `--verify` confere por _hash_ os arquivos que estão nos dois lados; `--setup` faz as perguntas do _bucket_ e grava o `.env`). Não é espelho nem restaura | diário, após o `cleaner` (`auto.cloud`: `false` padrão, `true` ou `"undated"`) |
| `sanitize` | Executa o serviço `sanitize` da _stack_ | mensal (`auto.sanitize`) |
| `health` / `cve` | Relatam ao _core_ a saúde dos serviços / as CVEs (Trivy) das imagens em execução | a cada minuto / de hora em hora (`stats.health` / `stats.cve`) |
| `check` | Testa o acesso ao GitLab (API, _token_ e _clone_) e, se configurado, ao _bucket_ S3 | diário |
| `install` / `split` | Configuração guiada de uma _build_ / migra o `builds.json` para um arquivo por _build_ | — |
| `info` | Mostra o diretório de cada _build_ implantada, os últimos relatos ao _core_, a última cópia em nuvem e comandos úteis | — |
| `mail` | Testa o SMTP | — |

O menu (`io` sem comando) mostra descrições curtas por grupo; o detalhe, as opções e os exemplos de cada comando estão em `io COMMAND --help`. Cores só aparecem em terminal (desligáveis com `NO_COLOR=1`).

## Monitoramento

Todo Releaser reporta ao Sentry (sentry.io, projeto `releaser`, DSN embutido na imagem): exceções do próprio Releaser como issues e cada linha de saída como log (com `operation`, `build`, `project`, `app`, `stage`). `environment` é o `SERVER` do `/data/.env` e `release` é a versão da imagem. Erros de deploy/backup de uma _build_ **não** viram issue: cada app tem o próprio DSN e esses erros vão por e-mail à equipe.

## Desenvolvimento

- Mapa do código e convenções: [`CLAUDE.md`](CLAUDE.md).
- Lint: `for f in run.php class/*.php plugin/*.php controller/*.php helper/*.php; do php -l $f; done`.
- Testes da retenção: `php tests/retention.php` (sai com código diferente de zero se algum caso falhar).
- Texto da cópia em nuvem para a documentação pública: [`docs/cloud.md`](docs/cloud.md).
- Imagem local: `docker build -t releaser-local --build-arg IO_RELEASER_VERSION=0.26.10-dev.1 .`
- Publicação (multi-arch, Docker Hub `embrapa/releaser`): `./build.sh` (interativo; pede a versão no formato `MAJOR.AA.M-N`).

## Versões

- **1.26.10-12** (out/2026): `io cloud` copia os _backups_ de cada _build_ para um _bucket_ S3 compatível (AWS S3, Cloudflare R2, Backblaze B2, MinIO), configurado uma vez no `.env` (`S3_ENDPOINT`, `S3_BUCKET`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_REGION`, `S3_RETENTION`) e ligado por _build_ em `auto.cloud`; envia só o que falta, rotaciona o _bucket_ com a própria política e registra o resultado (no `io info`); `io check` testa o _bucket_ e o _daemon_ avisa por e-mail quando o acesso falha. Retenção com geração anual (padrão `7/4/3/1`: o primeiro _backup_ do ano a mais) e configurável por `RETENTION` (`D/S/M/A`). `cleaner` e `cloud` acessam o volume por um contêiner auxiliar da própria imagem do Releaser (sem `alpine:3`; `IO_RELEASER_IMAGE` como _fallback_), que passa a incluir o `rclone`. `io cloud --verify` confere por _hash_ os arquivos que estão no volume e no _bucket_. O assistente da primeira execução pergunta a retenção e o _bucket_ S3 (com teste de acesso) e `io cloud --setup` faz as mesmas perguntas numa instalação existente; todas as perguntas do assistente mostram a resposta efetiva em destaque (segredos mascarados, sem eco para o segredo do S3).
- **1.26.10-11** (out/2026): o relato de CVEs (`cve.services`) passa a incluir `class`, `type` e `target` (de `Results[]` do Trivy) em cada vulnerabilidade, para o _core_ separar pacotes do sistema de dependências da aplicação. Campos adicionais, compatível com o _core_ anterior.
- **1.26.10-10** (out/2026): `io restart --upgrade` (sinônimo `--update`) renova as imagens da _build_ (_pull_ das _floating_, `build --pull` dos serviços `build:`, `up -d --force-recreate`; no Swarm, também `push` e `stack deploy`) e, em seguida, varre as CVEs e relata ao _core_; sem `--remove`. Menu novo: logo, versão e servidor no cabeçalho, comandos agrupados com descrições curtas quebradas pela largura do terminal, detalhes no `--help`, cores só em terminal; o `bin/io` segue o mesmo padrão na configuração inicial. Revisão do inglês de todas as mensagens (menu, `--help`, prompts, `INFO`/`WARNING`/`ERROR`/`SUCCESS`/`CRITICAL` e assuntos dos e-mails do _daemon_: `SUCCESSFUL EXECUTION of Releaser` e `CRITICAL ERROR in Releaser`), sem mudar os prefixos.
- **1.26.10-8**: `io check` (acesso ao GitLab) no menu, em comando e no _daemon_ diário.
