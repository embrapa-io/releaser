# Comando `io` no servidor — texto para a seção de instalação de `site/docs/releaser.md`

Texto **pronto para transpor** à documentação pública do Releaser, a partir da versão **1.26.10-12**. Ao transpor, ajuste os níveis de título e os blocos de destaque ao padrão da página.

---

### O comando `io` no terminal do servidor

Todo uso do Releaser passa pelo `io` dentro do container: `docker exec -it releaser io backup --all`. Para encurtar, o próprio Releaser instala no servidor um comando `io` que faz esse `docker exec` por você:

```bash
docker exec -it releaser io cli
```

Ele mostra o destino (`/usr/local/bin/io`), pede confirmação (`--yes` pula) e grava o _script_. A partir daí, direto no terminal do servidor, no `cron` ou em _scripts_:

```bash
io                       # o menu
io backup --all && io cloud --all
io cloud meu-projeto/api@release --list
```

Como funciona e o que esperar:

- O comando é um _script_ `sh` pequeno que roda `docker exec [-it | -i] releaser io "$@"`: usa `-it` só quando a entrada e a saída são um terminal (senão `-i`, para funcionar em `cron`, _pipes_ e `&&`), repassa os argumentos sem reinterpretá-los e devolve o código de saída do `io` do container.
- Procura o container pelo nome `releaser` (ou pelo que estiver em `RELEASER_CONTAINER`). Se não houver, procura pelo rótulo do serviço do Swarm (`com.docker.swarm.service.name`) ou pela imagem `embrapa/releaser` — e falha com mensagem clara se achar nenhum ou mais de um.
- Falha com mensagem clara se o Docker não responder ou se o usuário não tiver permissão (fora do grupo `docker`): nesse caso, `sudo io ...` ou acrescente o usuário ao grupo.
- O Releaser grava o _script_ por um container auxiliar efêmero da própria imagem, com `/usr/local/bin` do servidor montado. Isso não dá nenhum poder novo: quem tem o _socket_ do Docker já é, na prática, _root_ no servidor. A gravação é atômica (`.io.tmp` + `mv`), para não quebrar um `io` em execução.
- Um `/usr/local/bin/io` que **não** tenha sido gerado pelo Releaser (sem o cabeçalho `# Embrapa I/O Releaser host wrapper`) nunca é sobrescrito nem removido. `io cli --uninstall` remove o comando, só se for do Releaser.
- **Atualização automática:** o _script_ leva a versão da imagem que o gerou e a repassa em cada chamada. Quando a imagem do Releaser é atualizada, a primeira chamada pelo `io` do servidor regrava o _script_ sem perguntar e avisa numa linha (`INFO > 'io' command on this server updated to ...`). Nas chamadas normais nada extra acontece: nenhum container auxiliar, nenhum `docker exec` a mais. Chamadas diretas (`docker exec releaser io ...`), o `cron` interno e o _daemon_ não tentam atualizar nada.
- Se a gravação falhar (Docker instalado via _snap_, com AppArmor; Docker _rootless_; `/usr/local/bin` somente leitura), o Releaser não insiste: imprime a alternativa manual, que também serve para instalar o comando pela primeira vez:

```bash
docker exec releaser io cli --print | sudo tee /usr/local/bin/io > /dev/null && sudo chmod +x /usr/local/bin/io
```

Na atualização automática, essa falha é avisada uma vez por versão, sem repetir a cada chamada e sem interromper o comando pedido.

Nada no diretório `/data` do Releaser muda com o `io cli`; a instalação do Releaser continua a mesma.

> **Códigos de saída.** Hoje os comandos do Releaser terminam com código 0 mesmo quando uma _build_ falha (`ERROR >`) ou a execução é interrompida (`CRITICAL >`). Por isso, `io backup --all && io cloud --all` segue para o `cloud` mesmo depois de uma falha no `backup`. Essa mudança está proposta para uma versão futura, porque altera o comportamento de todos os comandos.

### Tabela de comandos

Acrescentar:

| Comando | Descrição | _Daemon_ |
|---|---|---|
| `cli` | Instala (ou atualiza) o comando `io` no servidor, em `/usr/local/bin/io` (`--print` só imprime o _script_; `--uninstall` remove; `--yes` não pergunta) | — |
