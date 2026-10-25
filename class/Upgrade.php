<?php

/**
 * Atualização do próprio Releaser (`io upgrade`) e aviso de versão nova.
 *
 * Detecção: as tags de embrapa/releaser na API do Docker Hub, comparadas com
 * IO_RELEASER_VERSION pelo formato de versão (MAJOR.AA.M-N; `latest`, as de
 * desenvolvimento, `0.*` e `*-dev.*`, e qualquer tag fora do formato são
 * ignoradas). Cache em /data/.update/latest.json por 24 h; a consulta tem tempo
 * limite de 3 s e falha de rede é silenciosa (fica o cache anterior, e a próxima
 * tentativa só depois de 1 h), para o menu não ficar lento nem depender da rede.
 *
 * Troca: um contêiner não se substitui sozinho (a sessão `docker exec` de quem
 * chamou morre junto com ele). O `io upgrade` no contêiner atual só resolve a
 * versão, confere que nada está rodando, baixa a imagem nova (sempre a tag
 * exata), grava o plano em /data/.update/plan.json e lança um contêiner
 * auxiliar efêmero DA IMAGEM NOVA (`docker run -d --rm`, com o socket e o
 * /data), que faz a troca sozinho e registra o resultado em last.json:
 *
 *   - contêiner comum (docker run): guarda o `docker inspect` em previous.json,
 *     tira a política de restart do atual (senão um `always` voltaria junto com
 *     o novo num restart do Docker), para, renomeia para <nome>-previous, cria o
 *     novo pela API do Docker com a mesma configuração (Config e HostConfig do
 *     inspect, menos o que vem da imagem antiga; mesmas redes), espera ele ficar
 *     de pé, restaura a chave SSH (bin/entrypoint) e roda um `io check`
 *     silencioso. Falhou? Remove o novo, devolve nome e política ao antigo e o
 *     religa (ou, se ele foi criado com --rm e sumiu ao parar, recria-o com a
 *     imagem anterior, pelo ID). Deu certo? Remove o antigo.
 *   - Docker Swarm (serviço): `docker service update --image ...
 *     --update-failure-action rollback`, e o `io check` na tarefa nova; falhou,
 *     `docker service update --rollback`.
 *
 * Enquanto houver /data/.lock/upgrade (até 30 min), os jobs do daemon não
 * começam (run.php), para nada ser interrompido no meio da troca.
 *
 * IO_UPGRADE_TEST_REPOSITORY (SÓ PARA TESTE): repositório de um registry local
 * no lugar de embrapa/releaser (ex.: localhost:5000/releaser-test); as tags vêm
 * de http://<registry>/v2/<repositório>/tags/list. Nunca use em produção.
 */
class Upgrade
{
    const DOCKER = '/usr/bin/docker';

    const IMAGE = 'embrapa/releaser';

    const HUB = 'https://hub.docker.com/v2/repositories/%s/tags?page_size=100&ordering=last_updated';

    // Versão publicada: MAJOR.AA.M-N (ex.: 1.26.10-13); a de desenvolvimento (0.AA.M-dev.N) fica de fora.
    const FORMAT = '/^([1-9][0-9]*)\.([0-9]{2})\.([0-9]{1,2})-([0-9]+)$/';

    const TIMEOUT = 3; // segundos, consulta do menu e do daemon
    const TTL = 86400; // segundos, validade do cache
    const RETRY = 3600; // segundos, nova tentativa depois de uma falha

    const DIR = '.update';

    const LOCK = 'upgrade';
    const LOCK_LIFETIME = 30; // minutos

    // Rótulo do contêiner auxiliar (para achar uma troca em andamento).
    const LABEL = 'io.embrapa.releaser.upgrade';

    const SOCKET = '/var/run/docker.sock';

    const STOP_TIMEOUT = 30; // segundos
    const START_TIMEOUT = 90; // segundos para o contêiner novo subir
    const STABLE = 15; // segundos de pé, sem reiniciar, para contar como "subiu"

    static public function current ()
    {
        return trim ((string) getenv ('IO_RELEASER_VERSION'));
    }

    static public function repository ()
    {
        $test = trim ((string) getenv ('IO_UPGRADE_TEST_REPOSITORY'));

        return $test !== '' ? $test : self::IMAGE;
    }

    static public function reference ($version)
    {
        return self::repository () .':'. $version;
    }

    /** [major, ano, mês, n] ou FALSE se a tag não está no formato de versão publicada. */
    static public function parse ($version)
    {
        if (!preg_match (self::FORMAT, trim ((string) $version), $m)) return FALSE;

        return [ (int) $m [1], (int) $m [2], (int) $m [3], (int) $m [4] ];
    }

    static public function valid ($version)
    {
        return self::parse ($version) !== FALSE;
    }

    /** < 0, 0 ou > 0, como strcmp. Versões fora do formato são as menores. */
    static public function compare ($a, $b)
    {
        $x = self::parse ($a);
        $y = self::parse ($b);

        if ($x === FALSE || $y === FALSE) return ($x === FALSE ? 0 : 1) - ($y === FALSE ? 0 : 1);

        return $x <=> $y;
    }

    /** A versão mais nova de uma lista de tags (NULL se nenhuma está no formato). */
    static public function newest (array $tags)
    {
        $best = NULL;

        foreach ($tags as $tag)
            if (self::valid ($tag) && ($best === NULL || self::compare ($tag, $best) > 0)) $best = trim ($tag);

        return $best;
    }

    /** Consulta as tags no registro (Docker Hub ou o de teste) e devolve a versão mais nova. Lança em falha. */
    static public function fetch ($timeout = self::TIMEOUT)
    {
        $client = new GuzzleHttp\Client ([ 'connect_timeout' => $timeout, 'timeout' => $timeout, 'http_errors' => FALSE ]);

        $test = trim ((string) getenv ('IO_UPGRADE_TEST_REPOSITORY'));

        if ($test !== '')
        {
            $parts = explode ('/', $test, 2);

            $url = 'http://'. $parts [0] .'/v2/'. ($parts [1] ?? '') .'/tags/list';
        }
        else
            $url = sprintf (self::HUB, self::IMAGE);

        $response = $client->get ($url, [ 'headers' => [ 'Accept' => 'application/json' ] ]);

        if ($response->getStatusCode () !== 200)
            throw new Exception ('HTTP '. $response->getStatusCode () .' from '. parse_url ($url, PHP_URL_HOST));

        $json = json_decode ((string) $response->getBody (), TRUE);

        if (!is_array ($json))
            throw new Exception ('invalid answer from '. parse_url ($url, PHP_URL_HOST));

        $tags = $test !== '' ? ($json ['tags'] ?? []) : array_column ($json ['results'] ?? [], 'name');

        $newest = self::newest (is_array ($tags) ? $tags : []);

        if ($newest === NULL)
            throw new Exception ('no published version found in '. self::repository ());

        return $newest;
    }

    static public function dir ($data = '/data')
    {
        return rtrim ($data, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::DIR;
    }

    static public function read ($data, $name)
    {
        $file = self::dir ($data) . DIRECTORY_SEPARATOR . $name;

        try
        {
            if (!is_file ($file)) return [];

            $json = json_decode ((string) file_get_contents ($file), TRUE);

            return is_array ($json) ? $json : [];
        }
        catch (\Throwable $e)
        {
            return [];
        }
    }

    static public function write ($data, $name, $content)
    {
        try
        {
            if (!is_dir (self::dir ($data))) mkdir (self::dir ($data), 0755, TRUE);

            return file_put_contents (self::dir ($data) . DIRECTORY_SEPARATOR . $name, json_encode ($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ."\n", LOCK_EX) !== FALSE;
        }
        catch (\Throwable $e)
        {
            return FALSE;
        }
    }

    /**
     * Cache da versão mais nova: ['version', 'checked', 'checked_at', ...]. Só
     * consulta o registro se o cache venceu (24 h) e a última falha tem mais de
     * 1 h, ou com $refresh. Nunca lança: falha de rede mantém o cache anterior.
     */
    static public function latest ($data = '/data', $refresh = FALSE, $timeout = self::TIMEOUT)
    {
        if (!is_dir ($data)) return [];

        $cache = self::read ($data, 'latest.json');

        // o cache é do repositório de onde veio (troca para o de teste e de volta)
        if (($cache ['repository'] ?? self::IMAGE) !== self::repository ()) $cache = [];

        $now = time ();

        $fresh = isset ($cache ['checked']) && $now - (int) $cache ['checked'] < self::TTL;
        $failed = isset ($cache ['attempted']) && $now - (int) $cache ['attempted'] < self::RETRY;

        if (!$refresh && ($fresh || $failed)) return $cache;

        try
        {
            $version = self::fetch ($timeout);

            $cache ['version'] = $version;
            $cache ['checked'] = $now;
            $cache ['checked_at'] = date (DATE_ATOM, $now);

            unset ($cache ['attempted'], $cache ['error']);
        }
        catch (\Throwable $e)
        {
            $cache ['attempted'] = $now;
            $cache ['error'] = mb_strimwidth ($e->getMessage (), 0, 200, '...');
        }

        $cache ['repository'] = self::repository ();

        self::write ($data, 'latest.json', $cache);

        return $cache;
    }

    /** Versão mais nova que a em execução (pelo cache), ou NULL. Instalação de desenvolvimento não é avisada. */
    static public function available ($data = '/data', $refresh = FALSE, $timeout = self::TIMEOUT)
    {
        if (!self::valid (self::current ())) return NULL;

        $cache = self::latest ($data, $refresh, $timeout);

        $version = (string) ($cache ['version'] ?? '');

        return self::valid ($version) && self::compare ($version, self::current ()) > 0 ? $version : NULL;
    }

    static public function notice ($version)
    {
        return 'Update available: '. $version .' (current '. self::current () ."): run 'io upgrade'";
    }

    /** Linha do `io check`: ['status' => OK|WARNING, 'message' => ...]. */
    static public function check ($data = '/data')
    {
        $cache = self::latest ($data, TRUE, 5);

        $version = self::available ($data);

        if ($version !== NULL)
            return [ 'status' => 'WARNING', 'message' => 'update available: '. $version .' (current '. self::current () ."; run 'io upgrade')", 'version' => $version ];

        if (!self::valid (self::current ()))
            return [ 'status' => 'OK', 'message' => (self::current () !== '' ? self::current () : 'dev') .' (development version, not checked)' ];

        if (!isset ($cache ['version']))
            return [ 'status' => 'OK', 'message' => self::current () .' (latest unknown: '. ($cache ['error'] ?? 'registry not reachable') .')' ];

        return [ 'status' => 'OK', 'message' => self::current () .' is the latest'. (isset ($cache ['error']) ? ' (cached at '. ($cache ['checked_at'] ?? '?') .')' : '') ];
    }

    /** O daemon diário avisa por e-mail uma vez por versão nova: TRUE se ainda não avisou desta. */
    static public function notify ($data, $version)
    {
        $cache = self::read ($data, 'latest.json');

        if (($cache ['notified'] ?? '') === $version) return FALSE;

        $cache ['notified'] = $version;
        $cache ['notified_at'] = date (DATE_ATOM);

        self::write ($data, 'latest.json', $cache);

        return TRUE;
    }

    /** Resultado da última atualização (last.json), para o `io info` e o menu. */
    static public function last ($data = '/data')
    {
        return self::read ($data, 'last.json');
    }

    static public function describe (array $last)
    {
        if (!isset ($last ['result'])) return 'never run';

        $text = ($last ['from'] ?? '?') .' -> '. ($last ['to'] ?? '?');

        switch ($last ['result'])
        {
            case 'ok': return $text .': ok at '. ($last ['finished_at'] ?? '?');
            case 'running': return $text .': started at '. ($last ['started_at'] ?? '?') .' and not finished'. (self::stale ($last) ? ' (helper interrupted? see /data/.update/upgrade.log)' : ' (in progress)');
            case 'rolled back': return $text .': ROLLED BACK at '. ($last ['finished_at'] ?? '?') .' ('. ($last ['message'] ?? '?') .'); still on '. ($last ['from'] ?? '?');
        }

        return $text .': FAILED at '. ($last ['finished_at'] ?? '?') .' ('. ($last ['message'] ?? '?') .')';
    }

    static public function stale (array $last)
    {
        return ($last ['result'] ?? '') === 'running' && time () - strtotime ((string) ($last ['started_at'] ?? 'now')) > self::LOCK_LIFETIME * 60;
    }

    /**
     * Para o menu: o resultado da última troca, uma vez (marca 'shown'), já que
     * quem chamou o `io upgrade` perdeu a sessão no meio. NULL se nada a mostrar.
     */
    static public function unseen ($data = '/data')
    {
        $last = self::last ($data);

        if (!isset ($last ['result']) || !empty ($last ['shown'])) return NULL;

        if ($last ['result'] === 'running' && !self::stale ($last)) return $last;

        $last ['shown'] = TRUE;

        self::write ($data, 'last.json', $last);

        return $last;
    }

    /** Uma troca em andamento (lock com menos de 30 min)? Consultado pelos jobs do daemon no run.php. */
    static public function running ($data = '/data')
    {
        $lock = rtrim ($data, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .'.lock'. DIRECTORY_SEPARATOR . self::LOCK;

        try
        {
            return is_file ($lock) && time () - filemtime ($lock) < self::LOCK_LIFETIME * 60;
        }
        catch (\Throwable $e)
        {
            return FALSE;
        }
    }

    static public function lock ($data, $on)
    {
        $lock = rtrim ($data, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .'.lock'. DIRECTORY_SEPARATOR . self::LOCK;

        try
        {
            if ($on)
            {
                if (!is_dir (dirname ($lock))) mkdir (dirname ($lock), 0755, TRUE);

                file_put_contents ($lock, date (DATE_RFC822));
            }
            elseif (is_file ($lock))
                unlink ($lock);
        }
        catch (\Throwable $e) {}
    }

    /**
     * Comandos do Releaser rodando neste contêiner, pelo /proc: [pid => 'deploy:daemon', ...]
     * (os `docker exec` e os jobs do cron dividem o mesmo espaço de PIDs). Fora o próprio upgrade.
     */
    static public function processes ()
    {
        $found = [];

        foreach (glob ('/proc/[0-9]*/cmdline') ?: [] as $file)
        {
            $pid = (int) basename (dirname ($file));

            if ($pid === getmypid ()) continue;

            try
            {
                $raw = (string) file_get_contents ($file);
            }
            catch (\Throwable $e)
            {
                continue; // o processo terminou no meio da leitura
            }

            $args = explode ("\0", rtrim ($raw, "\0"));

            foreach ($args as $i => $arg)
            {
                if (basename ($arg) !== 'run.php' || !isset ($args [$i + 1])) continue;

                $op = explode (':', $args [$i + 1])[0];

                if ($op !== '' && $op !== 'upgrade') $found [$pid] = $args [$i + 1];

                break;
            }
        }

        return $found;
    }

    /**
     * O que impede a troca agora: operações com lock ativo em /data/.lock e um
     * processo rodando (lock sem processo é de um contêiner que morreu no meio:
     * fica de fora, em $stale) e comandos manuais em execução (não criam lock).
     *
     * @return array [busy => [op => motivo], stale => [op, ...]]
     */
    static public function busy ($data, array $lifetimes)
    {
        $running = self::processes ();

        $ops = [];

        foreach ($running as $pid => $op) $ops [explode (':', $op)[0]][] = $pid;

        $busy = [];
        $stale = [];

        foreach (glob (rtrim ($data, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .'.lock'. DIRECTORY_SEPARATOR .'*') ?: [] as $lock)
        {
            $op = basename ($lock);

            if ($op === self::LOCK || !isset ($lifetimes [$op])) continue;

            try
            {
                $active = time () - filemtime ($lock) < $lifetimes [$op] * 60;
            }
            catch (\Throwable $e)
            {
                continue;
            }

            if (!$active) continue;

            if (isset ($ops [$op]))
                $busy [$op] = "'". $op ."' running (lock since ". date ('Y-m-d H:i', filemtime ($lock)) .', pid '. implode (', ', $ops [$op]) .')';
            else
                $stale [] = $op;
        }

        foreach ($running as $pid => $op)
            if (!isset ($busy [explode (':', $op)[0]])) $busy [explode (':', $op)[0]] = "'". $op ."' running (pid ". $pid .')';

        return [ 'busy' => $busy, 'stale' => $stale ];
    }

    /** `docker inspect` de um contêiner ou de uma imagem, como objeto (preserva {} e []). */
    static public function inspect ($id, $type = 'container')
    {
        exec (self::DOCKER .' inspect --type '. $type .' '. escapeshellarg ($id) .' 2>&1', $output, $return);

        $json = json_decode (implode ("\n", $output));

        if ($return !== 0 || !is_array ($json) || !isset ($json [0]))
            throw new Exception ("Unable to inspect ". $type ." '". $id ."' (". trim (implode (' ', array_slice ($output, -1))) .")");

        return $json [0];
    }

    /** Montagem do contêiner com destino $destination (ex.: /data), ou NULL. */
    static public function mount ($container, $destination)
    {
        foreach ((array) ($container->Mounts ?? []) as $m)
            if (rtrim ((string) $m->Destination, '/') === rtrim ($destination, '/')) return $m;

        return NULL;
    }

    /** Origem de uma montagem para `-v` (caminho no host ou nome do volume). */
    static public function source ($mount)
    {
        return ($mount->Type ?? '') === 'volume' ? (string) $mount->Name : (string) $mount->Source;
    }

    /** Serviço do Swarm de que o contêiner é tarefa, ou NULL. */
    static public function service ($container)
    {
        $labels = $container->Config->Labels ?? NULL;

        $name = $labels->{'com.docker.swarm.service.name'} ?? '';

        return $name !== '' ? $name : NULL;
    }

    /**
     * Corpo do POST /containers/create a partir do inspect do contêiner atual,
     * para recriá-lo com a imagem $ref. Com $image (inspect da imagem ANTIGA),
     * tira o que veio dela (variáveis, rótulos, CMD, ENTRYPOINT, WORKDIR...),
     * para valerem os da imagem nova: ficam só as opções da criação (`-e`,
     * `--label`, comando sobrescrito...). Sem $image (volta atrás recriando com
     * a mesma imagem), a Config vai inteira.
     *
     * @return array [corpo, [rede => configuração] a conectar depois de criar]
     */
    static public function body ($container, $image, $ref)
    {
        $config = json_decode (json_encode ($container->Config));

        $config->Image = $ref;

        // hostname padrão é o ID abreviado do contêiner: o novo ganha o dele
        if (isset ($config->Hostname) && $config->Hostname === substr ((string) $container->Id, 0, 12)) unset ($config->Hostname);

        unset ($config->MacAddress);

        if ($image !== NULL)
        {
            $base = $image->Config ?? new stdClass ();

            $config->Env = array_values (array_diff ((array) ($config->Env ?? []), (array) ($base->Env ?? [])));

            $labels = new stdClass ();

            foreach ((array) ($config->Labels ?? []) as $key => $value)
                if (!isset ($base->Labels->$key) || $base->Labels->$key !== $value) $labels->$key = $value;

            $config->Labels = $labels;

            foreach ([ 'Cmd', 'Entrypoint', 'WorkingDir', 'User', 'StopSignal', 'Healthcheck', 'Shell', 'OnBuild', 'ArgsEscaped' ] as $key)
                if (property_exists ($config, $key) && json_encode ($config->$key) === json_encode ($base->$key ?? NULL)) unset ($config->$key);

            foreach ([ 'Volumes', 'ExposedPorts' ] as $key)
            {
                if (!isset ($config->$key) || !is_object ($config->$key)) continue;

                foreach (array_keys ((array) ($base->$key ?? [])) as $name) unset ($config->$key->$name);

                if (!sizeof ((array) $config->$key)) unset ($config->$key);
            }
        }

        $config->HostConfig = $container->HostConfig;

        // redes: a principal na criação, as demais depois (API antiga só aceita uma no create)
        $mode = (string) ($container->HostConfig->NetworkMode ?? 'default');

        $networks = (array) ($container->NetworkSettings->Networks ?? []);

        $extra = [];

        if (!in_array ($mode, [ 'host', 'none' ]) && strpos ($mode, 'container:') !== 0 && sizeof ($networks))
        {
            $primary = isset ($networks [$mode]) ? $mode : ($mode === 'default' && isset ($networks ['bridge']) ? 'bridge' : array_keys ($networks)[0]);

            $endpoints = new stdClass ();

            foreach ($networks as $name => $endpoint)
            {
                $aliases = array_values (array_filter ((array) ($endpoint->Aliases ?? []), function ($alias) use ($container) {
                    return strpos ((string) $container->Id, (string) $alias) !== 0;
                }));

                $settings = (object) [ 'IPAMConfig' => $endpoint->IPAMConfig ?? NULL, 'Links' => $endpoint->Links ?? NULL, 'Aliases' => sizeof ($aliases) ? $aliases : NULL ];

                if ($name === $primary) $endpoints->$name = $settings; else $extra [$name] = $settings;
            }

            $config->NetworkingConfig = (object) [ 'EndpointsConfig' => $endpoints ];
        }

        return [ $config, $extra ];
    }

    /** Resumo do que é preservado na troca (para o --dry-run e o log). */
    static public function summary ($container, $body)
    {
        $lines = [];

        $lines [] = 'Name: '. ltrim ((string) $container->Name, '/');
        $lines [] = 'Image: '. (string) $container->Config->Image .' -> '. (string) $body->Image;

        foreach ((array) ($container->Mounts ?? []) as $m)
            $lines [] = 'Mount: '. self::source ($m) .' -> '. $m->Destination . (empty ($m->RW) ? ' (read-only)' : '');

        $policy = $container->HostConfig->RestartPolicy ?? NULL;

        $lines [] = 'Restart policy: '. ($policy && $policy->Name !== '' ? $policy->Name . ($policy->Name === 'on-failure' && $policy->MaximumRetryCount ? ':'. $policy->MaximumRetryCount : '') : 'no');
        $lines [] = 'Network: '. (string) ($container->HostConfig->NetworkMode ?? 'default') . (sizeof ((array) ($container->NetworkSettings->Networks ?? [])) > 1 ? ' (+ '. implode (', ', array_slice (array_keys ((array) $container->NetworkSettings->Networks), 1)) .')' : '');

        // só os nomes: os valores podem ter segredos
        $env = array_map (function ($e) { return explode ('=', $e, 2)[0]; }, (array) ($body->Env ?? []));

        $lines [] = 'Variables set at creation: '. (sizeof ($env) ? implode (', ', $env) : 'none');
        $lines [] = 'Labels set at creation: '. (sizeof ((array) ($body->Labels ?? [])) ? implode (', ', array_keys ((array) $body->Labels)) : 'none');

        if (sizeof ((array) ($container->HostConfig->ExtraHosts ?? [])))
            $lines [] = 'Extra hosts: '. implode (', ', (array) $container->HostConfig->ExtraHosts);

        foreach ([ 'Cmd' => 'Command', 'Entrypoint' => 'Entrypoint' ] as $key => $label)
            if (isset ($body->$key)) $lines [] = $label .' (set at creation): '. implode (' ', (array) $body->$key);

        return $lines;
    }

    /** Chamada à API do Docker pelo socket (criar contêiner e ligar redes; o resto vai pela CLI). */
    static public function api ($method, $path, $body = NULL)
    {
        $socket = self::SOCKET;

        $host = (string) getenv ('DOCKER_HOST');

        if (strpos ($host, 'unix://') === 0) $socket = substr ($host, 7);

        $curl = curl_init ('http://localhost'. $path);

        curl_setopt_array ($curl, [
            CURLOPT_UNIX_SOCKET_PATH => $socket,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ]
        ]);

        if ($body !== NULL) curl_setopt ($curl, CURLOPT_POSTFIELDS, json_encode ($body, JSON_UNESCAPED_SLASHES));

        $out = curl_exec ($curl);

        $status = (int) curl_getinfo ($curl, CURLINFO_HTTP_CODE);

        $error = curl_error ($curl);

        curl_close ($curl);

        if ($out === FALSE)
            throw new Exception ('Docker API '. $method .' '. $path .': '. $error);

        $json = json_decode ((string) $out);

        if ($status >= 300)
            throw new Exception ('Docker API '. $method .' '. $path .': HTTP '. $status .' ('. (is_object ($json) && isset ($json->message) ? $json->message : trim ((string) $out)) .')');

        return $json;
    }

    /** Cria o contêiner (sem iniciar) e liga as redes extras. Devolve o ID. */
    static public function create ($name, $body, array $extra)
    {
        echo 'COMMAND > POST /containers/create?name='. $name .' (image '. $body->Image .") \n";

        $created = self::api ('POST', '/containers/create?name='. rawurlencode ($name), $body);

        if (!is_object ($created) || empty ($created->Id))
            throw new Exception ("Docker did not return the ID of the new container '". $name ."'");

        foreach ($extra as $network => $settings)
        {
            echo 'COMMAND > POST /networks/'. $network ."/connect \n";

            self::api ('POST', '/networks/'. rawurlencode ($network) .'/connect', (object) [ 'Container' => $created->Id, 'EndpointConfig' => $settings ]);
        }

        return $created->Id;
    }

    /** Roda um comando docker (com COMMAND >); com $must, lança se falhar. */
    static public function docker ($args, $must = TRUE, $quiet = FALSE)
    {
        $cmd = self::DOCKER .' '. $args;

        if (!$quiet) echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        if ($must && $return !== 0)
            throw new Exception ("'docker ". strtok ($args, ' ') ."' failed: ". mb_strimwidth (trim (implode (' ', array_slice ($output, -3))), 0, 300, '...'));

        return [ 'code' => $return, 'out' => $output ];
    }

    /**
     * Espera o contêiner ficar de pé: rodando por STABLE segundos sem reiniciar.
     * Lança com o motivo (código de saída, erro) se ele parar ou reiniciar.
     */
    static public function wait ($id)
    {
        $start = time ();

        $up = NULL;

        $restarts = NULL;

        while (time () - $start < self::START_TIMEOUT)
        {
            try
            {
                $c = self::inspect ($id);
            }
            catch (\Throwable $e)
            {
                // criado com --rm: some assim que para
                throw new Exception ('the new container stopped and was removed (created with --rm)');
            }

            $state = $c->State;

            if ($restarts === NULL) $restarts = (int) ($c->RestartCount ?? 0);

            if ((int) ($c->RestartCount ?? 0) > $restarts || !empty ($state->Restarting))
                throw new Exception ('the new container is restarting (exit code '. (int) $state->ExitCode . ($state->Error !== '' ? ', '. $state->Error : '') .')');

            if (empty ($state->Running) && $up !== NULL)
                throw new Exception ('the new container stopped (exit code '. (int) $state->ExitCode . ($state->Error !== '' ? ', '. $state->Error : '') .')');

            if (empty ($state->Running) && in_array ((string) $state->Status, [ 'exited', 'dead' ]))
                throw new Exception ('the new container did not start (exit code '. (int) $state->ExitCode . ($state->Error !== '' ? ', '. $state->Error : '') .')');

            if (!empty ($state->Running) && $up === NULL) $up = time ();

            if ($up !== NULL && time () - $up >= self::STABLE) return TRUE;

            sleep (1);
        }

        throw new Exception ('the new container did not come up in '. self::START_TIMEOUT .' seconds');
    }

    /**
     * Confere o contêiner novo: restaura a chave SSH (bin/entrypoint, idempotente)
     * e roda um `io check` silencioso. Só falha pelo que é da atualização: o
     * comando não roda até o fim (PHP quebrado, variável faltando, imagem errada)
     * ou a chave SSH que havia sumiu. GitLab ou bucket fora do ar não contam.
     */
    static public function verify ($id, $plan)
    {
        $setup = self::docker ('exec '. escapeshellarg ($id) .' /app/bin/entrypoint', FALSE);

        if ($setup ['code'] !== 0)
            throw new Exception ('the setup of the new container failed (exit code '. $setup ['code'] .': '. trim (implode (' ', array_slice ($setup ['out'], -2))) .')');

        $version = self::docker ('exec '. escapeshellarg ($id) .' printenv IO_RELEASER_VERSION', FALSE, TRUE);

        $running = trim ((string) ($version ['out'][0] ?? ''));

        if ($running !== (string) $plan ['to'])
            echo "WARNING > The new container reports version '". $running ."' (expected ". $plan ['to'] ."). \n";

        $check = self::docker ('exec '. escapeshellarg ($id) .' sh -c '. escapeshellarg ('export $(cat /data/.env) && timeout 120 /usr/local/bin/php /app/run.php check'), FALSE);

        $text = implode ("\n", $check ['out']);

        foreach ($check ['out'] as $line)
            if (preg_match ('/^(SUCCESS|WARNING|ERROR|CRITICAL) > /', $line)) echo '       [new] '. $line ."\n";

        if (strpos ($text, 'FINISH > All done') === FALSE)
        {
            $reason = preg_match ('/^CRITICAL > (.+)$/m', $text, $m) ? trim ($m [1]) : trim (implode (' ', array_slice ($check ['out'], -2)));

            throw new Exception ("'io check' did not run to the end in the new container (exit code ". $check ['code'] .': '. mb_strimwidth ($reason, 0, 300, '...') .')');
        }

        if (!empty ($plan ['ssh']) && strpos ($text, 'no SSH key in the container') !== FALSE)
            throw new Exception ('the SSH key for GitLab is missing in the new container');

        return TRUE;
    }

    /** Política de restart no formato do `docker update --restart`. */
    static public function policy ($container)
    {
        $policy = $container->HostConfig->RestartPolicy ?? NULL;

        $name = $policy && (string) $policy->Name !== '' ? (string) $policy->Name : 'no';

        return $name === 'on-failure' && !empty ($policy->MaximumRetryCount) ? 'on-failure:'. (int) $policy->MaximumRetryCount : $name;
    }

    /**
     * Troca de um contêiner comum (docker run), no auxiliar. Ordem: inspect
     * guardado em previous.json, restart desligado, parar, renomear para
     * <nome>-previous, criar e iniciar o novo, esperar, conferir; o antigo só é
     * removido depois. Qualquer falha a partir da parada volta atrás (restore ()).
     *
     * @return array ['result' => ok|rolled back, 'message' => ...]; lança se nem a volta deu certo
     */
    static public function swap (array $plan, $data)
    {
        try
        {
            $old = self::inspect ($plan ['container']);

            $image = self::inspect ($old->Image, 'image');
        }
        catch (\Throwable $e)
        {
            throw new Exception ($e->getMessage () .'; nothing was changed');
        }

        $name = ltrim ((string) $old->Name, '/');

        $backup = $name .'-previous';

        if (!self::write ($data, 'previous.json', $old))
            throw new Exception ("Unable to save the configuration of the current container in /data/". self::DIR ."/previous.json; nothing was changed");

        echo "INFO > Configuration of the current container saved in /data/". self::DIR ."/previous.json ('docker inspect'). \n";

        list ($body, $extra) = self::body ($old, $image, $plan ['image']);

        foreach (self::summary ($old, $body) as $line) echo 'INFO > '. $line ." \n";

        if (self::docker ('container inspect '. escapeshellarg ($backup), FALSE, TRUE)['code'] === 0)
            throw new Exception ("there is already a container named '". $backup ."' (left by an earlier upgrade?): remove it with 'docker rm ". $backup ."' and run 'io upgrade' again; nothing was changed");

        $restart = self::policy ($old);

        $autoRemove = !empty ($old->HostConfig->AutoRemove);

        $state = [ 'policy' => FALSE, 'stopped' => FALSE, 'renamed' => FALSE, 'new' => NULL ];

        try
        {
            // senão, com 'always', um restart do Docker religaria o antigo junto com o novo
            if ($restart !== 'no')
            {
                self::docker ('update --restart no '. escapeshellarg ($old->Id));

                $state ['policy'] = TRUE;
            }

            echo "INFO > Stopping the current container '". $name ."' (". $plan ['from'] ."): the 'docker exec' sessions in it end now. \n";

            self::docker ('stop -t '. self::STOP_TIMEOUT .' '. escapeshellarg ($old->Id));

            $state ['stopped'] = TRUE;

            if ($autoRemove)
            {
                // criado com --rm: some ao parar; a volta atrás o recria pelo inspect, com a imagem anterior
                for ($i = 0; $i < 30 && self::docker ('container inspect '. escapeshellarg ($old->Id), FALSE, TRUE)['code'] === 0; $i++) sleep (1);
            }
            else
            {
                self::docker ('rename '. escapeshellarg ($old->Id) .' '. escapeshellarg ($backup));

                $state ['renamed'] = TRUE;
            }

            echo "INFO > Creating the new container '". $name ."' (". $plan ['to'] .") with the same configuration... \n";

            $state ['new'] = self::create ($name, $body, $extra);

            self::docker ('start '. escapeshellarg ($state ['new']));

            echo "INFO > Waiting for the new container to stay up for ". self::STABLE ." seconds... \n";

            self::wait ($state ['new']);

            echo "INFO > Checking the new container (SSH key and a silent 'io check')... \n";

            self::verify ($state ['new'], $plan);
        }
        catch (\Throwable $e)
        {
            echo "ERROR > Upgrade failed: ". $e->getMessage () ." \n";

            if ($state ['new'] !== NULL)
            {
                $logs = self::docker ('logs --tail 30 '. escapeshellarg ($state ['new']), FALSE, TRUE);

                if (sizeof ($logs ['out']))
                {
                    echo "INFO > Last lines of the log of the new container: \n";

                    foreach ($logs ['out'] as $line) echo '       '. $line ."\n";
                }
            }

            self::restore ($old, $name, $backup, $restart, $autoRemove, $state);

            return [ 'result' => 'rolled back', 'message' => $e->getMessage () ];
        }

        if (!$autoRemove) self::docker ('rm '. escapeshellarg ($backup), FALSE);

        return [ 'result' => 'ok', 'message' => "container '". $name ."' recreated with ". $plan ['image'] ];
    }

    /** Volta ao contêiner anterior: remove o novo, devolve nome e política ao antigo e o religa. Lança se não conseguir. */
    static private function restore ($old, $name, $backup, $restart, $autoRemove, array $state)
    {
        echo "INFO > Rolling back to the previous container (". (string) $old->Config->Image .")... \n";

        try
        {
            if ($state ['new'] !== NULL) self::docker ('rm -f '. escapeshellarg ($state ['new']), FALSE);

            if ($autoRemove && $state ['stopped'])
            {
                // a mesma Config, inteira, com a imagem anterior pelo ID (já local); pela tag, se ela ainda é a mesma imagem
                $previous = $old->Image;

                try
                {
                    if (self::inspect ((string) $old->Config->Image, 'image')->Id === $old->Image) $previous = (string) $old->Config->Image;
                }
                catch (\Throwable $e) {}

                list ($body, $extra) = self::body ($old, NULL, $previous);

                $id = self::create ($name, $body, $extra);
            }
            else
            {
                $id = $old->Id;

                if ($state ['renamed']) self::docker ('rename '. escapeshellarg ($old->Id) .' '. escapeshellarg ($name));

                if ($state ['policy']) self::docker ('update --restart '. escapeshellarg ($restart) .' '. escapeshellarg ($old->Id));
            }

            self::docker ('start '. escapeshellarg ($id));

            self::wait ($id);
        }
        catch (\Throwable $e)
        {
            throw new Exception ('the rollback failed too ('. $e->getMessage () .'). The previous container is '. ($autoRemove ? 'gone (created with --rm); recreate it from /data/'. self::DIR .'/previous.json' : "'". ($state ['renamed'] ? $backup : $name) ."': docker rm -f ". $name ." ; docker rename ". $backup ." ". $name ." ; docker update --restart ". $restart ." ". $name ." ; docker start ". $name));
        }

        echo "SUCCESS > The previous container '". $name ."' is running again. \n";
    }

    /**
     * Troca no Docker Swarm, no auxiliar: o próprio Swarm substitui a tarefa e
     * volta atrás sozinho se a nova não subir (--update-failure-action
     * rollback); depois, o `io check` na tarefa nova deste nó, e
     * `--rollback` se falhar.
     */
    static public function swarm (array $plan)
    {
        $service = escapeshellarg ($plan ['service']);

        echo "INFO > Updating the Swarm service '". $plan ['service'] ."' to ". $plan ['image'] ." (the Swarm stops the current task: the 'docker exec' sessions in it end)... \n";

        $update = self::docker ('service update --image '. escapeshellarg ($plan ['image']) .' --update-failure-action rollback --update-monitor 30s --detach=false --quiet '. $service, FALSE);

        $state = trim ((string) (self::docker ('service inspect --format '. escapeshellarg ('{{if .UpdateStatus}}{{.UpdateStatus.State}}{{end}}') .' '. $service, FALSE, TRUE)['out'][0] ?? ''));

        if ($update ['code'] !== 0 || strpos ($state, 'rollback') === 0 || $state === 'paused')
        {
            // o motivo está na tarefa que falhou (ex.: "task: non-zero exit (3)"), não na saída do update
            $reason = '';

            foreach (self::docker ('service ps --no-trunc --format '. escapeshellarg ('{{.Image}}|{{.Error}}') .' '. $service, FALSE, TRUE)['out'] as $line)
            {
                list ($task, $error) = array_pad (explode ('|', $line, 2), 2, '');

                if (trim ($error) !== '' && strpos ($task, (string) $plan ['image']) === 0) { $reason = trim ($error); break; }
            }

            if ($reason === '') $reason = trim (implode (' ', array_slice ($update ['out'], -2)));

            return [ 'result' => strpos ($state, 'rollback') === 0 ? 'rolled back' : 'failed', 'message' => 'the new task failed'. ($reason !== '' ? ' ('. $reason .')' : '') .'; Swarm update state: '. ($state !== '' ? $state : 'unknown') ];
        }

        $task = NULL;

        for ($i = 0; $i < 60 && $task === NULL; $i++)
        {
            foreach (self::docker ('ps -q --filter '. escapeshellarg ('label=com.docker.swarm.service.name='. $plan ['service']) .' --filter status=running', FALSE, TRUE)['out'] as $id)
                if (trim ((string) (self::docker ('exec '. escapeshellarg ($id) .' printenv IO_RELEASER_VERSION', FALSE, TRUE)['out'][0] ?? '')) === (string) $plan ['to']) $task = $id;

            if ($task === NULL) sleep (2);
        }

        try
        {
            if ($task === NULL) throw new Exception ('no task of the service with version '. $plan ['to'] .' running on this node');

            echo "INFO > Checking the new task (SSH key and a silent 'io check')... \n";

            self::verify ($task, $plan);
        }
        catch (\Throwable $e)
        {
            echo "ERROR > Upgrade failed: ". $e->getMessage () ." \n";

            self::docker ('service update --rollback --detach=false --quiet '. $service, FALSE);

            return [ 'result' => 'rolled back', 'message' => $e->getMessage () ];
        }

        return [ 'result' => 'ok', 'message' => "service '". $plan ['service'] ."' updated to ". $plan ['image'] ];
    }
}
