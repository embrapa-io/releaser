<?php

class DockerCompose extends Orchestrator
{
    const DOCKER = '/usr/bin/docker';
    const DOCKER_COMPOSE = '/usr/bin/docker-compose';

    // Único bind mount que a validação aceita num serviço, e só em build autorizada (ver checkDockerComposeFile).
    const DOCKER_SOCKET = '/var/run/docker.sock';

    // Caminhos do socket no host (em quase todo Linux, /var/run é um link para /run).
    const DOCKER_SOCKET_PATHS = [ '/var/run/docker.sock', '/run/docker.sock' ];

    // rótulos que o Compose grava nos containers (ver Orchestrator::containers)
    const STACK_LABEL = 'com.docker.compose.project';
    const SERVICE_LABEL = 'com.docker.compose.service';

    static public function validate ($path, $namespace, $allow = [])
    {
        return self::checkDockerComposeFile ($path, $namespace, $allow);
    }

    static public function deploy ($path, $namespace, $allow = [])
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        $valid = self::checkDockerComposeFile ($path, $namespace, $allow);

        if (!$valid)
            throw new Exception ('Invalid docker-compose.yaml file! Please check the configuration (volumes, ports, environment variables, etc.)');

        chdir ($path);

        echo "INFO > Trying to run the backup service before deploying...\n";

        // Resultado do backup para o e-mail do deploy (ver Orchestrator::deploy): TRUE feito, FALSE falhou, NULL sem
        // serviço 'backup' (comum nos fronts, não é falha).
        $backup = NULL;

        try
        {
            self::requireCliService ('backup');

            echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup'."\n";

            exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup 2>&1', $output, $return);

            unset ($return);
            unset ($output);

            echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' run --rm --no-deps backup'."\n";

            exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' run --rm --no-deps backup 2>&1', $output, $return);

            $backup = $return === 0;

            if (!$backup)
            {
                echo implode ("\n", $output) ."\n";

                echo "WARNING > Backup service failed! \n";
            }
            else
                echo "SUCCESS > Backup service ran successfully! \n";
        }
        catch (SkipException $e)
        {
            echo "INFO > ". $e->getMessage () .". Nothing to back up. \n";
        }
        catch (Exception $e)
        {
            $backup = FALSE;

            echo "WARNING > Backup service failed: ". $e->getMessage () ."! \n";
        }

        unset ($return);
        unset ($output);

        // A rede é criada no primeiro deploy; nos seguintes, o 'network create' só imprimia o erro "already exists".
        exec (self::DOCKER .' network inspect '. escapeshellarg ($namespace) .' > /dev/null 2>&1', $trash, $return);

        if ($return === 0)
            echo "INFO > Stack network '". $namespace ."' already exists. \n";
        else
        {
            unset ($return);

            echo "INFO > Creating a stack network named '". $namespace ."' with Docker... \n";

            echo 'COMMAND > '. self::DOCKER .' network create '. $namespace ."\n";

            exec (self::DOCKER .' network create '. $namespace .' 2>&1', $output, $return);

            if ($return !== 0)
                echo implode ("\n", $output) ."\n";
        }

        unset ($return);
        unset ($output);

        echo "INFO > Building application with Docker Compose... \n";

        echo 'COMMAND > set -e && '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up --force-recreate --build --no-start && exit $?'."\n";

        passthru ('set -e && '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up --force-recreate --build --no-start && exit $? 2>&1', $return);

        if ($return !== 0)
            throw new Exception ('Error when building containers with Docker Compose');

        unset ($return);
        unset ($output);

        echo "INFO > Getting valid services (will ignore: ". implode (", ", self::CLI_SERVICES) .")... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config --services 2>/dev/null', $services, $return);

        if ($return !== 0)
            throw new Exception ('Error when getting services from docker-compose.yaml');

        foreach ($services as $index => $service)
            if (in_array (trim ($service), self::CLI_SERVICES))
                unset ($services [$index]);

        if (!sizeof ($services))
            throw new Exception ('No valid services found in docker-compose.yaml');

        unset ($return);
        unset ($output);

        echo "INFO > Starting application with Docker Compose... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' start '. implode (' ', $services) ."\n";

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' start '. implode (' ', $services) .' 2>&1', $output, $return);

        echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when starting containers with Docker Compose');

        return $backup;
    }

    static public function checkDockerComposeFile ($folder, $namespace, $allow = [])
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        echo "INFO > Validating Docker Compose file: \n";

        chdir ($folder);

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config'."\n";

        if (!file_exists ('.embrapa') || !is_dir ('.embrapa'))
        {
            echo "ERROR > Apparently the '.embrapa' directory does not exist in this branch! Tip: merge from 'main' into the stage branch (alpha, beta or release). \n";

            return FALSE;
        }

        $out = tempnam ('.embrapa', '_');
        $log = tempnam ('.embrapa', '_');

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config > '. $out .' 2> '. $log, $trash, $return);

        $output = file_exists ($log) && is_readable ($log) ? @file ($log) : [];

        $warning = FALSE;

        if (sizeof ($output))
        {
            $warning = TRUE;

            echo "--- \n";
            echo implode ('', $output);
            echo "--- \n";
        }

        if ($return !== 0)
        {
            echo "ERROR > File docker-compose.yaml is INVALID! Please check the configuration (volumes, ports, environment variables, etc.). \n";

            return FALSE;
        }

        unset ($return);
        unset ($output);

        echo "INFO > Trying to validate declared VOLUMEs and PORTs... \n";

        if (!file_exists ($out) || !is_readable ($out))
        {
            echo "ERROR > Unable to load the interpolated docker-compose.yaml! \n";

            return FALSE;
        }

        $config = yaml_parse_file ($out);

        if ($config === FALSE || !is_array ($config))
        {
            echo "ERROR > Unable to properly load the interpolated docker-compose.yaml! \n";

            return FALSE;
        }

        $volumes = [];

        if (array_key_exists ('volumes', $config) && is_array ($config ['volumes']))
        {
            foreach ($config ['volumes'] as $name => $volume)
            {
                if (!array_key_exists ('external', $volume) || !(bool) $volume ['external'])
                {
                    echo "ERROR > Volume '". $name ."' is NOT 'external'! See https://docs.docker.com/compose/compose-file/#external-1 for more info. \n";

                    return FALSE;
                }

                $aux = explode ('_', $volume ['name']);

                array_pop ($aux);

                if (implode ('_', $aux) != $namespace)
                {
                    echo "ERROR > Volume '". $name ."' is pointing to '". $volume ['name'] ."'! All mounted volumes must belong to the application context. \n";

                    return FALSE;
                }

                $volumes [] = $name;
            }

            echo "INFO > All external VOLUMEs declared are valid! \n";
        }

        if (!array_key_exists ('networks', $config) || !is_array ($config ['networks']) || sizeof ($config ['networks']) !== 1 ||
            !array_key_exists ('external', $config ['networks'][array_key_first ($config ['networks'])]) || !(bool) $config ['networks'][array_key_first ($config ['networks'])]['external'] ||
            !array_key_exists ('name', $config ['networks'][array_key_first ($config ['networks'])]) || trim ($config ['networks'][array_key_first ($config ['networks'])]['name']) !== $namespace)
            echo "WARNING > It is recommended to have ONE, and only one, external network for the entire stack in 'docker-compose.yaml' (named '". $namespace ."'). See https://docs.docker.com/compose/networking/ for more info. \n";

        if (!array_key_exists ('services', $config) || !is_array ($config ['services']))
        {
            echo "ERROR > Unable to load the services from the config file! \n";

            return FALSE;
        }

        $socket = is_array ($allow) && ($allow ['socket'] ?? FALSE) === TRUE;

        foreach ($config ['services'] as $name => $service)
        {
            // Nome fixo de contêiner colide com outro de mesmo nome no servidor (o servidor da equipe costuma hospedar
            // várias apps): o segundo deploy falha com "container name already in use". Mesmo aviso do deployer da
            // plataforma; vira erro numa versão futura.
            if (array_key_exists ('container_name', $service))
                echo "WARNING > Service '". $name ."' sets 'container_name' ('". $service ['container_name'] ."'). Remove it: a fixed name collides with any other container with the same name on this server (another app, stage or project). It will become an ERROR in a future version. \n";

            if (isset ($service ['ports']))
                foreach ($service ['ports'] as $trash => $port)
                {
                    if (!isset ($port ['published']) || !(int) $port ['published'])
                    {
                        echo "ERROR > Service '". $name ."' is trying to expose a random port. All ports in services must be explicit! If you are not going to expose this service, remove it by setting a suitable 'profile'. \n";

                        return FALSE;
                    }
                }

            if (isset ($service ['volumes']))
                foreach ($service ['volumes'] as $trash => $volume)
                {
                    // O socket do Docker é o único bind mount aceito, só no caminho padrão dos dois lados e só em build
                    // autorizada por quem administra o servidor ('allow.socket' no arquivo da build): é o que um painel
                    // de contêineres (o boilerplate 'arcane') precisa para administrar ESTE servidor. A autorização fica
                    // no servidor, fora do repositório: quem publica uma tag da app não a concede a si mesmo. Qualquer
                    // outro bind mount continua recusado. Nos clusters compartilhados da plataforma, o deployer recusa
                    // também este (política de segurança do Compose).
                    if (($volume ['type'] ?? '') === 'bind' && ($volume ['source'] ?? '') === self::DOCKER_SOCKET && ($volume ['target'] ?? '') === self::DOCKER_SOCKET)
                    {
                        if (!$socket)
                        {
                            echo "ERROR > Service '". $name ."' mounts the Docker socket (". self::DOCKER_SOCKET ."), and this build is not authorized to: whoever controls this service controls this whole server, as root. ". self::howToAllowSocket ($namespace) ." \n";

                            return FALSE;
                        }

                        echo "WARNING > Service '". $name ."' mounts the Docker socket (". self::DOCKER_SOCKET ."), authorized for this build ('allow.socket' in the build file): whoever gets into this service controls this whole server. Keep it off the internet and give access only to who administers the server. \n";

                        $warning = TRUE;

                        continue;
                    }

                    if (!in_array ($volume ['source'], $volumes))
                    {
                        echo "ERROR > Volume named '". $volume ['source'] ."' used by service '". $name ."' is not declared as external. \n";

                        return FALSE;
                    }
                }
        }

        echo "INFO > All published PORTs are valid! \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config --services 2>/dev/null', $services1, $return);

        echo "INFO > Checking for CLI services starting with the application... ";

        foreach (self::CLI_SERVICES as $index => $service)
            if (in_array ($service, $services1))
            {
                echo "failed! \n";

                echo "ERROR > Service '". $service ."' cannot start during deployment! Please check the 'profiles' attribute in the configuration. \n";

                echo "\n". implode ("\n", $services1) ."\n\n";

                return FALSE;
            }

        echo "ok! \n";

        echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' config --services'."\n";

        exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' config --services 2>/dev/null', $services2, $return);

        $cli = self::CLI_SERVICES;

        foreach ($cli as $index => $service)
            if (in_array ($service, $services2))
                unset ($cli [$index]);

        if (sizeof ($cli))
        {
            echo "WARNING > Some recommended CLI services were NOT FOUND in docker-compose.yaml: ". implode (", ", $cli) ."! Please check the configuration. \n";

            $warning = TRUE;
        }

        // Os serviços CLI (perfil 'cli') ficam fora do compose interpolado acima, e os volumes deles nunca foram
        // conferidos: um 'backup' que monta o socket passava sem ninguém ver. Em build autorizada, o mesmo aviso dos
        // demais serviços; sem autorização, por ora só avisa (recusar agora derrubaria o deploy de builds que já fazem
        // isso), e vira erro numa versão futura.
        foreach (self::socketMounts (self::interpolated ('.env.sh'), self::CLI_SERVICES) as $name)
        {
            if ($socket)
                echo "WARNING > CLI service '". $name ."' mounts the Docker socket, authorized for this build ('allow.socket' in the build file): whoever controls this service controls this whole server. \n";
            else
                echo "WARNING > CLI service '". $name ."' mounts the Docker socket, and this build is not authorized to: whoever controls this service controls this whole server, as root. It will become an ERROR in a future version. ". self::howToAllowSocket ($namespace) ." \n";

            $warning = TRUE;
        }

        // A validação dos volumes confere só o nome. Volume que não existe no servidor derruba o 'up' (dados) ou faz o
        // backup falhar a cada deploy sem ninguém notar (o '_backup' é usado só pelos serviços CLI): o Forge ficou assim
        // da 0.26.10-4 à -100 (nov/2026).
        foreach (self::missingVolumes () as $volume)
        {
            echo "WARNING > External volume '". $volume ."' does NOT exist on this server! Create it: ". self::createVolumeCommand ($volume, $namespace) ." \n";

            $warning = TRUE;
        }

        if ($warning)
            echo "WARNING > File docker-compose.yaml is VALID, but has some warnings to fix! \n";
        else
            echo "SUCCESS > File docker-compose.yaml is VALID! \n";

        return TRUE;
    }

    static public function stop ($path, $namespace, $down = FALSE)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        chdir ($path);

        // `--down` remove os containers (e a rede padrão do Compose, se houver), sem `-v`: os volumes nomeados e os
        // externos da build ficam intactos. Útil para recriar a stack do zero (mudança de porta, de rede etc.).
        $action = $down ? 'down --remove-orphans' : 'stop';

        echo "INFO > ". ($down ? "Removing containers (volumes are kept)" : "Stopping application") ." with Docker Compose... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' '. $action ."\n";

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' '. $action .' 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when trying to stop containers with Docker Compose');
    }

    static public function restart ($path, $namespace, $upgrade = FALSE, $backup = TRUE, callable $onBackupFailure = NULL)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        chdir ($path);

        if (!$upgrade)
        {
            // O `docker compose restart` só age sobre containers que existem: depois de um `io stop --down` (ou de um
            // `docker rm` manual), ele sai com 0 sem subir nada e o Releaser relatava SUCCESS com a app fora do ar.
            // Se algum serviço da aplicação está sem container, ele é criado antes (`up -d --no-recreate`, que não
            // toca nos containers existentes nem renova imagens) e o restart segue como sempre.
            $missing = self::servicesWithoutContainer ();

            if (sizeof ($missing))
            {
                echo "WARNING > Service(s) without container: ". implode (', ', $missing) ." (removed by 'io stop --down'?). Creating them... \n";

                echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up -d --no-recreate --quiet-pull'."\n";

                exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up -d --no-recreate --quiet-pull 2>&1', $output, $return);

                if (sizeof ($output)) echo implode ("\n", $output) ."\n";

                if ($return !== 0)
                    throw new Exception ('Error when trying to create the missing containers with Docker Compose');

                unset ($output, $return);
            }

            echo "INFO > Restarting application with Docker Compose... \n";

            echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' restart'."\n";

            exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' restart 2>&1', $output, $return);

            if (sizeof ($output)) echo implode ("\n", $output) ."\n";

            if ($return !== 0)
                throw new Exception ('Error when trying to restart containers with Docker Compose');

            return;
        }

        // `--upgrade`: o mesmo do deployer da plataforma (pull, build --pull --no-cache e up). Com `--force-recreate`, como no
        // deploy: sem ele, serviço sem imagem nova nem era reiniciado, e o `build --pull` move a tag mesmo sem mudar
        // o conteúdo (atestados do BuildKit), deixando o container preso a uma imagem que o Trivy não encontra mais
        // (visto com o containerd image store). Os CLI_SERVICES ficam no perfil `cli` e não sobem.
        self::upgradeImages ();

        // Backup antes de recriar (desde `1.26.11-8`), depois do pull e do build: uma falha de build não custa um backup,
        // e o backup fica o mais perto possível da troca. O `restart --upgrade` também é o comando de socorro: sem nenhum
        // contêiner no ar (build parada ou caída) não há de onde tirar o backup, e a build volta assim mesmo.
        if ($backup && !self::anyRunning ($namespace))
            echo "WARNING > No container of the build is running (stopped with 'io stop'?): there is nothing to take a backup from. Going on without a backup. \n";
        else
            self::backupBefore ($path, $namespace, 'upgrade', $backup, $onBackupFailure);

        chdir ($path);

        echo "INFO > Recreating the containers with the renewed images... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up -d --force-recreate'."\n";

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up -d --force-recreate 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when trying to start containers with Docker Compose');
    }

    /**
     * `io update`: baixa as imagens de registro e recria só os serviços cuja imagem mudou (tags *floating*, como
     * `latest`, `2` ou `v3`). Diferente do `restart --upgrade`: não reconstrói os serviços `build:` (o build move a
     * tag mesmo sem mudar o conteúdo, e a build seria recriada toda noite) nem usa `--force-recreate` (sem imagem
     * nova, nenhum contêiner é tocado). Antes de recriar, roda o backup da build (`Orchestrator::backupBefore`): sem
     * serviço `backup`, segue; com backup que falha, nada é recriado, a menos que `$onBackupFailure` mande seguir.
     *
     * @return array serviços atualizados (ou que seriam, no dry run): [serviço => [reference, running, available]]
     */
    static public function update ($path, $namespace, $dryRun = FALSE, $backup = TRUE, callable $onBackupFailure = NULL)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        chdir ($path);

        self::pullImages ();

        $outdated = self::outdated ($namespace);

        if (!sizeof ($outdated)) return [];

        foreach ($outdated as $service => $o)
            echo "INFO > Service '". $service ."': image '". $o ['reference'] ."' has new content (". self::shortId ($o ['running']) ." -> ". self::shortId ($o ['available']) ."). \n";

        if ($dryRun) return $outdated;

        self::backupBefore ($path, $namespace, 'update', $backup, $onBackupFailure);

        chdir ($path);

        $services = implode (' ', array_map ('escapeshellarg', array_keys ($outdated)));

        echo "INFO > Recreating the service(s) with the new image(s)... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up -d --no-build --no-deps '. $services ."\n";

        $output = [];

        exec (''. self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' up -d --no-build --no-deps '. $services .' 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception ('Error when recreating the containers with Docker Compose');

        $left = array_intersect_key (self::outdated ($namespace), $outdated);

        if (sizeof ($left))
            throw new Exception ('Service(s) still running the previous image after the update: '. implode (', ', array_keys ($left)));

        return $outdated;
    }

    /**
     * Serviços da aplicação (os do `.env.io`, ou seja, sem os CLI_SERVICES do perfil `cli`) que não têm container,
     * nem parado. Deve ser chamado com o diretório da build como atual (depois do `chdir`).
     */
    static private function servicesWithoutContainer ()
    {
        exec (self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' config --services 2>/dev/null', $services, $return);

        if ($return !== 0)
            throw new Exception ("Unable to get the services in 'docker-compose.yaml'");

        unset ($return);

        exec (self::env ('.env.io') .' '. self::DOCKER_COMPOSE .' ps --all --services 2>/dev/null', $existing, $return);

        if ($return !== 0)
            throw new Exception ('Unable to list the containers of the build with Docker Compose');

        $services = array_diff (array_filter (array_map ('trim', $services)), self::CLI_SERVICES);

        return array_values (array_diff ($services, array_map ('trim', $existing)));
    }

    /**
     * Lança SkipException se o serviço CLI não existir no docker-compose.yaml
     * interpolado (apps de frontend, por exemplo, não têm 'backup' nem 'sanitize').
     */
    // Orientação comum às mensagens do socket do Docker em build não autorizada.
    static private function howToAllowSocket ($namespace)
    {
        return "If that is intended (a container management panel, for instance) and you administer this server, set \"allow\": { \"socket\": true } in the build file (builds/". $namespace .".json in the Releaser data directory) and deploy again.";
    }

    /*
     * Compose interpolado com o arquivo de ambiente dado ('.env.io' = serviços da aplicação, '.env.sh' = com os serviços
     * CLI). Roda na pasta do clone; devolve FALSE se o compose não puder ser interpretado.
     */
    static private function interpolated ($env)
    {
        $out = tempnam ('.embrapa', '_');

        exec (self::env ($env) .' '. self::DOCKER_COMPOSE .' config > '. $out .' 2> /dev/null', $trash, $return);

        // O helper/error.php transforma o warning do yaml em exceção.
        try { $config = $return === 0 ? yaml_parse_file ($out) : FALSE; }
        catch (Exception $e) { $config = FALSE; }

        unlink ($out);

        return is_array ($config) ? $config : FALSE;
    }

    /*
     * Serviços do compose interpolado que montam o socket do Docker do host, seja qual for o caminho dentro do
     * contêiner. Com $only, só entre os serviços dados. Pura: recebe o compose já interpretado.
     */
    static public function socketMounts ($config, $only = NULL)
    {
        $found = [];

        if (!is_array ($config) || !isset ($config ['services']) || !is_array ($config ['services'])) return $found;

        foreach ($config ['services'] as $name => $service)
        {
            if (is_array ($only) && !in_array ($name, $only, TRUE)) continue;

            if (!is_array ($service) || !isset ($service ['volumes']) || !is_array ($service ['volumes'])) continue;

            foreach ($service ['volumes'] as $volume)
                if (is_array ($volume) && ($volume ['type'] ?? '') === 'bind' && in_array (rtrim ((string) ($volume ['source'] ?? ''), '/'), self::DOCKER_SOCKET_PATHS, TRUE))
                {
                    $found [] = (string) $name;

                    break;
                }
        }

        return $found;
    }

    /*
     * O texto de um docker-compose.yaml cita o socket do Docker? Só para o 'io install' decidir se pergunta pela
     * autorização (o arquivo vem do GitLab, sem interpolar): quem decide de fato é a validação, no deploy.
     */
    static public function mentionsSocket ($yaml)
    {
        return (bool) preg_match ('#(^|[^A-Za-z0-9_.-])/(var/)?run/docker\.sock#m', (string) $yaml);
    }

    /*
     * Volumes externos do compose (os da aplicação, pelo .env.io, e os dos serviços CLI, pelo .env.sh: o
     * 'docker-compose config' omite volume que nenhum serviço ativo usa) que não existem no servidor. Roda na pasta do
     * clone; falha ao interpretar o compose não acusa nada (a validação já tratou).
     */
    static private function missingVolumes ()
    {
        $names = [];

        foreach ([ '.env.io', '.env.sh' ] as $env)
        {
            $out = tempnam ('.embrapa', '_');

            exec (self::env ($env) .' '. self::DOCKER_COMPOSE .' config > '. $out .' 2> /dev/null', $trash, $return);

            // O helper/error.php transforma o warning do yaml em exceção.
            try { $config = $return === 0 ? yaml_parse_file ($out) : FALSE; }
            catch (Exception $e) { $config = FALSE; }

            unlink ($out);

            unset ($return);

            if (!is_array ($config) || !isset ($config ['volumes']) || !is_array ($config ['volumes'])) continue;

            foreach ($config ['volumes'] as $volume)
                if (is_array ($volume) && !empty ($volume ['external']) && isset ($volume ['name']))
                    $names [$volume ['name']] = TRUE;
        }

        $missing = [];

        foreach (array_keys ($names) as $name)
        {
            exec (self::DOCKER .' volume inspect '. escapeshellarg ($name) .' > /dev/null 2>&1', $trash, $return);

            if ($return !== 0) $missing [] = $name;

            unset ($return);
        }

        return $missing;
    }

    // Mesmo comando que o 'io install' orienta: o de backup vai ligado à pasta BACKUP_DIR do host.
    static private function createVolumeCommand ($volume, $namespace)
    {
        if ($volume !== $namespace .'_backup')
            return 'docker volume create '. $volume;

        $dir = rtrim (trim ((string) getenv ('BACKUP_DIR')), '/');

        if ($dir === '') $dir = '/root/backup';

        return 'mkdir -p '. $dir .' && docker volume create --driver local --opt type=none --opt o=bind --opt device='. $dir .' '. $volume;
    }

    static private function requireCliService ($service)
    {
        exec (self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' config --services 2>/dev/null', $services, $return);

        if ($return !== 0)
            throw new Exception ("Unable to get the services in 'docker-compose.yaml'");

        if (!in_array ($service, array_map ('trim', $services)))
            throw new SkipException ("Build has no '". $service ."' service in 'docker-compose.yaml'");
    }

    static public function backup ($path, $namespace)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        chdir ($path);

        self::requireCliService ('backup');

        echo "INFO > Trying to execute backup service...\n";

        echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup'."\n";

        exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache backup 2>&1', $output1, $return1);

        if ($return1 !== 0)
        {
            echo implode ("\n", $output1) ."\n";

            throw new Exception ("Backup service failed to BUILD");
        }

        echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' run --rm --no-deps backup'."\n";

        exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' run --rm --no-deps backup 2>&1', $output2, $return2);

        if ($return2 !== 0)
        {
            echo implode ("\n", $output2) ."\n";

            throw new Exception ("Backup service failed to RUN");
        }
    }

    /**
     * Serviço 'restore' da app com BACKUP_FILE_TO_RESTORE = $file (nome do
     * .tar.gz na raiz do volume de backup), o mesmo contrato do restore da
     * plataforma (Doctor). A variável vai no ambiente da interpolação do Compose
     * e no contêiner (-e). As últimas linhas da saída são impressas sempre: é por
     * elas que se vê o que o serviço da app restaurou.
     */
    static public function restore ($path, $namespace, $file)
    {
        if (!preg_match ('/^[A-Za-z0-9._-]+\.tar\.gz$/', $file))
            throw new Exception ("Invalid file name to restore");

        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        chdir ($path);

        self::requireCliService ('restore');

        echo "INFO > Trying to execute restore service...\n";

        echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache restore'."\n";

        exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache restore 2>&1', $output1, $return1);

        if ($return1 !== 0)
        {
            echo implode ("\n", $output1) ."\n";

            throw new Exception ("Restore service failed to BUILD");
        }

        $run = self::env ('.env.sh') .' BACKUP_FILE_TO_RESTORE='. escapeshellarg ($file) .' timeout '. self::RESTORE_TIMEOUT .' '. self::DOCKER_COMPOSE .' run --rm --no-deps -e BACKUP_FILE_TO_RESTORE='. escapeshellarg ($file) .' restore';

        echo 'COMMAND > '. $run ."\n";

        exec ($run .' 2>&1', $output2, $return2);

        echo implode ("\n", array_slice ($output2, -40)) ."\n";

        if ($return2 !== 0)
            throw new Exception ($return2 === 124 ? "Restore service RUN timeout (". (self::RESTORE_TIMEOUT / 3600) ." hours)" : "Restore service failed to RUN");

        return array_slice ($output2, -40);
    }

    static public function sanitize ($path, $namespace)
    {
        exec ('type '. self::DOCKER_COMPOSE, $trash, $return);

        if ($return !== 0)
            throw new Exception ("Missing 'docker-compose' command");

        unset ($return);

        chdir ($path);

        self::requireCliService ('sanitize');

        echo "INFO > Trying to execute sanitize service...\n";

        echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache sanitize'."\n";

        exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' build --force-rm --no-cache sanitize 2>&1', $output1, $return1);

        if ($return1 !== 0)
        {
            echo implode ("\n", $output1) ."\n";

            throw new Exception ("Sanitize service failed to BUILD");
        }

        echo 'COMMAND > '. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' run --rm --no-deps sanitize'."\n";

        exec (''. self::env ('.env.sh') .' '. self::DOCKER_COMPOSE .' run --rm --no-deps sanitize 2>&1', $output2, $return2);

        if ($return2 !== 0)
        {
            echo implode ("\n", $output2) ."\n";

            throw new Exception ("Sanitize service failed to RUN");
        }
    }

    static public function cleaner ($path, $namespace, $policy, $dryRun = FALSE)
    {
        return self::rotateBackups ($path, $namespace, $policy, $dryRun);
    }

    static public function reference ()
    {
        $buffer  = "https://docs.docker.com/compose/reference/ \n\n";
        $buffer .= "Attention! To run commands, you need to inject the environment variables of the '.env.io' file. \n";
        $buffer .= "See the examples below: \n\n";
        $buffer .= "Remove stopped service containers: \n";
        $buffer .= "env \$(cat .env.io) docker-compose rm \n\n";
        $buffer .= "Display the running processes: \n";
        $buffer .= "env \$(cat .env.io) docker-compose top \n\n";
        $buffer .= "Open a console (bash) in the container of a service named 'app': \n";
        $buffer .= "env \$(cat .env.io) docker-compose exec app bash \n";

        return $buffer;
    }
}