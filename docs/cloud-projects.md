# _Bucket_ S3 por projeto — texto para `site/docs/releaser.md` e `site/docs/s3.md`

Este arquivo traz o texto **pronto para transpor** à documentação pública (`site/docs/releaser.md` e `site/docs/s3.md` do monorepo `embrapa.io`), a partir da versão **1.26.11-4**. Só deve ir para o site depois que a versão estiver no Docker Hub.

O que mudou: até a `1.26.11-3`, o Releaser tinha um só par de chaves S3, no `.env`, para todas as _builds_ do servidor. A orientação do S3 próprio é **uma chave por projeto**, restrita à pasta dele no _bucket_, e um servidor pode hospedar _builds_ de vários projetos. Com a chave de um projeto no `.env`, a cópia das _builds_ dos outros era recusada, e o remendo documentado (acrescentar a pasta dos outros projetos à política de uma das chaves) desfazia o isolamento entre eles. A partir da `1.26.11-4`, cada projeto pode ter a própria configuração.

---

## Parte 1 — `site/docs/releaser.md`

### Seção "Cópia em nuvem (S3)" (`#cloud`)

Trocar a última frase do primeiro parágrafo ("O _bucket_ é configurado **uma única vez** no `.env` do **Releaser** e a cópia é ligada **por _build_**, no atributo `auto.cloud`.") por:

> O _bucket_ é configurado no `.env` do **Releaser**, para todas as _builds_ do servidor, ou [por projeto](#cloud-projects), e a cópia é ligada **por _build_**, no atributo `auto.cloud`.

Na linha de uso do comando, acrescentar `--verify` (se ainda faltar) e a forma do assistente:

```bash
docker exec -it releaser io cloud [BUILD-1,BUILD-2,...,BUILD-N | --all] [--dry-run | --list | --verify]
docker exec -it releaser io cloud --setup [PROJETO]
```

### Subseção nova, logo depois de "Configuração do _bucket_"

##### Configuração por projeto {#cloud-projects}

A partir da versão `1.26.11-4`, as _builds_ de um projeto podem usar um _bucket_ ou uma chave **só delas**, no lugar dos que estão no `.env`. É o caso do S3 da Unidade, em que cada projeto tem uma chave restrita à própria pasta (veja [Uma chave por projeto]({{ site.baseurl }}/docs/s3#keys)), num servidor que hospeda _builds_ de mais de um projeto. Também serve a um projeto que guarda os _backups_ em outro S3.

```bash
docker exec -it releaser io cloud --setup meu-projeto
```

O assistente pergunta o _endpoint_, o _bucket_, a chave, o segredo, a região e a retenção no _bucket_. O _endpoint_, o _bucket_ e a região do servidor vêm como sugestão, porque o caso comum é o mesmo S3 com outra chave. Ele **testa a listagem e a gravação na pasta do projeto** antes de salvar. Sem o nome do projeto, `io cloud --setup` mostra, em terminal, um menu com o servidor e os projetos que têm _build_ nele.

A configuração fica em `s3/<projeto>.json`, no diretório de configuração do **Releaser** (`/data/s3/` no _container_), legível só pelo dono do arquivo:

```json
{
    "endpoint": "https://s3.cnpxx.embrapa.br",
    "bucket": "backup",
    "access_key": "meu-projeto",
    "secret_key": "…",
    "region": "auto"
}
```

| Campo | Obrigatório | Equivale a |
|---|---|---|
| `endpoint` | sim | `S3_ENDPOINT` |
| `bucket` | sim | `S3_BUCKET` |
| `access_key` | sim | `S3_ACCESS_KEY` |
| `secret_key` | sim | `S3_SECRET_KEY` |
| `region` | não | `S3_REGION` (padrão `auto`) |
| `retention` | não | `S3_RETENTION`; se ausente, vale a do servidor |

Regras:

- Vale para **todas as _builds_ do projeto** no servidor, em `io cloud`, `io restore`, `io check` e `io info`. Os projetos sem arquivo seguem com a configuração do `.env`.
- O arquivo substitui o `.env` por inteiro: os campos não se misturam. Só a retenção, quando ausente, herda a do servidor.
- Um arquivo incompleto ou com JSON inválido conta como **não configurado**: a cópia do projeto para, com erro, e nunca é feita com a chave do servidor.
- Para voltar à configuração do servidor, rode `io cloud --setup meu-projeto`, responda não e confirme a remoção (ou apague o arquivo).

> **Atenção!** O arquivo contém o segredo da chave. Ele não é repassado aos _containers_ das aplicações, mas entra em qualquer cópia que se faça do diretório de configuração do **Releaser**: trate-o como o `.env`.

### Comando `check` (em "Utilização")

Trocar o trecho sobre o _bucket_ ("A partir da versão `1.26.10-12`, quando alguma variável `S3_*` está no `.env`, o teste inclui também a listagem do _bucket_…") por:

> A partir da versão `1.26.10-12`, o teste inclui também o _bucket_ da [cópia em nuvem](#cloud) e o resume na mesma linha: a listagem (com _timeout_ de 5 segundos) e, desde a `1.26.10-15`, a gravação, sem deixar nada no _bucket_. A partir da `1.26.11-4`, o teste é feito **uma vez por configuração**: a do servidor, quando alguma variável `S3_*` está no `.env`, e a de cada [projeto com configuração própria](#cloud-projects). A gravação é testada na pasta de cada projeto que usa a configuração e tem a cópia ligada, porque uma chave restrita a um projeto lista o _bucket_ inteiro e só grava na pasta dele.

E o exemplo de saída:

```
INFO > S3 bucket 'backup' at https://s3.cnpxx.embrapa.br (settings of the server, S3_* in the '.env' file)...
SUCCESS > S3: ok (bucket backup, listing and writing, 184 ms)

INFO > S3 bucket 'backup' at https://s3.cnpxx.embrapa.br (settings of project 'calda-certa', /data/s3/calda-certa.json)...
SUCCESS > S3: ok (bucket backup, listing and writing, 171 ms)

Success! API ok (95 ms) | Token ok, expires on 2027-03-01, in 129 days | SSH: ok as @releaser (310 ms) | S3 ok (2 settings: server, project 'calda-certa')
```

Acrescentar, depois do exemplo:

> Quando a chave do `.env` é de um projeto e o servidor tem _builds_ de outro com a cópia ligada, o teste acusa `listing ok, but writing denied…`, com a pasta recusada, e indica o `io cloud --setup <project>`.

### Comando `info` (em "Utilização")

Acrescentar:

> A partir da versão `1.26.11-4`, as _builds_ de um projeto com configuração própria do _bucket_ mostram também a linha `S3 settings: own of project '…'`, com o arquivo, o _bucket_ e o _endpoint_.

### Subseção "Segurança" da cópia em nuvem

Trocar o primeiro item por:

> - As credenciais ficam só no `.env` do **Releaser** e, quando há [configuração por projeto](#cloud-projects), nos arquivos de `s3/`, gravados com permissão só para o dono. Ele as entrega ao `rclone` por variáveis de ambiente do processo: não há arquivo de configuração do `rclone`, e as chaves não aparecem na linha de comando (`ps`) nem na saída, nos e-mails ou nos _logs_.

### Seção "Restore" (`#restore`)

Acrescentar, onde se fala do _bucket_:

> O _bucket_ lido é o da configuração da _build_: a do projeto, se houver, ou a do servidor.

---

## Parte 2 — `site/docs/s3.md`

### Seção "Uma chave por projeto" (`#keys`)

Trocar o parágrafo "Um servidor com **Releaser** tem um só par de chaves no `.env`. Se ele hospeda _builds_ de mais de um projeto (…), use a chave de um dos projetos e acrescente à política dela uma linha `Resource` para cada projeto do servidor (…)." por:

> Um servidor com **Releaser** que hospeda _builds_ de mais de um projeto (o `io deploy` sem parâmetros, no próprio servidor, lista as _builds_ dele) usa a chave de cada projeto nas _builds_ dele: com o **Releaser** `1.26.11-4` ou mais novo, configure cada projeto com `io cloud --setup <projeto>` ([abaixo](#releaser)). Nas versões anteriores havia um só par de chaves por servidor, no `.env`, e era preciso acrescentar à política de uma das chaves uma linha `Resource` para cada projeto do servidor, o que dava a essa chave acesso aos _backups_ dos outros. Quem fez assim deve atualizar o **Releaser**, configurar cada projeto e devolver a política à pasta de um projeto só.

### Seção "Configuração em cada servidor" (`#releaser`)

Trocar a primeira frase e o comando por:

> No servidor que vai enviar os _backups_, com o **Releaser** `1.26.11-4` ou mais novo (`io upgrade`), configure o projeto:
>
> ```bash
> io cloud --setup meu-projeto
> ```

Trocar o parágrafo do teste ("O assistente testa a **listagem** e a **gravação** antes de salvar. O teste de gravação abre e cancela um _upload multipart_ no diretório de uma _build_ do servidor, …") por:

> O assistente testa a **listagem** e a **gravação** antes de salvar. O teste de gravação abre e cancela um _upload multipart_ no diretório de uma _build_ do projeto, sem deixar nada no _bucket_: um arquivo de teste comum ficaria retido pelo Object Lock por 14 dias. A configuração fica em `s3/meu-projeto.json`, no diretório do **Releaser**, e vale para todas as _builds_ do projeto no servidor (veja [Configuração por projeto]({{ site.baseurl }}/docs/releaser#cloud-projects)). Repita o comando para cada projeto do servidor.
>
> Num servidor com _builds_ de um projeto só, tanto faz usar `io cloud --setup` (sem o nome), que grava a chave no `.env`, para todas as _builds_.

Na frase final da seção ("O `io check`, e o `check` diário por e-mail, voltam a testar listagem e gravação todo dia."), acrescentar: "na pasta de cada projeto".

A captura `assets/img/s3/23.png` (o `io cloud --setup` do laboratório do vídeo) mostra a forma sem o nome do projeto: continua válida, mas vale regravar com `io cloud --setup cnpxx-s3` quando o vídeo for revisto.
