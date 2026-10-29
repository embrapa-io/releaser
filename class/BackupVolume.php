<?php

/**
 * Acesso ao volume externo de backup de uma build, compartilhado pelo `cleaner`
 * (listar e apagar) e pelo `cloud` (listar e enviar ao bucket S3).
 *
 * O contêiner do Releaser não tem os volumes das builds montados: cada operação
 * roda num contêiner auxiliar efêmero (`docker run --rm`) da PRÓPRIA imagem do
 * Releaser em execução, com o volume em /backup — somente leitura quando for só
 * ler ou enviar. Nada de imagem de terceiros (até a 1.26.10-11, `alpine:3`): a
 * imagem do Releaser já tem `find`/`stat` (BusyBox) e o `rclone`. A imagem é a
 * do contêiner em execução (o ID, pelo `docker inspect`), nunca uma tag fixa
 * como `latest`, que pode nem existir no host ou apontar para outra versão.
 *
 * O auxiliar só recebe as variáveis passadas explicitamente (`-e NOME`, com o
 * valor no ambiente do processo `docker`, montado por command ()): o /data/.env
 * do Releaser não vaza e segredo nenhum aparece na linha de comando (`ps`).
 */
class BackupVolume
{
    const DOCKER = '/usr/bin/docker';

    // Ponto de montagem do volume no contêiner auxiliar.
    const MOUNT = '/backup';

    // Variáveis do ambiente do Releaser repassadas aos comandos de command ():
    // as do próprio docker e as de proxy (o S3 pode exigir proxy no host).
    const INHERIT = [ 'PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy' ];

    static private $image = NULL;

    static private $container = NULL;

    /**
     * ID do contêiner do Releaser em execução: o do /proc/self/mountinfo (o
     * Docker monta /etc/hostname de /var/lib/docker/containers/<id>/) ou, se não
     * houver, o hostname (por padrão, o ID abreviado). FALSE fora de contêiner.
     */
    static public function container ()
    {
        if (self::$container !== NULL) return self::$container;

        $candidates = [];

        try
        {
            if (is_readable ('/proc/self/mountinfo') && preg_match ('#/containers/([0-9a-f]{64})/(hostname|resolv\.conf|hosts)#', (string) file_get_contents ('/proc/self/mountinfo'), $m))
                $candidates [] = $m [1];
        }
        catch (Exception $e) {}

        $candidates [] = (string) gethostname ();

        foreach ($candidates as $id)
        {
            if ($id === '') continue;

            unset ($output);

            exec (self::DOCKER .' inspect --type container --format '. escapeshellarg ('{{.Id}}|{{.Image}}') .' '. escapeshellarg ($id) .' 2> /dev/null', $output, $return);

            $parts = explode ('|', trim ((string) ($output [0] ?? '')));

            if ($return === 0 && sizeof ($parts) === 2 && $parts [0] !== '' && $parts [1] !== '')
            {
                self::$image = $parts [1];

                return self::$container = $parts [0];
            }
        }

        return self::$container = FALSE;
    }

    /**
     * Imagem do contêiner auxiliar: a do Releaser em execução ou, se não for
     * possível descobri-la (hostname trocado, sem acesso ao /proc), a da variável
     * opcional IO_RELEASER_IMAGE do /data/.env.
     */
    static public function image ()
    {
        if (self::container () !== FALSE && self::$image !== NULL) return self::$image;

        $image = trim ((string) getenv ('IO_RELEASER_IMAGE'));

        if ($image !== '') return self::$image = $image;

        throw new Exception ("Unable to find the image of this Releaser container ('docker inspect' of '". gethostname () ."' failed). Set IO_RELEASER_IMAGE in the '.env' file (e.g., 'embrapa/releaser:". (getenv ('IO_RELEASER_VERSION') ?: 'VERSION') ."')");
    }

    /**
     * Prefixo `docker run` do contêiner auxiliar com o volume montado em /backup.
     *
     * @param bool  $write   Monta o volume para escrita (só para apagar).
     * @param bool  $network Precisa de rede (rclone): usa a rede do próprio
     *                       Releaser (`container:<id>`), com o mesmo DNS, proxy e
     *                       alcance; sem rede (`none`) para listar e apagar.
     * @param array $env     Nomes das variáveis a repassar (`-e NOME`, sem valor:
     *                       o docker lê do próprio ambiente — ver command ()).
     */
    static public function docker ($volume, $write = FALSE, $network = FALSE, array $env = [])
    {
        $cmd = self::DOCKER .' run --rm';

        if (!$network)
            $cmd .= ' --network none';
        elseif (self::container () !== FALSE)
            $cmd .= ' --network '. escapeshellarg ('container:'. self::container ());

        foreach ($env as $name)
            $cmd .= ' -e '. $name;

        return $cmd .' -v '. escapeshellarg ($volume .':'. self::MOUNT . ($write ? '' : ':ro')) .' '. escapeshellarg (self::image ());
    }

    /**
     * Lista os arquivos regulares na raiz do volume: [['name' => string, 'mtime' => int, 'size' => int], ...]
     */
    static public function files ($volume)
    {
        $cmd = self::docker ($volume) .' find '. self::MOUNT .' -maxdepth 1 -type f -exec stat -c "%Y|%s|%n" {} +';

        echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Unable to list the files of volume '". $volume ."'");
        }

        $files = [];

        foreach ($output as $line)
        {
            if (!preg_match ('/^(\d+)\|(\d+)\|'. preg_quote (self::MOUNT, '/') .'\/(.+)$/', trim ($line), $m)) continue;

            $files [] = [ 'name' => $m [3], 'mtime' => intval ($m [1]), 'size' => intval ($m [2]) ];
        }

        return $files;
    }

    static public function delete ($volume, array $names)
    {
        $args = [];

        foreach ($names as $name)
            $args [] = escapeshellarg (self::MOUNT .'/'. $name);

        $cmd = self::docker ($volume, TRUE) .' rm -f -- '. implode (' ', $args);

        echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Unable to delete the files of volume '". $volume ."'");
        }
    }

    /** Espaço livre, em bytes, no disco do volume (df do auxiliar). */
    static public function free ($volume)
    {
        $cmd = self::docker ($volume) .' df -Pk '. self::MOUNT;

        exec ($cmd .' 2>&1', $output, $return);

        // Filesystem 1024-blocks Used Available Capacity Mounted-on
        $fields = preg_split ('/\s+/', trim ((string) end ($output)));

        if ($return !== 0 || sizeof ($fields) < 6 || !ctype_digit ($fields [3]))
            throw new Exception ("Unable to check the free space of volume '". $volume ."'");

        return intval ($fields [3]) * 1024;
    }

    /** Renomeia um arquivo na raiz do volume, sem sobrescrever (mv -n e conferência). */
    static public function rename ($volume, $from, $to)
    {
        $cmd = self::docker ($volume, TRUE) .' sh -c '. escapeshellarg ('test ! -e "$2" && mv -n "$1" "$2" && test -f "$2"') .' rename '. escapeshellarg (self::MOUNT .'/'. $from) .' '. escapeshellarg (self::MOUNT .'/'. $to);

        echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Unable to rename '". $from ."' to '". $to ."' in volume '". $volume ."'");
        }
    }

    /**
     * SHA-256 e leitura do tar.gz numa passada só (o arquivo pode ter vários GB):
     * o tee entrega o fluxo ao sha256sum e, por um FIFO, ao `tar -tz`, que também
     * acusa gzip truncado ou corrompido. O FIFO é criado ANTES de o tar ir para o
     * fundo (senão o tee podia criar um arquivo comum com o mesmo nome).
     *
     * @return array ['sha256' => string, 'tar' => bool, 'error' => string]
     */
    static public function inspect ($volume, $name)
    {
        $script = 'mkfifo /tmp/tar.fifo || exit 1; (tar -tzf /tmp/tar.fifo > /dev/null 2> /tmp/tar.err; echo $? > /tmp/tar.rc) & '
            .'tee /tmp/tar.fifo < "$1" | sha256sum | cut -d " " -f 1; wait; echo "TAR:$(cat /tmp/tar.rc)"; tail -n 2 /tmp/tar.err';

        $cmd = self::docker ($volume) .' sh -c '. escapeshellarg ($script) .' inspect '. escapeshellarg (self::MOUNT .'/'. $name);

        echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        $sha = trim ((string) ($output [0] ?? ''));

        $rc = NULL;

        foreach ($output as $line)
            if (preg_match ('/^TAR:(\d+)$/', trim ($line), $m)) $rc = intval ($m [1]);

        if (!preg_match ('/^[0-9a-f]{64}$/', $sha) || $rc === NULL)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Unable to read '". $name ."' in volume '". $volume ."'");
        }

        $error = implode (' ', array_slice (array_values (array_filter ($output, function ($l) use ($sha) { return trim ($l) !== $sha && !preg_match ('/^TAR:\d+$/', trim ($l)); })), -2));

        return [ 'sha256' => $sha, 'tar' => $rc === 0, 'error' => trim ($error) ];
    }

    /**
     * Separa os arquivos DESTA build ('{project}_{app}_{stage}_...') dos demais:
     * o mesmo volume/diretório pode ser compartilhado por várias builds (dica das
     * docs do Releaser) e arquivos de outras builds jamais são tocados.
     *
     * @return array [own, foreign]
     */
    static public function split (array $files, $namespace)
    {
        $prefix = $namespace .'_';

        $own = [];
        $foreign = [];

        foreach ($files as $file)
            if (strpos ($file ['name'], $prefix) === 0) $own [] = $file; else $foreign [] = $file;

        return [ $own, $foreign ];
    }

    static public function bytes (array $files)
    {
        $total = 0;

        foreach ($files as $file) $total += intval ($file ['size'] ?? 0);

        return $total;
    }

    /**
     * Executa um comando com ambiente mínimo e explícito: só as variáveis de
     * INHERIT e as de $env (ex.: credenciais do rclone). Diferente do exec (),
     * nada do /data/.env exportado no Releaser vai junto, e os valores de $env
     * não aparecem na linha de comando (`ps`) nem em arquivo.
     *
     * @return array ['code' => int, 'out' => [linhas do stdout], 'err' => [linhas do stderr]]
     */
    static public function command ($cmd, array $env = [])
    {
        $base = [];

        foreach (self::INHERIT as $name)
        {
            $value = getenv ($name);

            if ($value !== FALSE && $value !== '') $base [$name] = $value;
        }

        $process = proc_open ($cmd, [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, NULL, $env + $base);

        if (!is_resource ($process))
            throw new Exception ("Unable to run '". strtok ($cmd, ' ') ."'");

        $buffers = [ 1 => '', 2 => '' ];

        stream_set_blocking ($pipes [1], FALSE);
        stream_set_blocking ($pipes [2], FALSE);

        // lê stdout e stderr juntos (um pipe cheio travaria o processo)
        while (!feof ($pipes [1]) || !feof ($pipes [2]))
        {
            $read = array_values (array_filter ([ $pipes [1], $pipes [2] ], function ($p) { return !feof ($p); }));

            $write = NULL;
            $except = NULL;

            if (!sizeof ($read) || stream_select ($read, $write, $except, 1) === FALSE) break;

            foreach ($read as $pipe)
                $buffers [$pipe === $pipes [1] ? 1 : 2] .= (string) fread ($pipe, 65536);
        }

        fclose ($pipes [1]);
        fclose ($pipes [2]);

        $code = proc_close ($process);

        $lines = function ($text) { return array_values (array_filter (explode ("\n", str_replace ("\r", '', $text)), 'strlen')); };

        return [ 'code' => $code, 'out' => $lines ($buffers [1]), 'err' => $lines ($buffers [2]) ];
    }
}
