<?php

/**
 * Configuração guiada de uma build (fase 1): escolhe projeto, app e estágio,
 * lê do core o que só a plataforma sabe (equipe, DSN do Sentry, site e token
 * do Matomo, variáveis do .embrapa/settings.json da tag do estágio), gera os
 * valores por tipo (senhas aleatórias, próxima porta livre, padrões) e grava
 * /data/builds/<projeto>_<app>_<estágio>.json. Não cria volumes nem rede
 * (fase 2) e não revisa builds já configuradas.
 *
 * Variáveis do escopo: $_target ('proj/app@stage' ou ''), $_yes (sem perguntas).
 */

$ask = function ($prompt, $default = '') use ($_yes)
{
	if ($_yes) return $default;

	echo $prompt . ($default !== '' ? ' ['. $default .']' : '') .': ';

	$line = fgets (STDIN);

	if ($line === FALSE) return $default;

	$line = trim ($line);

	return $line === '' ? $default : $line;
};

// Sim/não: a letra maiúscula em (Y/n) ou (y/N) já indica o padrão, sem "[y]". Em terminal, como no
// assistente do bin/io, a linha é reescrita com a resposta efetiva (Yes em verde, No em amarelo).
$confirm = function ($prompt, $default = TRUE) use ($_yes)
{
	if ($_yes) return $default;

	$question = $prompt .' ('. ($default ? 'Y/n' : 'y/N') .')';

	while (TRUE)
	{
		echo $question .': ';

		$line = fgets (STDIN);

		$answer = $line === FALSE ? '' : strtolower (trim ($line));

		if ($answer === '') $result = $default;
		elseif (in_array ($answer, [ 'y', 'yes', 's', 'sim' ])) $result = TRUE;
		elseif (in_array ($answer, [ 'n', 'no', 'nao', 'não' ])) $result = FALSE;
		elseif ($line === FALSE) $result = $default;
		else continue;

		if (function_exists ('posix_isatty') && posix_isatty (STDIN) && posix_isatty (STDOUT) && getenv ('NO_COLOR') === FALSE)
			echo "\033[1A\033[2K". $question .': '. ($result ? "\033[1;32mYes\033[0m" : "\033[1;33mNo\033[0m") ."\n";

		return $result;
	}
};

$choose = function ($prompt, array $options) use ($ask, $_yes)
{
	if (!sizeof ($options)) throw new Exception ("Nothing to choose for: ". $prompt);

	if ($_yes) throw new Exception ("'--yes' requires the build as argument (proj/app@stage)");

	echo "\n". $prompt .": \n\n";

	$keys = array_keys ($options);

	foreach ($keys as $i => $key)
		echo "  ". str_pad ((string) ($i + 1), 3, ' ', STR_PAD_LEFT) .") ". $options [$key] ."\n";

	echo "\n";

	while (TRUE)
	{
		$n = (int) $ask ('Option', '');

		if ($n >= 1 && $n <= sizeof ($keys)) return $keys [$n - 1];

		echo "Invalid option. \n";
	}
};

$legacy = $_data . DIRECTORY_SEPARATOR .'builds.json';

$dir = $_data . DIRECTORY_SEPARATOR .'builds';

if (file_exists ($legacy))
{
	echo "INFO > This server still uses the legacy 'builds.json'. New builds are created as one file per build in 'builds/'. \n";

	if ($confirm ('Migrate builds.json to builds/*.json now (the original is kept as builds.json.bkp)?', TRUE))
	{
		require self::PATH .'split.php';

		echo "\n";
	}
}

// 1. Qual build?

$project = $app = $stage = NULL;

if (trim ((string) $_target) !== '')
{
	if (!preg_match ('#^([a-z0-9][a-z0-9-]+[a-z0-9])/([a-z0-9][a-z0-9-]+[a-z0-9])@(alpha|beta|release)$#', trim ($_target), $m))
		throw new Exception ("Invalid build '". $_target ."'. Expected: project/app@stage");

	list ($trash, $project, $app, $stage) = $m;
}
else
{
	$git = GitLab::singleton ();

	echo "INFO > Loading the projects your GitLab token has access to... \n";

	$groups = [];

	foreach ($git->userGroups () as $g)
		if (preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $g ['path']))
			$groups [$g ['id']] = $g ['path'] . ($g ['name'] !== $g ['path'] ? ' ('. $g ['name'] .')' : '');

	if (!sizeof ($groups)) throw new Exception ("No project found. Check that the GITLAB_TOKEN owner is at least Developer of a project in the platform.");

	$groupId = $choose ('Project', $groups);

	$project = explode (' ', $groups [$groupId]) [0];

	$apps = [];

	foreach ($git->groupProjects ($groupId) as $p)
		if (preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $p ['path']) && !in_array ($p ['path'], [ 'io-doc', 'io-api', 'io-web' ]))
			$apps [$p ['path']] = $p ['path'];

	if (!sizeof ($apps)) throw new Exception ("Project '". $project ."' has no app repository.");

	$app = $choose ('App of '. $project, $apps);

	$stage = $choose ('Stage', [ 'alpha' => 'alpha (internal tests)', 'beta' => 'beta (external tests)', 'release' => 'release (production)' ]);
}

$build = $project .'/'. $app .'@'. $stage;

$file = $dir . DIRECTORY_SEPARATOR . $project .'_'. $app .'_'. $stage .'.json';

echo "\n=== ". $build ." === \n\n";

if (file_exists ($file))
	throw new Exception ("Build '". $build ."' is already configured in '". $file ."'. This command does not review existing builds: edit the file or remove it first.");

if (file_exists ($legacy))
{
	$loaded = json_decode ((string) file_get_contents ($legacy));

	if (is_array ($loaded))
		foreach ($loaded as $b)
			if (is_object ($b) && isset ($b->project, $b->app, $b->stage) && $b->project === $project && $b->app === $app && $b->stage === $stage)
				throw new Exception ("Build '". $build ."' is already configured in 'builds.json'. Migrate it to 'builds/' first (io split).");
}

// 2. O que a plataforma sabe desta build

echo "INFO > Asking the core about '". $build ."'... \n";

$info = Report::build ($project, $app, $stage);

if (!empty ($info->cluster))
	throw new Exception ("The platform deploys '". $build ."' in cluster '". $info->cluster ."'. A stage is deployed by the platform OR by a Releaser, never both. Choose another stage or remove the cluster of this stage in the platform dashboard.");

foreach ((array) ($info->errors ?? []) as $what => $why)
	echo "WARNING > The core could not provide '". $what ."': ". $why .". You will have to fill it by hand in the build file. \n";

$team = [];

foreach ((array) ($info->team ?? []) as $member)
	if (is_object ($member) && isset ($member->email)) $team [] = $member->email;

$dsn = isset ($info->sentry->dsn) ? (string) $info->sentry->dsn : '';

$matomoId = isset ($info->matomo->id) ? (int) $info->matomo->id : 0;
$matomoToken = isset ($info->matomo->token) ? (string) $info->matomo->token : '';

$variables = isset ($info->settings->variables) && is_array ($info->settings->variables) ? $info->settings->variables : [];

$ref = isset ($info->settings->ref) ? (string) $info->settings->ref : '?';

echo "INFO > Team: ". (sizeof ($team) ? implode (', ', $team) : '(none)') ." \n";
echo "INFO > Sentry DSN: ". ($dsn !== '' ? 'ok' : 'MISSING') ." | Matomo site: ". ($matomoId ? $matomoId : 'MISSING') ." | Variables: ". sizeof ($variables) ." (from '". $ref ."') \n";

if (!empty ($info->deploy))
	echo "INFO > The core already knows a version for this stage (". $info->deploy ."): another Releaser reported it before. \n";

// 3. Portas e senhas

$randomize = $confirm ('Generate random values for PASSWORD and SECRET variables?', TRUE);

$usedPorts = [];

foreach (glob ($dir . DIRECTORY_SEPARATOR .'*.json') ?: [] as $other)
{
	$o = json_decode ((string) @file_get_contents ($other));

	if (!is_object ($o) || !isset ($o->env) || !is_object ($o->env)) continue;

	foreach ($o->env as $value)
		if (preg_match ('/^\d{4,5}$/', (string) $value) && (int) $value >= 1024 && (int) $value <= 65535) $usedPorts [(int) $value] = basename ($other);
}

// portas em uso no host: netstat num contêiner auxiliar da própria imagem do Releaser, na rede do host
try
{
	$return = 1;

	exec (DockerCompose::DOCKER .' run --rm --network host '. escapeshellarg (BackupVolume::image ()) .' sh -c "netstat -tln 2>/dev/null || ss -tln" 2>/dev/null', $listening, $return);
}
catch (Exception $e)
{
	echo "WARNING > Unable to check the ports in use on the host: ". $e->getMessage () .". \n";
}

if ($return === 0)
	foreach ($listening as $line)
		if (preg_match_all ('/:(\d{2,5})\s/', $line, $m))
			foreach ($m [1] as $port) $usedPorts [(int) $port] = 'in use on host';

$hasPort = FALSE;

foreach ($variables as $v) if (is_object ($v) && strtoupper ((string) ($v->type ?? '')) === 'PORT') $hasPort = TRUE;

$nextPort = $hasPort ? (int) $ask ('First port to try for PORT variables', '49152') : 0;

if ($hasPort && ($nextPort < 1024 || $nextPort > 65000)) $nextPort = 49152;

$random = function ()
{
	return substr (preg_replace ('/[^A-Za-z0-9]/', '', base64_encode (random_bytes (48))), 0, 32);
};

$env = [];

$volumes = [];

foreach ($variables as $v)
{
	if (!is_object ($v) || !isset ($v->name) || !preg_match ('/^[A-Z][A-Z0-9_]*$/', (string) $v->name)) continue;

	$name = (string) $v->name;
	$type = strtoupper ((string) ($v->type ?? 'TEXT'));
	$default = isset ($v->value) ? (string) $v->value : '';

	switch ($type)
	{
		case 'PORT':
			while (array_key_exists ($nextPort, $usedPorts)) $nextPort++;

			$value = $ask ($name .' (PORT, next free)', (string) $nextPort);

			$usedPorts [(int) $value] = $build;

			$nextPort = (int) $value + 1;

			break;

		case 'PASSWORD':
		case 'SECRET':
			$value = $randomize ? $random () : $ask ($name .' ('. $type .')', $default);

			break;

		case 'VOLUME':
			// A variável recebe o nome COMPLETO do volume externo ({projeto}_{app}_{estágio}_{sufixo}), como a plataforma
			// injeta nas builds dos clusters: é ele que o docker-compose.yaml das apps referencia. Pergunta-se só o sufixo;
			// um nome já completo (digitado ou no padrão do boilerplate) é mantido.
			$prefix = $project .'_'. $app .'_'. $stage .'_';

			$suffix = $ask ($name .' (VOLUME suffix; the external volume will be '. $prefix .'<value>)', $default);

			$value = strpos ($suffix, $prefix) === 0 ? $suffix : $prefix . $suffix;

			$volumes [] = $value;

			break;

		case 'EMPTY':
			$value = '';

			break;

		default:
			$value = $ask ($name .' ('. $type .')', $default);
	}

	if (preg_match ('/\s/', (string) $value))
		throw new Exception ("The value of '". $name ."' cannot contain spaces.");

	$env [$name] = (string) $value;
}

// 4. Grava

$definition = [
	'project' => $project,
	'app' => $app,
	'stage' => $stage,
	'team' => $team,
	'sentry' => [ 'dsn' => $dsn ],
	'matomo' => [ 'id' => $matomoId, 'token' => $matomoToken ],
	'auto' => [ 'deploy' => TRUE, 'backup' => TRUE, 'sanitize' => TRUE, 'cleaner' => FALSE, 'cloud' => FALSE ],
	'stats' => [ 'version' => TRUE, 'cve' => TRUE, 'health' => TRUE ],
	'env' => (object) $env
];

if (!file_exists ($dir) && !@mkdir ($dir, 0755, TRUE))
	throw new Exception ("Unable to create directory '". $dir ."'");

if (file_put_contents ($file, json_encode ($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ."\n", LOCK_EX) === FALSE)
	throw new Exception ("Unable to write '". $file ."'");

echo "\n";

echo "SUCCESS > Build '". $build ."' configured in '". $file ."': \n\n";

foreach ($env as $name => $value)
	echo "  ". $name .'='. (preg_match ('/PASS|SECRET|TOKEN|KEY|DSN/i', $name) && $value !== '' ? str_repeat ('*', 8) : $value) ."\n";

echo "\n";

// Volumes externos que ainda não existem. O de backup ({projeto}_{app}_{estágio}_backup, padrão dos boilerplates)
// vai ligado à pasta BACKUP_DIR do host (bind), compartilhada por todas as builds: fácil de listar, copiar e
// restaurar, e o cleaner/cloud só tocam nos arquivos com o prefixo de cada build.
$exists = function ($volume)
{
	exec ('/usr/bin/docker volume inspect '. escapeshellarg ($volume) .' > /dev/null 2>&1', $trash, $return);

	return $return === 0;
};

$backupDir = rtrim (trim ((string) getenv ('BACKUP_DIR')), '/');

if ($backupDir === '') $backupDir = '/root/backup';

$backupVolume = $project .'_'. $app .'_'. $stage .'_backup';

$missing = array_values (array_filter (array_unique ($volumes), function ($v) use ($backupVolume, $exists) { return $v !== $backupVolume && !$exists ($v); }));

$missingBackup = !$exists ($backupVolume);

if (sizeof ($missing) || $missingBackup)
{
	echo "WARNING > Before the first deploy, create the external volume(s) of this build on the host (this command does not create them): \n\n";

	foreach ($missing as $volume) echo "  docker volume create ". $volume ."\n";

	if ($missingBackup)
	{
		if (sizeof ($missing)) echo "\n";

		echo "  mkdir -p ". $backupDir ."\n";
		echo "  docker volume create --driver local --opt type=none --opt o=bind --opt device=". $backupDir ." ". $backupVolume ."\n";
	}

	echo "\n";

	if ($missingBackup)
		echo "INFO > The backup volume is bound to '". $backupDir ."' (BACKUP_DIR), shared by all builds of this server. If the app has no 'backup' service, it is not needed. \n\n";
}

echo "INFO > Review the file if needed, then deploy with: io deploy ". $build ." \n";
