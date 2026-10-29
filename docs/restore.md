# Restore — texto para `site/docs/releaser.md`

Texto **pronto para transpor** à documentação pública do Releaser (`site/docs/releaser.md` do monorepo `embrapa.io`), a partir da versão **1.26.10-16**. Entra como seção nova "Restore" (`{#restore}`) logo depois de "Cópia em nuvem (S3)", dentro de "Backup". Na página `site/docs/s3.md`, a seção "Restore" dos _clusters_ compartilhados ganha uma linha apontando para cá.

---

#### Restore {#restore}

O comando `io restore` restaura numa _build_ um _backup_ do **volume de backup** do próprio servidor ou do **_bucket_ S3** da cópia em nuvem (`io cloud`). Ele segue o mesmo contrato e as mesmas etapas do restore dos _clusters_ compartilhados da plataforma, então a _app_ não precisa de nada além do serviço `restore` (veja [Backup e restore]({{ site.baseurl }}/docs/boilerplate#cli:backup)).

```bash
io restore <projeto>/<app>@<estágio> [--list] [--file [GRUPO/]ARQUIVO] [--from local|s3] [--sha256 HASH] [--check] [--yes] [--accept-cross-stage] [--accept-upload]
```

Exemplos:

```bash
# interativo: lista os backups, pergunta qual e pede para digitar o nome da build
io restore meu-projeto/api@release

# só lista o que pode ser restaurado
io restore meu-projeto/api@release --list

# ensaio de uma migração: baixa e confere o arquivo, sem restaurar nada
io restore meu-projeto/api@alpha --file upload/sistema-antigo.tar.gz --check --sha256 <hash>

# sem perguntas (automação)
io restore meu-projeto/api@release --file meu-projeto_api_release_1.26.10-3_2026-10-28_02-00-00.tar.gz --yes
```

##### O que pode ser restaurado

A lista junta, do mais recente para o mais antigo:

- os arquivos da **mesma _app_** no volume de backup, nos três estágios. Com a pasta `BACKUP_DIR` compartilhada, os _backups_ do release aparecem também para o alpha;
- com o S3 configurado, os arquivos de `<bucket>/<projeto>/<app>/<alpha|beta|release|upload>/`.

A pasta `upload/` do _bucket_ é para arquivos enviados à mão, como os dados de um sistema antigo, migrados para a _app_. O arquivo precisa ter **a mesma estrutura dos `.tar.gz` que o serviço `backup` da _app_ gera**, porque quem o lê é o serviço `restore`. O nome tem de terminar em `.tar.gz` e ter só letras, números, ponto, hífen e sublinhado.

Um arquivo que está nos dois lados aparece uma vez, como `local+s3`, e é restaurado do volume, sem _download_. Para forçar o do _bucket_, use `--from s3`.

##### Como funciona

1. **Confirmação.** O comando pede para digitar o nome da _build_. Arquivos de outro estágio, por exemplo o release no alpha, e da pasta `upload/` pedem uma confirmação a mais. Dados de produção num estágio de teste podem incluir dados pessoais (LGPD).
2. **Backup antes do restore.** Roda o `backup` da _build_ e marca o arquivo com `_before-restore_` antes da data. Esse arquivo fica **fora da rotação por 30 dias**, no `cleaner` e no `cloud`, e, com S3, é enviado ao _bucket_. Se o _backup_ falhar, nada é restaurado.
3. **Download**, só para arquivos do _bucket_. Exige no disco do volume o dobro do tamanho do arquivo livre e confere o tamanho baixado.
4. **Conferência.** Calcula o SHA-256 (e o compara com o `--sha256`, se informado) e lê o `.tar.gz` inteiro, numa passada só.
5. **Restore.** Roda o serviço `restore` da _app_ com `BACKUP_FILE_TO_RESTORE`, por até 12 horas, e mostra as últimas linhas da saída.

No fim, o arquivo baixado do _bucket_ é apagado do volume, porque a cópia continua no _bucket_. Um arquivo escolhido no volume nunca é apagado.

O resultado vai por e-mail (`LOG_MAIL` e a equipe da _build_) e aparece no `io info` ("Last restore"). Enquanto o restore roda, o _daemon_ não faz _deploy_, _backup_, rotação nem cópia em nuvem nessa _build_.

Para **desfazer** um restore, restaure o arquivo `_before-restore_` gerado no passo 2.

> **Atenção!** Com `--yes`, o comando não pergunta nada: `--file` é obrigatório, e restaurar arquivo de outro estágio ou de `upload/` exige `--accept-cross-stage` ou `--accept-upload`. Use em automações, com cuidado.

O `io restore` funciona com o orquestrador **Docker Compose**. No Docker Swarm, ainda não.
