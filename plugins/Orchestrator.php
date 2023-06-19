<?php

abstract class Orchestrator
{
    const SSH = '/usr/bin/ssh';

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

    abstract public static function validate ($path, $cluster, $ports);
    abstract public static function deploy ($path, $cluster, $ports);
    abstract public static function health ($cluster);
    abstract public static function restart ($path, $cluster);
    abstract public static function undeploy ($path, $cluster);
    abstract public static function backup ($path, $cluster);
    abstract public static function sanitize ($path, $cluster);

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
