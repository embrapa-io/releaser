# Atualização do Releaser (`io upgrade`) — texto para a seção "Atualização" de `site/docs/releaser.md`

Texto **pronto para transpor** à documentação pública do Releaser (https://embrapa.io/docs/releaser/#update), a partir da versão **1.26.10-14**. Ao transpor, ajuste os níveis de título e os blocos de destaque ao padrão da página. A seção passa a recomendar o `io upgrade`; o procedimento manual de antes fica como alternativa.

---

### Atualização

A partir da versão **1.26.10-14**, o próprio Releaser avisa quando há versão nova e se atualiza com um comando:

```bash
docker exec -it releaser io upgrade
```

Ou só `io upgrade`, com o comando `io` instalado no servidor (`io cli`). É o mesmo sentido do `--upgrade` do `io restart`: puxar uma imagem mais nova. Lá, das _builds_; aqui, do próprio Releaser.

O que acontece:

1. O Releaser consulta as versões publicadas de `embrapa/releaser` no Docker Hub, mostra de qual para qual versão vai e pede confirmação (`--yes` pula).
2. **Não atualiza no meio de uma operação**: se um _deploy_, _backup_, cópia em nuvem ou outro comando estiver rodando, recusa e diz qual. Tente de novo quando terminar.
3. Baixa a imagem nova pela **tag exata** (`docker pull embrapa/releaser:1.26.10-14`), nunca pela `latest`.
4. Um container auxiliar, da imagem nova, faz a troca: guarda a configuração do container atual (`docker inspect`, em `/data/.update/previous.json`), para o container atual, cria o novo com **a mesma configuração** (nome, diretório `/data`, _socket_ do Docker, variáveis definidas no `docker run`, política de _restart_, rede e rótulos), espera ele ficar de pé, configura a chave SSH do GitLab e roda um `io check` silencioso.
5. **Volta atrás sozinho**: se o container novo não subir, ficar reiniciando ou o `io check` não rodar até o fim, o auxiliar remove o novo e religa o anterior, com a configuração de antes. GitLab ou _bucket_ fora do ar no momento **não** contam como falha da atualização.
6. O container anterior só é removido depois que o novo passou em tudo. A imagem anterior continua no servidor (`docker image prune` a remove quando quiser).

A sessão do `docker exec` termina quando o container antigo para — é esperado: a troca continua no auxiliar. Depois de alguns segundos, rode de novo:

```bash
docker exec -it releaser io info
```

O resultado aparece no início (`Last upgrade: 1.26.10-13 -> 1.26.10-14: ok at ...`) e, uma vez, na próxima abertura do menu. Se a atualização voltou atrás, o Releaser também manda um e-mail ao `LOG_MAIL` com o registro completo, que fica em `/data/.update/upgrade.log`.

Opções:

| Opção | O que faz |
|---|---|
| `--to VERSÃO` | Atualiza para uma versão específica (ex.: `--to 1.26.10-14`), inclusive para voltar a uma anterior |
| `--dry-run` | Só mostra o que seria feito e a configuração que o container novo vai manter; não baixa, não para, não cria nada |
| `--yes` | Não pede confirmação |

Durante a troca (no máximo alguns minutos), os _jobs_ automáticos do Releaser não começam: o _deploy_ seguinte roda normalmente no container novo.

#### Aviso de versão nova

- **Menu** (`io` sem comando): uma linha em amarelo, `Update available: 1.26.10-14 (current 1.26.10-13): run 'io upgrade'`, e o comando `upgrade` destacado.
- **`io check`**: a versão aparece na linha de resumo (`Releaser update available: ...`).
- **E-mail**: o _check_ diário do _daemon_ avisa o `LOG_MAIL` **uma vez por versão nova**, nunca todo dia.

A consulta ao Docker Hub é guardada por 24 horas e tem tempo limite de 3 segundos: o menu não fica lento nem depende da rede, e falha de rede não gera erro.

#### Docker Swarm

No Swarm, o `io upgrade` faz `docker service update --image embrapa/releaser:<versão> --update-failure-action rollback` no serviço do Releaser: a especificação do serviço (montagens, _constraint_, variáveis) fica como está, só a imagem muda, e o próprio Swarm volta à versão anterior se a tarefa nova não subir. Depois, o `io check` roda na tarefa nova e, se falhar, o Releaser pede o _rollback_ do serviço. O comando precisa rodar num nó _manager_; num _worker_, o Releaser recusa e mostra o comando para rodar no _manager_.

#### Atualização manual

Use o procedimento manual quando o `io upgrade` não for possível: container do Releaser parado ou com problema (não há onde rodar o `docker exec`), Releaser sem o _socket_ do Docker montado, container criado por Docker Compose ou versões anteriores à **1.26.10-14**, que não têm o comando. No diretório do Releaser (o que está montado em `/data`):

```bash
docker stop releaser && docker rm releaser
docker pull embrapa/releaser:1.26.10-14
docker run --name releaser \
  -v $(pwd):/data \
  -v /var/run/docker.sock:/var/run/docker.sock \
  --restart unless-stopped -d \
  embrapa/releaser:1.26.10-14
docker exec -it releaser io
```

Repita no `docker run` as demais opções usadas na instalação (outras variáveis `-e`, rede, rótulos). No Docker Swarm: `docker service update --image embrapa/releaser:1.26.10-14 releaser`.

> **Servidores com o `~/releaser-update.sh`.** Faça uma última atualização com o _script_ (ou com os comandos acima) até a versão **1.26.10-14** ou mais nova. Daí em diante, use `io upgrade`: o _script_ deixa de ser necessário e pode ficar guardado como alternativa manual. O `io upgrade` funciona mesmo que o container tenha sido criado com a imagem `embrapa/releaser` sem _tag_ (a `latest`); a partir dele, o container passa a usar a _tag_ exata da versão.

#### Se a troca for interrompida

O auxiliar não depende do terminal de quem chamou. Se ele mesmo for interrompido no meio (servidor reiniciado, Docker parado), o container anterior **não é apagado**: fica parado com o nome `releaser-previous`, e a configuração dele está em `/data/.update/previous.json`. Para voltar:

```bash
docker rm -f releaser                      # o novo, se existir
docker rename releaser-previous releaser
docker update --restart unless-stopped releaser
docker start releaser
```

#### Comando `io` no servidor

Com o comando `io` instalado no servidor (`io cli`), nada muda: a primeira chamada depois da atualização regrava o _script_ para a versão nova e avisa numa linha.

### Tabela de comandos

Acrescentar:

| Comando | Descrição | _Daemon_ |
|---|---|---|
| `upgrade` | Atualiza o próprio Releaser para a versão mais nova publicada (ou `--to VERSÃO`), por um container auxiliar, com volta automática à versão anterior se a nova falhar (`--dry-run` só mostra; `--yes` não pergunta) | — (o aviso de versão nova sai no _check_ diário) |
