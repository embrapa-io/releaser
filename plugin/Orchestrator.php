<?php

/**
 * Sinaliza que não há o que fazer na build (ex.: app sem serviço 'backup' ou
 * sem volume de backup — caso comum em apps de frontend). Os controllers
 * tratam como WARNING (vai no e-mail do daemon), nunca como ERROR.
 */
class SkipException extends Exception {}

abstract class Orchestrator
{
    const SSH = '/usr/bin/ssh';
    const DOCKER = '/usr/bin/docker';

    // Tempo máximo do pull e do build do `restart --upgrade` (o mesmo do deployer da plataforma).
    const UPGRADE_TIMEOUT = 1200;

    const CLI_SERVICES = [
        'backup',
        'restore',
        'sanitize',
        'test'
    ];

    private static $orchestrators = [
        'DockerCompose',
        'DockerSwarm'
    ];

    final private function __construct ()
    {}

    public static function exists ($name)
    {
        return in_array ($name, self::$orchestrators);
    }

    abstract public static function validate ($path, $namespace);
    abstract public static function deploy ($path, $namespace);
    abstract public static function stop ($path, $namespace, $down = FALSE);
    abstract public static function restart ($path, $namespace, $upgrade = FALSE);
    abstract public static function backup ($path, $namespace);
    abstract public static function sanitize ($path, $namespace);
    abstract public static function cleaner ($path, $namespace, $policy, $dryRun = FALSE);
    abstract public static function reference ();

    /**
     * Renova as imagens da build, como o deployer da plataforma no `restart` com
     * `upgrade`: baixa as imagens de registro (as *floating*, como `latest` ou
     * `8.3-apache`, mudam de conteúdo sem mudar de nome) e reconstrói os serviços
     * `build:` com `--pull`, que renova a imagem base do Dockerfile. Só os serviços
     * do `.env.io` (sem os CLI_SERVICES, que ficam no perfil `cli`). Falha de pull
     * de um serviço não aborta (`--ignore-pull-failures`); falha de build, sim.
     */
    protected static function upgradeImages ()
    {
        $timeout = 'timeout '. self::UPGRADE_TIMEOUT;

        echo "INFO > Pulling the images of the services (floating tags get the latest content)... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. $timeout .' '. static::DOCKER_COMPOSE .' pull --ignore-pull-failures --ignore-buildable'."\n";

        exec (''. self::env ('.env.io') .' '. $timeout .' '. static::DOCKER_COMPOSE .' pull --ignore-pull-failures --ignore-buildable 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception (self::timedOut ($return) ? 'Timeout ('. self::UPGRADE_TIMEOUT .' s) when pulling images with Docker Compose' : 'Error when pulling images with Docker Compose');

        unset ($return);
        unset ($output);

        echo "INFO > Rebuilding the services with 'build:' over a renewed base image... \n";

        echo 'COMMAND > '. self::env ('.env.io') .' '. $timeout .' '. static::DOCKER_COMPOSE .' build --pull'."\n";

        exec (''. self::env ('.env.io') .' '. $timeout .' '. static::DOCKER_COMPOSE .' build --pull 2>&1', $output, $return);

        if (sizeof ($output)) echo implode ("\n", $output) ."\n";

        if ($return !== 0)
            throw new Exception (self::timedOut ($return) ? 'Timeout ('. self::UPGRADE_TIMEOUT .' s) when building images with Docker Compose' : 'Error when building images with Docker Compose');
    }

    // Código de saída do `timeout` (GNU: 124; BusyBox: 143, por SIGTERM).
    private static function timedOut ($return)
    {
        return in_array ((int) $return, [ 124, 143 ]);
    }

    /**
     * Containers da build neste host, pelo rótulo da stack (Compose: projeto;
     * Swarm: namespace), no formato que o deployer da plataforma usa no health
     * check: serviço => [image, state, status, healthy, size, created, running].
     * Serviços de linha de comando (CLI_SERVICES) ficam de fora. Com réplicas,
     * vale o container em execução (ou o primeiro).
     */
    public static function containers ($namespace)
    {
        $format = '{{.Label "'. static::SERVICE_LABEL .'"}}|{{.Image}}|{{.State}}|{{.Status}}|{{.Size}}|{{.CreatedAt}}|{{.RunningFor}}';

        $cmd = static::DOCKER .' ps -a --filter '. escapeshellarg ('label='. static::STACK_LABEL .'='. $namespace) .' --format '. escapeshellarg ($format);

        exec ($cmd .' 2>&1', $output, $return);

        if ($return !== 0)
            throw new Exception ("Error in Docker command: ". implode (' ', $output));

        $services = [];

        foreach ($output as $line)
        {
            $parts = explode ('|', $line);

            if (sizeof ($parts) !== 7) continue;

            $service = trim ($parts [0]);

            // no Swarm o rótulo do serviço é '<namespace>_<serviço>'
            if (strpos ($service, $namespace .'_') === 0) $service = substr ($service, strlen ($namespace) + 1);

            if ($service === '' || in_array ($service, self::CLI_SERVICES)) continue;

            $state = trim ($parts [2]);

            if (array_key_exists ($service, $services) && ($services [$service]['state'] === 'running' || $state !== 'running')) continue;

            preg_match ('/^[^\(]+\(([^\)]+)\)$/', trim ($parts [3]), $h);

            preg_match ('/^[^\(]+\(virtual ([^\)]+)\)$/', trim ($parts [4]), $s);

            try
            {
                $created = (new DateTime (trim ($parts [5])))->setTimezone (new DateTimeZone ('America/Sao_Paulo'))->format ('j/n/y G:i');
            }
            catch (Exception $e)
            {
                $created = trim ($parts [5]);
            }

            $services [$service] = [
                'image' => trim ($parts [1]),
                'state' => $state,
                'status' => trim ($parts [3]),
                'healthy' => sizeof ($h) == 2 ? $h [1] : 'undefined',
                'size' => sizeof ($s) == 2 ? $s [1] : trim ($parts [4]),
                'created' => $created,
                'running' => trim ($parts [6])
            ];
        }

        ksort ($services);

        return $services;
    }

    /**
     * Rotaciona os arquivos do volume externo de backup da build, aplicando a
     * política de retenção (ver Retention). Compartilhado pelos orquestradores:
     * o volume é acessado diretamente pelo Docker (BackupVolume, um contêiner
     * auxiliar da imagem do Releaser), sem depender do serviço 'backup' da stack
     * da aplicação.
     *
     * @return array Plano executado: ['keep' => [name => tags], 'delete' => [names]]
     */
    protected static function rotateBackups ($path, $namespace, $policy, $dryRun = FALSE)
    {
        $volume = static::backupVolume ($path, $namespace);

        echo "INFO > Backup volume: '". $volume ."' \n";

        // Só os arquivos DESTA build ('{project}_{app}_{stage}_...'): ver BackupVolume::split ().
        $prefix = $namespace .'_';

        list ($files, $others) = BackupVolume::split (BackupVolume::files ($volume), $namespace);

        $foreign = sizeof ($others);

        $stats = [
            'volume' => $volume,
            'files_before' => sizeof ($files),
            'bytes_before' => BackupVolume::bytes ($files),
            'foreign' => $foreign,
            'bytes_foreign' => BackupVolume::bytes ($others)
        ];

        if ($foreign)
            echo "INFO > Ignoring ". $foreign ." file(s) of other builds in the same volume (name does not start with '". $prefix ."'). \n";

        if (!sizeof ($files))
        {
            echo "WARNING > No backup files of this build found in the volume! \n";

            return [ 'keep' => [], 'delete' => [], 'ignore' => [], 'stats' => $stats + [ 'files_after' => 0, 'bytes_after' => 0, 'bytes_deleted' => 0 ] ];
        }

        $plan = Retention::plan ($files, $policy);

        $sizes = [];

        foreach ($files as $file) $sizes [$file ['name']] = intval ($file ['size'] ?? 0);

        $deleted = 0;

        foreach ($plan ['delete'] as $name) $deleted += $sizes [$name] ?? 0;

        $plan ['stats'] = $stats + [ 'files_after' => sizeof ($files) - sizeof ($plan ['delete']), 'bytes_after' => $stats ['bytes_before'] - $deleted, 'bytes_deleted' => $deleted ];

        if (sizeof ($plan ['ignore']))
        {
            echo "WARNING > Ignoring ". sizeof ($plan ['ignore']) ." file(s) without a date in the name (they will never be deleted): \n";

            foreach ($plan ['ignore'] as $name)
                echo "  ". $name ."\n";
        }

        echo "INFO > Keeping ". sizeof ($plan ['keep']) ." of ". (sizeof ($files) - sizeof ($plan ['ignore'])) ." backup file(s) [D = daily, W = weekly, M = monthly, Y = yearly]: \n";

        foreach ($plan ['keep'] as $name => $tags)
            echo "  ". str_pad ($tags, 4) . str_pad (static::humanSize ($sizes [$name] ?? 0), 10) . $name ."\n";

        if (!sizeof ($plan ['delete']))
        {
            echo "INFO > Nothing to delete. \n";

            return $plan;
        }

        echo "INFO > ". ($dryRun ? 'Would delete' : 'Deleting') ." ". sizeof ($plan ['delete']) ." file(s) (". static::humanSize ($deleted) ."): \n";

        foreach ($plan ['delete'] as $name)
            echo "  ". str_pad (static::humanSize ($sizes [$name] ?? 0), 10) . $name ."\n";

        if (!$dryRun) BackupVolume::delete ($volume, $plan ['delete']);

        return $plan;
    }

    /**
     * Copia os backups da build para o bucket S3 (ver Cloud) e rotaciona o que
     * está lá com a política do bucket (S3_RETENTION). Compartilhado pelos
     * orquestradores: só depende do volume de backup. Nesta ordem:
     *
     *   1. lista o volume (só arquivos com o prefixo da build);
     *   2. lista o diretório da build no bucket (idem);
     *   3. envia o que falta no bucket (`copy --ignore-existing`, nunca `sync`);
     *   4. lista o bucket de novo, aplica a retenção e apaga o excedente.
     *
     * O que falta no bucket mas já está fora da retenção dele (ex.: o volume
     * guarda mais que o bucket) NÃO é enviado: seria enviado e apagado de novo
     * a cada dia. Nada é apagado do bucket por ter sumido do volume, e arquivos
     * sem data no nome são enviados e nunca apagados (salvo em "undated").
     *
     * @return array ['keep' => [name => tags], 'delete' => [names], 'upload' => [names], 'stats' => [...]]
     */
    public static function cloud ($path, $namespace, $dir, $policy, $dryRun = FALSE)
    {
        $volume = static::backupVolume ($path, $namespace);

        echo "INFO > Backup volume: '". $volume ."' \n";

        list ($local, $others) = BackupVolume::split (BackupVolume::files ($volume), $namespace);

        if (sizeof ($others))
            echo "INFO > Ignoring ". sizeof ($others) ." file(s) of other builds in the same volume (name does not start with '". $namespace ."_'). \n";

        echo "INFO > Bucket: '". Cloud::url ($dir) ."' at '". Cloud::value ('S3_ENDPOINT') ."' \n";

        list ($remote, $others) = BackupVolume::split (Cloud::files ($dir), $namespace);

        if (sizeof ($others))
            echo "INFO > Ignoring ". sizeof ($others) ." file(s) in the bucket whose name does not start with '". $namespace ."_'. \n";

        echo "INFO > Files of this build: ". sizeof ($local) ." in the volume (". static::humanSize (BackupVolume::bytes ($local)) ."), ". sizeof ($remote) ." in the bucket (". static::humanSize (BackupVolume::bytes ($remote)) ."). \n";

        $stats = [
            'volume' => $volume,
            'files_before' => sizeof ($remote),
            'bytes_before' => BackupVolume::bytes ($remote),
            'uploaded' => 0,
            'bytes_uploaded' => 0,
            'skipped' => 0,
            'kept' => 0,
            'deleted' => 0,
            'bytes_deleted' => 0
        ];

        if (!sizeof ($local) && !sizeof ($remote))
        {
            echo "WARNING > No backup files of this build found in the volume nor in the bucket! \n";

            return [ 'keep' => [], 'delete' => [], 'upload' => [], 'stats' => $stats + [ 'files_after' => 0, 'bytes_after' => 0 ] ];
        }

        $inBucket = array_column ($remote, NULL, 'name');

        $missing = static::newestFirst (array_filter ($local, function ($f) use ($inBucket) { return !array_key_exists ($f ['name'], $inBucket); }));

        // plano sobre o que o bucket terá: o que já está lá mais o que falta
        $preview = Retention::plan (array_merge ($remote, $missing), $policy);

        $upload = [];
        $skip = [];

        foreach ($missing as $file)
            if (in_array ($file ['name'], $preview ['delete'])) $skip [] = $file; else $upload [] = $file;

        // a lista pode ser longa e se repetir todo dia (volume que guarda mais que o bucket): só os mais recentes
        if (sizeof ($skip))
        {
            echo "INFO > Not uploading ". sizeof ($skip) ." old file(s), already outside the retention of the bucket". (sizeof ($skip) > 10 ? ' (the 10 most recent)' : '') .": \n";

            foreach (array_slice ($skip, 0, 10) as $file)
                echo "  ". str_pad (static::humanSize ($file ['size']), 10) . $file ['name'] ."\n";
        }

        $stats ['skipped'] = sizeof ($skip);
        $stats ['uploaded'] = sizeof ($upload);
        $stats ['bytes_uploaded'] = BackupVolume::bytes ($upload);

        if (!sizeof ($upload))
            echo "INFO > Nothing to upload: the bucket already has every backup of the volume. \n";
        else
        {
            echo "INFO > ". ($dryRun ? 'Would upload' : 'Uploading') ." ". sizeof ($upload) ." file(s) (". static::humanSize ($stats ['bytes_uploaded']) ."): \n";

            foreach ($upload as $file)
                echo "  ". str_pad (static::humanSize ($file ['size']), 10) . $file ['name'] ."\n";
        }

        if ($dryRun)
            $after = array_merge ($remote, $upload);
        else
        {
            Cloud::upload ($volume, $dir, array_column ($upload, 'name'));

            // a retenção vale sobre o que REALMENTE está no bucket agora
            list ($after, $trash) = BackupVolume::split (Cloud::files ($dir), $namespace);

            $present = array_column ($after, NULL, 'name');

            foreach ($upload as $file)
                if (!array_key_exists ($file ['name'], $present) || intval ($present [$file ['name']]['size']) !== intval ($file ['size']))
                    throw new Exception ("File '". $file ['name'] ."' is missing (or has a different size) in the bucket after the upload. Nothing was deleted from the bucket");
        }

        $plan = Retention::plan ($after, $policy);

        $sizes = array_column ($after, 'size', 'name');

        if (sizeof ($plan ['ignore']))
        {
            echo "WARNING > ". sizeof ($plan ['ignore']) ." file(s) without a date in the name in the bucket (they will never be deleted from it): \n";

            foreach ($plan ['ignore'] as $name)
                echo "  ". $name ."\n";
        }

        echo "INFO > Keeping ". sizeof ($plan ['keep']) ." of ". (sizeof ($after) - sizeof ($plan ['ignore'])) ." backup file(s) in the bucket [D = daily, W = weekly, M = monthly, Y = yearly]: \n";

        foreach ($plan ['keep'] as $name => $tags)
            echo "  ". str_pad ($tags, 4) . str_pad (static::humanSize ($sizes [$name] ?? 0), 10) . $name ."\n";

        $deleted = 0;

        foreach ($plan ['delete'] as $name) $deleted += intval ($sizes [$name] ?? 0);

        $stats ['kept'] = sizeof ($plan ['keep']) + sizeof ($plan ['ignore']);
        $stats ['deleted'] = sizeof ($plan ['delete']);
        $stats ['bytes_deleted'] = $deleted;
        $stats ['files_after'] = sizeof ($after) - sizeof ($plan ['delete']);
        $stats ['bytes_after'] = BackupVolume::bytes ($after) - $deleted;

        $plan ['upload'] = array_column ($upload, 'name');
        $plan ['stats'] = $stats;

        if (!sizeof ($plan ['delete']))
        {
            echo "INFO > Nothing to delete from the bucket. \n";

            return $plan;
        }

        echo "INFO > ". ($dryRun ? 'Would delete' : 'Deleting') ." ". sizeof ($plan ['delete']) ." file(s) from the bucket (". static::humanSize ($deleted) ."): \n";

        foreach ($plan ['delete'] as $name)
            echo "  ". str_pad (static::humanSize ($sizes [$name] ?? 0), 10) . $name ."\n";

        if (!$dryRun) Cloud::delete ($dir, $plan ['delete']);

        return $plan;
    }

    // Do mais recente ao mais antigo, pela data do nome (ou de modificação, se não houver).
    protected static function newestFirst (array $files)
    {
        $files = array_values ($files);

        usort ($files, function ($a, $b) {
            $ta = Retention::timestampFromName ($a ['name']) ?? intval ($a ['mtime']);
            $tb = Retention::timestampFromName ($b ['name']) ?? intval ($b ['mtime']);

            return $tb <=> $ta ?: strcmp ($b ['name'], $a ['name']);
        });

        return $files;
    }

    /**
     * `io cloud --list`: o que há no bucket para a build, com a marca que a
     * próxima rotação daria a cada arquivo (D/W/M/Y, '-' = sai, '?' = sem data).
     * Só lê o bucket: não precisa do volume nem da versão implantada.
     */
    public static function cloudList ($namespace, $dir, $policy)
    {
        echo "INFO > Bucket: '". Cloud::url ($dir) ."' at '". Cloud::value ('S3_ENDPOINT') ."' \n";

        list ($remote, $others) = BackupVolume::split (Cloud::files ($dir), $namespace);

        if (sizeof ($others))
            echo "INFO > Ignoring ". sizeof ($others) ." file(s) whose name does not start with '". $namespace ."_'. \n";

        if (!sizeof ($remote))
        {
            echo "INFO > No backup files of this build in the bucket. \n";

            return [ 'files' => 0, 'bytes' => 0 ];
        }

        $plan = Retention::plan ($remote, $policy);

        $remote = static::newestFirst ($remote);

        echo "INFO > ". sizeof ($remote) ." file(s), ". static::humanSize (BackupVolume::bytes ($remote)) ." [D/W/M/Y = kept by the next rotation, - = deleted by it, ? = no date in the name, never deleted]: \n";

        foreach ($remote as $file)
        {
            $tag = $plan ['keep'][$file ['name']] ?? (in_array ($file ['name'], $plan ['ignore']) ? '?' : '-');

            echo "  ". str_pad ($tag, 4) . str_pad (static::humanSize ($file ['size']), 10) . str_pad ($file ['mtime'] ? date ('Y-m-d H:i', $file ['mtime']) : '?', 18) . $file ['name'] ."\n";
        }

        return [ 'files' => sizeof ($remote), 'bytes' => BackupVolume::bytes ($remote) ];
    }

    /**
     * Descobre o nome do volume de backup da build. Convenção da plataforma:
     * '{project}_{app}_{stage}_backup'. Se o docker-compose.yaml interpolado
     * declarar um volume chamado 'backup' (ou cujo nome termine em '_backup'),
     * esse nome prevalece. O volume precisa existir no Docker.
     */
    protected static function backupVolume ($path, $namespace)
    {
        $volume = $namespace .'_backup';

        $compose = NULL;

        foreach ([ 'docker-compose.yaml', 'docker-compose.yml' ] as $file)
            if (file_exists ($path . DIRECTORY_SEPARATOR . $file)) { $compose = $file; break; }

        if ($compose !== NULL && file_exists ($path . DIRECTORY_SEPARATOR .'.env.sh') && function_exists ('yaml_parse'))
        {
            $cwd = getcwd ();

            chdir ($path);

            exec (self::env ('.env.sh') .' '. static::DOCKER_COMPOSE .' config 2> /dev/null', $output, $return);

            chdir ($cwd);

            if ($return === 0 && sizeof ($output))
            {
                $config = @yaml_parse (implode ("\n", $output));

                if (is_array ($config) && array_key_exists ('volumes', $config) && is_array ($config ['volumes']))
                {
                    foreach ($config ['volumes'] as $key => $definition)
                    {
                        $name = is_array ($definition) && array_key_exists ('name', $definition) ? trim ($definition ['name']) : $key;

                        if ($key === 'backup' || preg_match ('/_backup$/', $name)) { $volume = $name; break; }
                    }
                }
            }
        }

        exec (static::DOCKER .' volume inspect '. escapeshellarg ($volume) .' > /dev/null 2>&1', $trash, $return);

        if ($return !== 0)
            throw new SkipException ("Backup volume '". $volume ."' not found (app has no 'backup' service?)");

        return $volume;
    }

    /**
     * Prefixo de ambiente para os comandos docker-compose das builds.
     *
     * `env -i` isola o subprocesso do ambiente do próprio Releaser: o bin/io e os
     * jobs exportam o /data/.env (SERVER, SMTP_HOST, SMTP_PORT, SMTP_SECURE, ...)
     * e variável de ambiente tem precedência sobre o .env do projeto no Compose.
     * Sem o isolamento, uma app que use os mesmos nomes (ex.: SMTP_* do Leantime)
     * recebe os valores do Releaser em vez dos do builds.json. Só sobrevivem as
     * variáveis necessárias ao docker/compose e as do arquivo da build (.env.io
     * ou .env.sh), exatamente como antes.
     */
    protected static function env (...$files)
    {
        $keep = [];

        foreach ([ 'PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY', 'DOCKER_BUILDKIT', 'COMPOSE_DOCKER_CLI_BUILD' ] as $name)
        {
            $value = getenv ($name);

            if ($value !== FALSE && $value !== '') $keep [] = $name .'='. escapeshellarg ($value);
        }

        $cat = [];

        foreach ($files as $file) $cat [] = 'cat '. $file;

        return 'env -i '. implode (' ', $keep) .' $('. implode (' && ', $cat) .')';
    }

    public static function humanSize ($bytes)
    {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

        $i = 0;

        $value = floatval ($bytes);

        while ($value >= 1024 && $i < sizeof ($units) - 1) { $value /= 1024; $i++; }

        return number_format ($value, $i ? 1 : 0, ',', '.') .' '. $units [$i];
    }

    public static function checkSSHConnection ($host, $timeout = 30)
    {
        echo "INFO > Checking SSH connection to host '". $host ."'... ";

        exec (self::SSH .' -o ConnectTimeout='. $timeout .' -o PasswordAuthentication=no root@'. $host .' "echo \'.\' > /dev/null" 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo "error! \n";

            echo implode ("\n", $output) ."\n";

            throw new Exception ('Unable to connect to host "'. $host .'"');
        }

        echo "ok! \n";
    }
}
