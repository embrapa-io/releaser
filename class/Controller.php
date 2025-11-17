<?php

class Controller
{
    static private $single = FALSE;

    const VERSION_REGEX = [
        'alpha' => '/^([\d]+)\.([\d]{2})\.([1-9][0-2]?)-alpha\.([\d]+)$/',
        'beta' => '/^([\d]+)\.([\d]{2})\.([1-9][0-2]?)-beta\.([\d]+)$/',
        'release' => '/^([\d]+)\.([\d]{2})\.([1-9][0-2]?)-([\d]+)$/'
    ];

    const PATH = __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'controller'. DIRECTORY_SEPARATOR;

    private $boilerplates = [];
    private $clusters = NULL;
    private $types = [];

    private $orchestrator = NULL;
    private $environment = [];

    private final function __construct ()
	{
        echo "INFO > Trying to load metadata info... \n";

        $git = GitLab::singleton ();

        $load = $git->reposSearch ('io/boilerplate/metadata');

        if (!sizeof ($load))
            throw new Exception ("Repository 'io/boilerplate/metadata' not found!");

        $metadata = $load [0];

        $this->boilerplates = json_decode ($git->getFile ($metadata ['id'], 'boilerplates.json'));

        $this->clusters = json_decode ($git->getFile ($metadata ['id'], 'clusters.json'));

        $this->types = json_decode ($git->getFile ($metadata ['id'], 'orchestrators.json'));

        if (!is_array ($this->boilerplates) || !is_object ($this->clusters) || !is_array ($this->types))
            throw new Exception ("Metadata files not loaded!");

        $type = null;

        foreach ($this->types as $trash => $t)
        {
            if (getenv ('ORCHESTRATOR') != $t->type) continue;

            $type = $t;

            break;
        }

        if (!$type)
            throw new Exception ("Fail to load type '". getenv ('ORCHESTRATOR') ."'! See registered types in '". getenv ('GITLAB_URL') ."/io/boilerplate/metadata' at file 'orchestrators.json'.");

        $this->orchestrator = getenv ('ORCHESTRATOR');
        $this->environment = $type->variables;

        echo "INFO > Metadata info of boilerplates, clusters and types loaded! \n";
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    static public function validate ($slice)
    {
        global $_path, $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'validate.php';
    }

    static public function deploy ($slice, $force = '')
    {
        global $_daemon, $_nothing, $_path, $_data;

        $_force = $force == '--force' ? TRUE : FALSE;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'deploy.php';
    }

    static public function rollback ($build, $_version)
    {
        global $_path, $_data;

        $_builds = self::getBuilds ($_data, $build);

        require self::PATH .'rollback.php';
    }

    static public function stop ($slice)
    {
        global $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'stop.php';
    }

    static public function restart ($slice)
    {
        global $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'restart.php';
    }

    static public function backup ($slice)
    {
        global $_daemon, $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'backup.php';
    }

    static public function sanitize ($slice)
    {
        global $_daemon, $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'sanitize.php';
    }

    static public function info ()
    {
        global $_data;

        $_builds = self::getBuilds ($_data, '--all');

        require self::PATH .'info.php';
    }

    static public function mail ($addrs)
    {
        $addresses = [];

        foreach (explode (',', $addrs) as $trash => $addr)
            if (Mail::isValid ($addr))
                $addresses [] = $addr;

        if (!sizeof ($addresses))
        {
            echo "ERROR > No valid e-mail addresses! \n";

            return;
        }

        echo "INFO > Sending e-mail messages to ". implode (', ', array_merge ([ getenv ('LOG_MAIL') ], $addresses)) ."... \n";

        try
        {
            Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - E-MAIL TEST', "It's ok!", $addresses);

            echo "SUCCESS > Sended! \n";
        }
        catch (Exception $e)
        {
            echo "ERROR > ". $e->getMessage () ."! \n";
        }
    }

    static protected function score ($stage, $version)
    {
        if (!preg_match (self::VERSION_REGEX [$stage], $version, $matches)) return 0;

        if ((int) $matches [1] > 999) return 0;

        if ((int) $matches [2] < 10 || (int) $matches [2] > 99) return 0;

        if ((int) $matches [3] < 1 || (int) $matches [3] > 12) return 0;

        if ((int) $matches [4] > 9999) return 0;

        return (int) $matches [1] . $matches [2] . str_pad ($matches [3], 2, '0', STR_PAD_LEFT) . str_pad ($matches [4], 4, '0', STR_PAD_LEFT);
    }

    static protected function getBuilds ($data, $input)
    {
        $builds = $data . DIRECTORY_SEPARATOR .'builds.json';

        if (!file_exists ($builds) || !is_readable ($builds))
            throw new Exception ("Is needed configure builds of apps to deploy in file '". $builds ."'!");

        $_builds = [];

        $loaded = json_decode (file_get_contents ($builds));

        if (!is_array ($loaded) || !sizeof ($loaded))
            throw new Exception ("No builds configured (or malformed JSON) in file '". $builds ."'!");

        $slice = explode (',', $input);

        $all = sizeof ($slice) == 1 && trim ($slice [0]) == '--all' ? TRUE : FALSE;

        foreach ($loaded as $trash => $b)
        {
            $build  = $b->project .'/'. $b->app .'@'. $b->stage;

            if ($all || in_array ($build, $slice))
            {
                if (array_key_exists ($build, $_builds))
                    throw new Exception ("The build '". $build ."' was configured twice!");

                $_builds [$build] = $b;
            }
        }

        if (!sizeof ($_builds))
            throw new Exception ("No builds to deploy! Check settings file (apps/builds.json).");

        return $_builds;
    }
}
