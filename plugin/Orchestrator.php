<?php

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

        $files = static::listBackupFiles ($volume);

        if (!sizeof ($files))
        {
            echo "WARNING > No backup files found in volume! \n";

            return [ 'keep' => [], 'delete' => [] ];
        }

        $plan = Retention::plan ($files, $policy);

        if (sizeof ($plan ['ignore']))
        {
            echo "WARNING > Ignoring ". sizeof ($plan ['ignore']) ." file(s) without a date in the name (they will never be deleted): \n";

            foreach ($plan ['ignore'] as $name)
                echo "  ". $name ."\n";
        }

        echo "INFO > Keeping ". sizeof ($plan ['keep']) ." of ". (sizeof ($files) - sizeof ($plan ['ignore'])) ." backup file(s) [D = daily, W = weekly, M = monthly]: \n";

        foreach ($plan ['keep'] as $name => $tags)
            echo "  ". str_pad ($tags, 4) . $name ."\n";

        if (!sizeof ($plan ['delete']))
        {
            echo "INFO > Nothing to delete. \n";

            return $plan;
        }

        echo "INFO > ". ($dryRun ? 'Would delete' : 'Deleting') ." ". sizeof ($plan ['delete']) ." file(s): \n";

        foreach ($plan ['delete'] as $name)
            echo "  ". $name ."\n";

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
            throw new Exception ("Backup volume '". $volume ."' not found");

        return $volume;
    }

    /**
     * Lista os arquivos regulares na raiz do volume: [['name' => string, 'mtime' => int], ...]
     */
    protected static function listBackupFiles ($volume)
    {
        $cmd = static::DOCKER .' run --rm -v '. escapeshellarg ($volume) .':/backup:ro '. static::CLEANER_IMAGE .' find /backup -maxdepth 1 -type f -exec stat -c "%Y|%n" {} +';

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
            if (!preg_match ('/^(\d+)\|\/backup\/(.+)$/', trim ($line), $m)) continue;

            $files [] = [ 'name' => $m [2], 'mtime' => intval ($m [1]) ];
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
