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

    // Imagem mínima usada para listar/apagar arquivos dentro do volume de backup.
    const CLEANER_IMAGE = 'alpine:3';

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
    abstract public static function stop ($path, $namespace);
    abstract public static function restart ($path, $namespace);
    abstract public static function backup ($path, $namespace);
    abstract public static function sanitize ($path, $namespace);
    abstract public static function cleaner ($path, $namespace, $policy, $dryRun = FALSE);
    abstract public static function reference ();

    /**
     * Rotaciona os arquivos do volume externo de backup da build, aplicando a
     * política de retenção (ver Retention). Compartilhado pelos orquestradores:
     * o volume é acessado diretamente pelo Docker (docker run -v), sem depender
     * do serviço 'backup' da stack da aplicação.
     *
     * @return array Plano executado: ['keep' => [name => tags], 'delete' => [names]]
     */
    protected static function rotateBackups ($path, $namespace, $policy, $dryRun = FALSE)
    {
        $volume = static::backupVolume ($path, $namespace);

        echo "INFO > Backup volume: '". $volume ."' \n";

        $all = static::listBackupFiles ($volume);

        // Só os arquivos DESTA build ('{project}_{app}_{stage}_...'): o mesmo
        // volume/diretório pode ser compartilhado por várias builds (dica das
        // docs do Releaser) e arquivos de outras builds jamais são tocados.
        $prefix = $namespace .'_';

        $files = [];
        $foreign = 0;

        foreach ($all as $file)
            if (strpos ($file ['name'], $prefix) === 0) $files [] = $file; else $foreign++;

        $bytes = function ($list) { $t = 0; foreach ($list as $f) $t += intval ($f ['size'] ?? 0); return $t; };

        $stats = [
            'volume' => $volume,
            'files_before' => sizeof ($files),
            'bytes_before' => $bytes ($files),
            'foreign' => $foreign,
            'bytes_foreign' => $bytes (array_filter ($all, function ($f) use ($prefix) { return strpos ($f ['name'], $prefix) !== 0; }))
        ];

        if ($foreign)
            echo "INFO > Ignoring ". $foreign ." file(s) of other builds in the same volume (name does not start with '". $prefix ."'). \n";

        if (!sizeof ($files))
        {
            echo "WARNING > No backup files of this build found in volume! \n";

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

        echo "INFO > Keeping ". sizeof ($plan ['keep']) ." of ". (sizeof ($files) - sizeof ($plan ['ignore'])) ." backup file(s) [D = daily, W = weekly, M = monthly]: \n";

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

        if (!$dryRun) static::deleteBackupFiles ($volume, $plan ['delete']);

        return $plan;
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

            exec ('env $(cat .env.sh) '. static::DOCKER_COMPOSE .' config 2> /dev/null', $output, $return);

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

    /**
     * Lista os arquivos regulares na raiz do volume: [['name' => string, 'mtime' => int, 'size' => int], ...]
     */
    protected static function listBackupFiles ($volume)
    {
        $cmd = static::DOCKER .' run --rm -v '. escapeshellarg ($volume) .':/backup:ro '. static::CLEANER_IMAGE .' find /backup -maxdepth 1 -type f -exec stat -c "%Y|%s|%n" {} +';

        echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Impossible to list files of volume '". $volume ."'");
        }

        $files = [];

        foreach ($output as $line)
        {
            if (!preg_match ('/^(\d+)\|(\d+)\|\/backup\/(.+)$/', trim ($line), $m)) continue;

            $files [] = [ 'name' => $m [3], 'mtime' => intval ($m [1]), 'size' => intval ($m [2]) ];
        }

        return $files;
    }

    protected static function deleteBackupFiles ($volume, array $names)
    {
        $args = [];

        foreach ($names as $name)
            $args [] = escapeshellarg ('/backup/'. $name);

        $cmd = static::DOCKER .' run --rm -v '. escapeshellarg ($volume) .':/backup '. static::CLEANER_IMAGE .' rm -f -- '. implode (' ', $args);

        echo 'COMMAND > '. $cmd ."\n";

        exec ($cmd .' 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo implode ("\n", $output) ."\n";

            throw new Exception ("Impossible to delete files of volume '". $volume ."'");
        }
    }

    public static function checkSSHConnection ($host, $timeout = 30)
    {
        echo "INFO > Checking SSH connection to host '". $host ."'... ";

        exec (self::SSH .' -o ConnectTimeout='. $timeout .' -o PasswordAuthentication=no root@'. $host .' "echo \'.\' > /dev/null" 2>&1', $output, $return);

        if ($return !== 0)
        {
            echo "error! \n";

            echo implode ("\n", $output) ."\n";

            throw new Exception ('Impossible to connect in host "'. $host .'"');
        }

        echo "ok! \n";
    }
}
