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
        echo "INFO > Trying to load metadata... \n";

        // Os catálogos (boilerplates, clusters e orquestradores) vêm da rota pública
        // do backend da plataforma, sem token. O repositório io/boilerplate/metadata
        // deixou de ser público em 14/09/2026 e o token pessoal configurado no
        // Releaser nem sempre o enxerga (ex.: usuário marcado como "external" no
        // GitLab). O GitLab fica como fallback, para o caso de o backend estar fora.
        try
        {
            $this->boilerplates = self::metadata ('boilerplates.json');
            $this->clusters = self::metadata ('clusters.json');
            $this->types = self::metadata ('orchestrators.json');
        }
        catch (Exception $e)
        {
            echo "WARNING > Unable to load metadata from '". self::apiUrl () ."' (". $e->getMessage () ."). Falling back to GitLab... \n";

            $git = GitLab::singleton ();

            $load = $git->reposSearch ('io/boilerplate/metadata');

            if (!sizeof ($load))
                throw new Exception ("Repository 'io/boilerplate/metadata' not found!");

            $metadata = $load [0];

            $this->boilerplates = json_decode ($git->getFile ($metadata ['id'], 'boilerplates.json'));

            $this->clusters = json_decode ($git->getFile ($metadata ['id'], 'clusters.json'));

            $this->types = json_decode ($git->getFile ($metadata ['id'], 'orchestrators.json'));
        }

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
            throw new Exception ("Unable to load orchestrator type '". getenv ('ORCHESTRATOR') ."'! See the registered types in '". getenv ('GITLAB_URL') ."/io/boilerplate/metadata' (file 'orchestrators.json').");

        $this->orchestrator = getenv ('ORCHESTRATOR');
        $this->environment = $type->variables;

        echo "INFO > Metadata of boilerplates, clusters and orchestrators loaded! \n";
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    // URL do backend da plataforma (rota /metadata/:file). Sobrescrevível pelo
    // IO_API_URL do /data/.env — só faz sentido em instâncias fora do embrapa.io.
    static public function apiUrl ()
    {
        $url = trim ((string) getenv ('IO_API_URL'));

        return rtrim ($url !== '' ? $url : 'https://core.embrapa.io', '/');
    }

    // Baixa e decodifica um dos catálogos: boilerplates.json, clusters.json ou
    // orchestrators.json. Timeouts curtos: se o backend não responder, o
    // construtor cai no fallback pelo GitLab em vez de segurar o daemon.
    static private function metadata ($file)
    {
        $client = new GuzzleHttp\Client ([
            'connect_timeout' => 10,
            'timeout' => 30
        ]);

        $response = $client->request ('GET', self::apiUrl () .'/metadata/'. $file);

        $decoded = json_decode ((string) $response->getBody ());

        if ($decoded === NULL)
            throw new Exception ("Invalid JSON in '". $file ."'");

        return $decoded;
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

    static public function stop ($slice, $flag = NULL)
    {
        global $_data;

        // `--down`: remove os containers da stack (docker compose down), não só os para; volumes ficam
        if ($flag !== NULL && trim ((string) $flag) !== '--down')
            throw new Exception ("Unknown parameter '". $flag ."'. The only option accepted by 'stop' is '--down'");

        $_down = $flag !== NULL;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'stop.php';
    }

    static public function restart ($slice, $flag = NULL)
    {
        global $_daemon, $_data;

        // `--upgrade` (ou `--update`): renova as imagens antes de subir, como o deployer da plataforma. Não há
        // `--remove` (decisão de out/2026): o `down --volumes` do deployer apagaria volumes não externos.
        if (in_array (trim ((string) $slice), [ '--upgrade', '--update' ]))
            throw new Exception ("Specify the builds (or '--all') before '". trim ($slice) ."'. E.g.: io restart proj-a/app-1@beta ". trim ($slice));

        if ($flag !== NULL && !in_array (trim ((string) $flag), [ '--upgrade', '--update' ]))
            throw new Exception ("Unknown parameter '". $flag ."'. The only option accepted by 'restart' is '--upgrade' (or its synonym '--update')");

        $_upgrade = $flag !== NULL;

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

    static public function cleaner ($slice, $flag = NULL)
    {
        global $_daemon, $_data;

        // `io cleaner --dry-run` (sem lista de builds) equivale a `--all --dry-run`
        if (trim ((string) $slice) === '--dry-run') { $slice = '--all'; $flag = '--dry-run'; }

        if ($flag !== NULL && trim ((string) $flag) !== '--dry-run')
            throw new Exception ("Unknown parameter '". $flag ."'. The only option accepted by 'cleaner' is '--dry-run'");

        $_builds = self::getBuilds ($_data, $slice);

        $_flag = $flag;

        require self::PATH .'cleaner.php';
    }

    static public function cloud ($slice, $flag = NULL)
    {
        global $_daemon, $_data;

        // `io cloud --setup` (perguntas do S3 e da retenção) é interativo e roda no entrypoint `io` (bin/io), não aqui
        if (trim ((string) $slice) === '--setup')
            throw new Exception ("'cloud --setup' is interactive and runs in the 'io' entrypoint: docker exec -it releaser io cloud --setup");

        // `io cloud --dry-run` (ou `--list`, `--verify`), sem lista de builds, equivale a `--all --dry-run` etc.
        if (in_array (trim ((string) $slice), [ '--dry-run', '--list', '--verify' ])) { $flag = trim ($slice); $slice = '--all'; }

        if ($flag !== NULL && !in_array (trim ((string) $flag), [ '--dry-run', '--list', '--verify' ]))
            throw new Exception ("Unknown parameter '". $flag ."'. The only options accepted by 'cloud' are '--dry-run', '--list', '--verify' and '--setup'");

        $_builds = self::getBuilds ($_data, $slice);

        $_mode = $flag === NULL ? '' : trim ((string) $flag);

        require self::PATH .'cloud.php';
    }

    /**
     * `io restore <build> [--list] [--file NOME] [--from local|s3] [--sha256 HASH] [--check] [--yes]
     * [--accept-cross-stage] [--accept-upload]`: uma build por vez (ver controller/restore.php).
     */
    static public function restore ($slice, ...$args)
    {
        global $_daemon, $_data;

        $slice = trim ((string) $slice);

        if ($slice === '--all' || strpos ($slice, ',') !== FALSE)
            throw new Exception ("'restore' works on one build at a time (e.g., 'io restore my-project/api@release')");

        $_opts = [ 'list' => FALSE, 'file' => NULL, 'from' => NULL, 'sha256' => NULL, 'check' => FALSE, 'yes' => FALSE, 'cross' => FALSE, 'upload' => FALSE ];

        for ($i = 0; $i < sizeof ($args); $i++)
        {
            $arg = trim ((string) $args [$i]);

            $value = function ($name) use ($args, &$i, $arg)
            {
                if (strpos ($arg, $name .'=') === 0) return trim (substr ($arg, strlen ($name) + 1));

                if (!isset ($args [$i + 1])) throw new Exception ("Missing value for '". $name ."'");

                return trim ((string) $args [++$i]);
            };

            if ($arg === '--list') $_opts ['list'] = TRUE;
            elseif ($arg === '--check') $_opts ['check'] = TRUE;
            elseif ($arg === '--yes') $_opts ['yes'] = TRUE;
            elseif ($arg === '--accept-cross-stage') $_opts ['cross'] = TRUE;
            elseif ($arg === '--accept-upload') $_opts ['upload'] = TRUE;
            elseif ($arg === '--file' || strpos ($arg, '--file=') === 0) $_opts ['file'] = $value ('--file');
            elseif ($arg === '--from' || strpos ($arg, '--from=') === 0) $_opts ['from'] = $value ('--from');
            elseif ($arg === '--sha256' || strpos ($arg, '--sha256=') === 0) $_opts ['sha256'] = strtolower ($value ('--sha256'));
            else
                throw new Exception ("Unknown parameter '". $arg ."'. See 'io restore --help'");
        }

        if ($_opts ['from'] !== NULL && !in_array ($_opts ['from'], [ 'local', 's3' ]))
            throw new Exception ("Invalid '--from': use 'local' or 's3'");

        if ($_opts ['sha256'] !== NULL && !preg_match ('/^[0-9a-f]{64}$/', $_opts ['sha256']))
            throw new Exception ("Invalid '--sha256': 64 hexadecimal characters");

        if ($_opts ['yes'] && $_opts ['file'] === NULL && !$_opts ['list'])
            throw new Exception ("'--yes' requires '--file': without questions, the file must be chosen explicitly");

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'restore.php';
    }

    /**
     * Há um `io restore` em andamento na build? (trava /data/.restore/<proj>_<app>_<stage>.lock com o PID).
     * Trava de processo que já não existe é removida. Os comandos do daemon pulam a build enquanto houver.
     */
    static public function restoring ($b)
    {
        global $_data;

        $lock = ($_data ? $_data : '/data') .'/.restore/'. $b->project .'_'. $b->app .'_'. $b->stage .'.lock';

        if (!file_exists ($lock)) return FALSE;

        $pid = intval (trim ((string) file_get_contents ($lock)));

        if ($pid > 0 && file_exists ('/proc/'. $pid)) return TRUE;

        echo "WARNING > Removing a stale restore lock of build '". $b->project .'/'. $b->app .'@'. $b->stage ."' (process ". $pid ." is gone). \n";

        try { unlink ($lock); } catch (Exception $e) {}

        return FALSE;
    }

    static public function cli ($flag = NULL, $flag2 = NULL)
    {
        $_flags = [];

        foreach ([ $flag, $flag2 ] as $f)
        {
            if ($f === NULL) continue;

            if (!in_array (trim ((string) $f), [ '--print', '--uninstall', '--yes' ]))
                throw new Exception ("Unknown parameter '". $f ."'. The options accepted by 'cli' are '--print', '--uninstall' and '--yes'");

            $_flags [] = trim ($f);
        }

        require self::PATH .'cli.php';
    }

    static public function upgrade (...$args)
    {
        global $_data, $lifetimes;

        $_to = NULL;
        $_yes = FALSE;
        $_dry = FALSE;
        $_helper = FALSE;

        for ($i = 0; $i < sizeof ($args); $i++)
        {
            $arg = trim ((string) $args [$i]);

            if ($arg === '--yes') $_yes = TRUE;
            elseif ($arg === '--dry-run') $_dry = TRUE;
            elseif ($arg === '--helper') $_helper = TRUE; // interno: o contêiner auxiliar da troca
            elseif ($arg === '--to' && isset ($args [$i + 1])) $_to = trim ((string) $args [++$i]);
            elseif (strpos ($arg, '--to=') === 0) $_to = trim (substr ($arg, 5));
            else
                throw new Exception ("Unknown parameter '". $arg ."'. The options accepted by 'upgrade' are '--to VERSION', '--yes' and '--dry-run'");
        }

        $_lifetimes = is_array ($lifetimes) ? $lifetimes : [];

        require self::PATH .'upgrade.php';
    }

    static public function info ()
    {
        global $_data;

        // sem builds, o bloco do próprio Releaser (versão, última atualização) sai antes do erro
        $_noBuilds = NULL;

        try
        {
            $_builds = self::getBuilds ($_data, '--all');
        }
        catch (Exception $e)
        {
            $_builds = [];

            $_noBuilds = $e;
        }

        require self::PATH .'info.php';
    }

    static public function health ($slice)
    {
        global $_daemon, $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'health.php';
    }

    static public function cve ($slice)
    {
        global $_daemon, $_data;

        $_builds = self::getBuilds ($_data, $slice);

        require self::PATH .'cve.php';
    }

    static public function check ()
    {
        global $_daemon, $_data;

        require self::PATH .'check.php';
    }

    static public function install ($target = '', $flag = '')
    {
        global $_data;

        // `io install --yes` (sem build) e `io install proj/app@stage --yes`
        if (trim ((string) $target) === '--yes') { $flag = '--yes'; $target = ''; }

        if ($flag !== '' && trim ((string) $flag) !== '--yes')
            throw new Exception ("Unknown parameter '". $flag ."'. The only option accepted by 'install' is '--yes'");

        $_target = trim ((string) $target);
        $_yes = trim ((string) $flag) === '--yes';

        require self::PATH .'install.php';
    }

    static public function split ()
    {
        global $_data;

        require self::PATH .'split.php';
    }

    /**
     * O que a build reporta ao core (atributo `stats` do arquivo da build):
     * { version, cve, health }, TODOS verdadeiros por padrão, inclusive quando
     * ausentes; `"stats": false` desliga os três. Lido só pelo Releaser.
     */
    static public function stats ($b)
    {
        $all = [ 'version' => TRUE, 'cve' => TRUE, 'health' => TRUE ];

        if (!isset ($b->stats)) return (object) $all;

        if ($b->stats === FALSE) return (object) array_fill_keys (array_keys ($all), FALSE);

        if (!is_object ($b->stats)) return (object) $all;

        foreach ($all as $key => $trash)
            if (isset ($b->stats->$key) && $b->stats->$key === FALSE) $all [$key] = FALSE;

        return (object) $all;
    }

    static public function mail ($addrs)
    {
        $addresses = [];

        foreach (explode (',', $addrs) as $trash => $addr)
            if (Mail::isValid (trim ($addr)))
                $addresses [] = trim ($addr);

        if (!sizeof ($addresses))
        {
            echo "ERROR > No valid e-mail addresses! \n";

            return;
        }

        // Mail::send() nunca lança exceção (falha vira WARNING e fila): o teste
        // usa test(), que devolve o resultado real do envio.
        if (Mail::singleton ()->test ('Releaser at '. getenv ('SERVER') .' - E-MAIL TEST', "It's ok!", $addresses))
            echo "SUCCESS > E-mail sent! \n";
        else
            echo "ERROR > The test e-mail could not be sent! See the SMTP attempts above. \n";
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

    /**
     * Carrega as builds configuradas: o legado /data/builds.json (todas num
     * arquivo) e/ou /data/builds/<projeto>_<app>_<estágio>.json (um por build;
     * qualquer outra extensão, como .bkp, é ignorada = build desativada). O nome
     * do arquivo precisa bater com o conteúdo, e a mesma build em dois lugares é
     * erro — nunca precedência silenciosa. Cada build ganha `_file` (origem).
     */
    static public function loadBuilds ($data)
    {
        $legacy = $data . DIRECTORY_SEPARATOR .'builds.json';

        $dir = $data . DIRECTORY_SEPARATOR .'builds';

        $builds = [];

        $sources = [];

        if (file_exists ($legacy))
        {
            if (!is_readable ($legacy))
                throw new Exception ("File '". $legacy ."' is not readable!");

            $loaded = json_decode ((string) file_get_contents ($legacy));

            if (!is_array ($loaded))
                throw new Exception ("Malformed JSON in file '". $legacy ."'!");

            foreach ($loaded as $i => $b)
            {
                if (!is_object ($b) || !isset ($b->project, $b->app, $b->stage))
                    throw new Exception ("Entry #". ($i + 1) ." of '". $legacy ."' has no project, app or stage!");

                $build = $b->project .'/'. $b->app .'@'. $b->stage;

                if (array_key_exists ($build, $builds))
                    throw new Exception ("The build '". $build ."' was configured twice in '". $legacy ."'!");

                $b->_file = $legacy;

                $builds [$build] = $b;
            }
        }

        if (file_exists ($dir) && is_dir ($dir))
        {
            $files = glob ($dir . DIRECTORY_SEPARATOR .'*.json') ?: [];

            sort ($files);

            foreach ($files as $file)
            {
                $b = json_decode ((string) file_get_contents ($file));

                if (!is_object ($b) || !isset ($b->project, $b->app, $b->stage))
                    throw new Exception ("File '". $file ."' is not a valid build (malformed JSON or missing project/app/stage)!");

                $expected = $b->project .'_'. $b->app .'_'. $b->stage .'.json';

                if (basename ($file) !== $expected)
                    throw new Exception ("File '". $file ."' must be named '". $expected ."' (project_app_stage.json) to match its content!");

                $build = $b->project .'/'. $b->app .'@'. $b->stage;

                if (array_key_exists ($build, $builds))
                    throw new Exception ("The build '". $build ."' is configured twice: in '". $builds [$build]->_file ."' and in '". $file ."'!");

                $b->_file = $file;

                $builds [$build] = $b;
            }
        }

        if (!file_exists ($legacy) && !(file_exists ($dir) && is_dir ($dir)))
            throw new Exception ("The builds to deploy must be configured: one file per build in '". $dir ."' (or the legacy '". $legacy ."')!");

        return $builds;
    }

    /** Builds desativadas: arquivos em /data/builds que não terminam em .json (ex.: .bkp). */
    static public function disabledBuilds ($data)
    {
        $dir = $data . DIRECTORY_SEPARATOR .'builds';

        if (!file_exists ($dir) || !is_dir ($dir)) return [];

        $disabled = [];

        foreach (scandir ($dir) ?: [] as $entry)
            if ($entry [0] !== '.' && is_file ($dir . DIRECTORY_SEPARATOR . $entry) && !preg_match ('/\.json$/', $entry)) $disabled [] = $entry;

        return $disabled;
    }

    static protected function getBuilds ($data, $input)
    {
        $loaded = self::loadBuilds ($data);

        if (!sizeof ($loaded))
            throw new Exception ("No builds configured! Add one file per build in '". $data . DIRECTORY_SEPARATOR ."builds/' (see 'io install').");

        $_builds = [];

        $slice = array_map ('trim', explode (',', (string) $input));

        $all = sizeof ($slice) == 1 && $slice [0] == '--all' ? TRUE : FALSE;

        foreach ($loaded as $build => $b)
            if ($all || in_array ($build, $slice))
                $_builds [$build] = $b;

        if (!sizeof ($_builds))
            throw new Exception ("No builds to process! Check the builds configured in '". $data . DIRECTORY_SEPARATOR ."builds/' (and the legacy builds.json).");

        return $_builds;
    }
}
