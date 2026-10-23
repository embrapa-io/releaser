# Cópia em nuvem (S3) — texto para `site/docs/releaser.md`

Este arquivo traz o texto **pronto para transpor** à documentação pública do Releaser (`site/docs/releaser.md` do monorepo `embrapa.io`), a partir da versão **1.26.10-12**. Foi escrito em Markdown simples: ao transpor, ajuste os níveis de título e os blocos de destaque ao padrão da página.

Há duas partes:

1. **Seção nova** "Cópia em nuvem (S3)", para entrar logo depois da seção "Rotação de _backups_";
2. **Alterações nas seções existentes**: rotação de _backups_ (geração anual e `RETENTION`), tabela do atributo `auto`, `.env` de exemplo, `io check` e `io info`.

---

## Parte 1 — seção nova

### Cópia em nuvem (S3)

O Releaser pode copiar, todo dia, os arquivos de _backup_ de cada _build_ para um _bucket_ S3 compatível — AWS S3, Cloudflare R2, Backblaze B2, MinIO ou qualquer serviço com a API do S3 —, guardando uma cópia fora do servidor. O _bucket_ é configurado **uma única vez** no `.env` do Releaser e a cópia é ligada **por _build_**, no atributo `auto.cloud`.

```bash
docker exec -it releaser io cloud [BUILD-1,BUILD-2,...,BUILD-N | --all] [--dry-run | --list | --verify]
```

Exemplos:

```bash
# o que seria enviado e apagado, sem tocar no bucket
docker exec -it releaser io cloud --all --dry-run

# copia (e rotaciona no bucket) uma build
docker exec -it releaser io cloud meu-projeto/api@release

# lista o que há no bucket para a build
docker exec -it releaser io cloud meu-projeto/api@release --list

# confere, por hash, os arquivos que estão no volume e no bucket
docker exec -it releaser io cloud meu-projeto/api@release --verify
```

Em modo _daemon_, o comando roda uma vez por dia, **depois** do `backup` e do `cleaner`, para as _builds_ com `auto.cloud` ligado.

#### Configuração do _bucket_ (`.env`)

| Variável | Obrigatória | Descrição |
|---|---|---|
| `S3_ENDPOINT` | sim | URL da API S3 do provedor (ex.: `https://<conta>.r2.cloudflarestorage.com`, `https://s3.us-east-005.backblazeb2.com`, `https://s3.sa-east-1.amazonaws.com`, `http://minio.exemplo.br:9000`). |
| `S3_BUCKET` | sim | Nome do _bucket_ (só o nome, sem `s3://` nem barras). **O _bucket_ precisa existir**: o Releaser não o cria. |
| `S3_ACCESS_KEY` | sim | Chave de acesso (_access key ID_). |
| `S3_SECRET_KEY` | sim | Segredo da chave (_secret access key_). |
| `S3_REGION` | não | Região. Padrão `auto`, que funciona no Cloudflare R2. Na AWS e no Backblaze B2, use a região real do _bucket_ (ex.: `sa-east-1`, `us-east-005`); no MinIO, em geral, `us-east-1`. |
| `S3_RETENTION` | não | Rotação no _bucket_, no formato `D/S/M/A` (ver "Rotação de _backups_"). Se ausente ou vazia, vale o `RETENTION`; se este também faltar, `7/4/3/1`. |

A chave precisa de permissão para **listar, ler, gravar e apagar objetos** no _bucket_. Prefira uma chave restrita a esse _bucket_.

Depois de configurar, teste com `io check` (ver "Testando o acesso ao GitLab e ao _bucket_").

#### Pelo assistente (`io` na primeira execução ou `io cloud --setup`)

Não é preciso editar o `.env` à mão. Na **primeira execução** do Releaser, o assistente que cria o `.env` pergunta, depois do SMTP, a retenção local (`Backup retention on this server (daily/weekly/monthly/yearly) [7/4/3/1]`) e se os _backups_ devem ser copiados para um _bucket_ S3 (`Do you want to copy the backups to an S3 bucket in the cloud, owned by you? (y/N)`). Respondendo sim, pede o _endpoint_, o _bucket_, a chave, o segredo (sem eco), a região (padrão `auto`, ou a região lida do _endpoint_ da AWS e do B2) e a retenção no _bucket_ (padrão: a local), **testa o acesso** (`rclone lsd`, 5 segundos) e mostra `OK` ou o motivo da falha, deixando corrigir ou seguir assim mesmo. Só as variáveis respondidas vão para o `.env` (nenhuma `S3_*` fica vazia quando a resposta é não), e a tela de confirmação mostra os segredos mascarados.

Numa **instalação existente**, o mesmo bloco de perguntas roda com:

```bash
docker exec -it releaser io cloud --setup
```

Ele acrescenta ou atualiza `RETENTION` e as `S3_*` no `.env`, preservando as outras linhas e a permissão do arquivo (Enter mantém o valor atual; respondendo não com um _bucket_ já configurado, pergunta se remove as `S3_*`). O _daemon_ lê o `.env` na próxima execução.

Em terminal, cada pergunta do assistente é reescrita com a resposta efetiva (`Yes`/`No`, o valor ou o padrão, segredos mascarados); com respostas pela entrada padrão ou saída redirecionada, sai uma linha simples, sem códigos de escape (e `NO_COLOR=1` desliga as cores).

#### Ligando a cópia por _build_ (`auto.cloud`)

No arquivo da _build_ (`builds/<projeto>_<app>_<estágio>.json`):

```json
"auto": {
  "deploy": true,
  "backup": true,
  "sanitize": true,
  "cleaner": true,
  "cloud": true
}
```

| Valor de `auto.cloud` | Comportamento no _daemon_ |
|---|---|
| `false` ou ausente (**padrão**) | Não copia. |
| `true` | Copia diariamente e rotaciona o _bucket_ com `S3_RETENTION`. |
| `"undated"` | Idem, e arquivos **sem data no nome** entram na rotação do _bucket_ pela data de modificação (como no `cleaner`). |

Em execução manual (`io cloud ...`), o comando roda para as _builds_ indicadas mesmo com `auto.cloud` desligado.

#### Organização no _bucket_

```
<bucket>/<projeto>/<app>/<estágio>/<arquivo>
```

Por exemplo, `meus-backups/meu-projeto/api/release/meu-projeto_api_release_1.26.9-1_2026-10-23_02-00-00.tar.gz`. Assim, um mesmo _bucket_ pode servir a vários servidores e a várias _builds_.

Como no `cleaner`, **só são considerados os arquivos cujo nome começa com `<projeto>_<app>_<estágio>_`**, tanto no volume quanto no _bucket_. Arquivos de outras _builds_ ou com outro nome nunca são enviados nem apagados.

#### Como funciona

A cada execução, para cada _build_:

1. lista os arquivos da _build_ no volume de _backup_;
2. lista os arquivos da _build_ no _bucket_;
3. envia **só o que falta** no _bucket_ (nunca sobrescreve um arquivo que já está lá);
4. aplica a rotação (`S3_RETENTION`) **ao que está no _bucket_** e apaga o excedente.

Arquivos que faltam no _bucket_ mas que a rotação dele já descartaria (por exemplo, quando o volume guarda mais _backups_ que o _bucket_) não são enviados — seriam enviados e apagados de novo a cada dia. Arquivos sem data no nome são enviados e nunca apagados do _bucket_ (salvo com `"undated"`).

Ao final, um resumo por _build_: arquivos e _bytes_ enviados, mantidos e apagados, e o tamanho do _bucket_ antes e depois. O resultado da última cópia aparece no `io info`.

Falha de rede ou de permissão numa _build_ é registrada como erro **daquela _build_** (as outras seguem). Nesse caso, nada é apagado do _bucket_.

#### O que a cópia em nuvem **não** faz

- **Não é um espelho** do volume: nada é apagado do _bucket_ porque saiu do volume (por exemplo, pela rotação local do `cleaner`). O _bucket_ tem a própria rotação, `S3_RETENTION`, que pode guardar mais (ou menos) que o volume.
- **Não restaura** _backups_: para restaurar, baixe o arquivo do _bucket_ (pelo painel do provedor ou por um cliente S3, como `rclone` ou `aws s3 cp`) e use o procedimento de restauração da aplicação.
- **Não cria o _bucket_** nem configura regras de ciclo de vida, versionamento ou criptografia no provedor.
- **Não gera _backups_**: copia os arquivos que o serviço `backup` da aplicação gravou no volume.

#### Exemplo: Cloudflare R2

1. No painel da Cloudflare, em **R2**, crie o _bucket_ (ex.: `releaser-backups`).
2. Em **R2 > Manage API tokens**, crie um _token_ com permissão **Object Read & Write**, restrito ao _bucket_. Anote o _Access Key ID_, o _Secret Access Key_ e o _endpoint_ S3 (`https://<ID da conta>.r2.cloudflarestorage.com`).
3. No `.env` do Releaser:

```bash
S3_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
S3_BUCKET=releaser-backups
S3_ACCESS_KEY=0f1e2d3c4b5a69788796a5b4c3d2e1f0
S3_SECRET_KEY=exemplo-de-segredo-nao-use-este-valor-0123456789abcdef
S3_RETENTION=30/8/12/3
```

`S3_REGION` pode ficar de fora (o padrão, `auto`, é o que o R2 espera).

#### Exemplo: MinIO

Com o MinIO acessível pelo servidor (ex.: `http://minio.unidade.embrapa.br:9000`), crie o _bucket_ e uma chave com acesso a ele (pelo console do MinIO ou com o `mc`):

```bash
mc alias set local http://minio.unidade.embrapa.br:9000 ADMIN SENHA-DO-ADMIN
mc mb local/releaser-backups
mc admin user add local releaser exemplo-de-segredo-nao-use
mc admin policy attach local readwrite --user releaser
```

No `.env` do Releaser:

```bash
S3_ENDPOINT=http://minio.unidade.embrapa.br:9000
S3_BUCKET=releaser-backups
S3_ACCESS_KEY=releaser
S3_SECRET_KEY=exemplo-de-segredo-nao-use
S3_REGION=us-east-1
```

#### Exemplo de saída

```
=== meu-projeto/api@release ===

INFO > Checking if build 'meu-projeto/api@release' has been deployed...
INFO > Copying backups to the bucket (rotation there: keep last 7 daily, 4 weekly, 3 monthly and 1 yearly)...
INFO > Backup volume: 'meu-projeto_api_release_backup'
INFO > Bucket: 's3://releaser-backups/meu-projeto/api/release/' at 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com'
INFO > Files of this build: 15 in the volume (2,1 GB), 15 in the bucket (2,1 GB).
INFO > Uploading 1 file(s) (143,2 MB):
  143,2 MB  meu-projeto_api_release_1.26.9-1_2026-10-23_02-00-00.tar.gz
  INFO  : meu-projeto_api_release_1.26.9-1_2026-10-23_02-00-00.tar.gz: Copied (new)
INFO > Keeping 15 of 16 backup file(s) in the bucket [D = daily, W = weekly, M = monthly, Y = yearly]:
  D   143,2 MB  meu-projeto_api_release_1.26.9-1_2026-10-23_02-00-00.tar.gz
  D   142,9 MB  meu-projeto_api_release_1.26.9-1_2026-10-22_02-00-00.tar.gz
  ...
  Y   120,4 MB  meu-projeto_api_release_1.26.1-3_2026-01-01_02-00-00.tar.gz
INFO > Deleting 1 file(s) from the bucket (141,7 MB):
  141,7 MB  meu-projeto_api_release_1.26.9-1_2026-10-16_02-00-00.tar.gz
SUCCESS > All done! Build 'meu-projeto/api@release': 1 file(s) uploaded, 15 kept and 1 deleted in the bucket.

INFO > Summary:

meu-projeto/api@release
uploaded 1 file(s), 143,2 MB
kept 15, deleted 1 file(s), 141,7 MB
bucket: 15 → 15 file(s), 2,1 GB → 2,1 GB
```

E `io cloud meu-projeto/api@release --list`, com a marca que a próxima rotação dará a cada arquivo (`-` = será apagado; `?` = sem data no nome, nunca apagado):

```
INFO > 16 file(s), 2,2 GB [D/W/M/Y = kept by the next rotation, - = deleted by it, ? = no date in the name, never deleted]:
  D   143,2 MB  2026-10-23 02:00  meu-projeto_api_release_1.26.9-1_2026-10-23_02-00-00.tar.gz
  ...
  -   141,7 MB  2026-10-16 02:00  meu-projeto_api_release_1.26.9-1_2026-10-16_02-00-00.tar.gz
  ?   12,0 MB   2026-03-02 15:10  meu-projeto_api_release_dump-antes-da-migracao.sql.gz
```

#### Conferindo a cópia (`--verify`)

`io cloud <build> --verify` compara, por _hash_ (MD5), cada arquivo da _build_ que existe **no volume e no _bucket_** — os que estão só de um lado são contados e listados, mas não são erro (a retenção do _bucket_ pode guardar mais ou menos que a do volume). Nada é enviado nem apagado. Qualquer arquivo diferente aparece como `DIFFER` e a _build_ termina em `ERROR`:

```
INFO > Files of this build: 11 in the volume, 7 in the bucket, 7 in both (4 only in the volume, 0 only in the bucket).
INFO > Verifying 7 file(s) by hash (MD5), volume against bucket...
  OK      143,2 MB  meu-projeto_api_release_1.26.9-1_2026-10-23_02-00-00.tar.gz
  DIFFER  142,9 MB  meu-projeto_api_release_1.26.9-1_2026-10-22_02-00-00.tar.gz
  ...
ERROR > Verification of build failed. 1 of 7 file(s) differ between the volume and the bucket (or could not be verified): ...!
```

A conferência lê os arquivos inteiros no volume (para calcular o MD5) e consulta o _hash_ guardado pelo provedor; em _buckets_ grandes, demora o tempo de ler os arquivos. O envio normal já confere o MD5 de cada arquivo ao terminar (o `rclone` não é chamado com `--ignore-checksum` nem `--size-only`), inclusive nos envios em partes (_multipart_), em que o MD5 fica gravado como metadado do objeto.

#### Segurança

- As credenciais ficam só no `.env` do Releaser. O Releaser as entrega ao `rclone` por variáveis de ambiente do processo: não há arquivo de configuração do `rclone`, e as chaves não aparecem na linha de comando (`ps`) nem na saída, nos e-mails ou nos _logs_.
- As variáveis do `.env` do Releaser não são repassadas aos containers das aplicações.
- O volume de _backup_ é montado **somente leitura** para a cópia.

---

## Parte 2 — alterações nas seções existentes

### Rotação de _backups_ (`cleaner`)

Substituir a descrição da política (7 diários, 4 semanais e 3 mensais) por:

> O `cleaner` mantém, por padrão, os últimos **7 diários, 4 semanais, 3 mensais e 1 anual**. O anual é o **primeiro _backup_ de cada um dos anos mais recentes** que têm _backup_ (com 1, o primeiro do ano corrente; com 2, também o primeiro do ano anterior). Assim como o mensal, que guarda o primeiro _backup_ de cada mês, ele não muda ao longo do ano. Quem já usa o `cleaner` passa a guardar **um arquivo a mais** (o primeiro do ano), salvo quando ele já está entre os mensais.
>
> A quantidade de cada geração pode ser trocada pela variável opcional `RETENTION` do `.env`, no formato `D/S/M/A` — quatro inteiros maiores ou iguais a zero, separados por barra: quantos dias, semanas, meses e anos guardar. Exemplos:
>
> | `RETENTION` | Guarda |
> |---|---|
> | (ausente ou vazia) | `7/4/3/1`: 7 diários, 4 semanais, 3 mensais e 1 anual (padrão) |
> | `14/8/12/3` | 14 diários, 8 semanais, 12 mensais e 3 anuais |
> | `7/4/3/0` | a política anterior à 1.26.10-12, sem anual |
> | `0/0/0/5` | só o primeiro _backup_ de cada um dos 5 anos mais recentes |
>
> Valor malformado (menos de quatro números, número não inteiro ou negativo) **interrompe a rotação com um erro, sem apagar nada**. No _daemon_, se alguma _build_ tem a rotação ligada, o erro vai por e-mail.
>
> A contagem é sempre de **períodos com _backup_**: um ano (ou mês, semana, dia) sem nenhum _backup_ não conta. Por isso, a falta de _backups_ novos nunca provoca exclusão.

Na legenda da listagem de exemplo, acrescentar `Y = yearly` (e as marcas combinadas, como `MY`, quando o primeiro do ano também é o mensal).

### Tabela do atributo `auto`

Acrescentar a linha:

| Atributo | Padrão | Descrição |
|---|---|---|
| `auto.cloud` | `false` | Cópia diária dos _backups_ para o _bucket_ S3 configurado no `.env` (`true`, ou `"undated"` para incluir na rotação do _bucket_ arquivos sem data no nome). Ver "Cópia em nuvem (S3)". |

No exemplo de arquivo de _build_, o bloco `auto` passa a ter `"cloud": false`.

### `.env` de exemplo

Acrescentar, depois das variáveis existentes (valores fictícios):

```bash
# Rotação dos backups no volume (cleaner): D/S/M/A, padrão 7/4/3/1
RETENTION=7/4/3/1

# Cópia em nuvem (io cloud): bucket S3 compatível
S3_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com
S3_BUCKET=releaser-backups
S3_ACCESS_KEY=0f1e2d3c4b5a69788796a5b4c3d2e1f0
S3_SECRET_KEY=exemplo-de-segredo-nao-use-este-valor-0123456789abcdef
S3_REGION=auto
S3_RETENTION=30/8/12/3

# Opcional: imagem do contêiner auxiliar que acessa os volumes de backup, se o
# Releaser não conseguir descobrir a própria (ex.: container com --hostname trocado)
# IO_RELEASER_IMAGE=embrapa/releaser:1.26.10-12
```

Nota para a seção (ou para "Rotação de _backups_"): o `cleaner` e o `cloud` acessam o volume de _backup_ com um container auxiliar efêmero da **própria imagem do Releaser em execução** (não baixam mais a imagem `alpine:3`).

### Testando o acesso (`io check`)

Acrescentar:

> Quando alguma variável `S3_*` está no `.env`, o `io check` também testa a listagem do _bucket_ (com _timeout_ de 5 segundos) e a inclui na linha de resumo:
>
> ```
> INFO > S3 bucket 'releaser-backups' at https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com (S3_ENDPOINT)...
> SUCCESS > S3: ok (bucket releaser-backups, 184 ms)
>
> Success! API ok (95 ms) | Token ok, expires on 2027-03-01, in 129 days | SSH: ok as @releaser (310 ms) | S3 ok (bucket releaser-backups, 184 ms)
> ```
>
> Configuração incompleta, _bucket_ inexistente, chave recusada ou provedor inacessível aparecem como `ERROR`. O _daemon_ diário do `check` envia e-mail ao `LOG_MAIL` quando o acesso ao _bucket_ falha (no máximo um por dia), como já faz para o _clone_ recusado. O teste do _bucket_ não entra na linha de resumo do menu do `io` (sem comando), que continua testando só o GitLab.

### `io info`

Acrescentar, à lista do que o `io info` mostra para cada _build_:

> A última cópia em nuvem: `ok`, com data e hora, arquivos e _bytes_ enviados, arquivos apagados e o tamanho no _bucket_; `FAILED`, com o motivo e a data do último sucesso; `never run`; `disabled (auto.cloud = false)`; ou `bucket not configured`. Por exemplo:
>
> ```
> Cloud copy (S3): ok at 2026-10-23T03:10:42-03:00 (1 uploaded, 143,2 MB; 1 deleted; 15 file(s), 2,1 GB in the bucket)
> ```

### Tabela de comandos

Acrescentar:

| Comando | Descrição | _Daemon_ |
|---|---|---|
| `cloud` | Copia os _backups_ para um _bucket_ S3 e os rotaciona lá (`--dry-run` só mostra; `--list` lista o _bucket_; `--verify` confere por _hash_; `--setup` configura o _bucket_ no `.env`) | diário, após o `cleaner` (`auto.cloud`) |
