<?php
/**
 * embrapa/releaser
 * Script for automatic deploy of Embrapa I/O apps releases in external remote environments.
 *
 * Copyright 2023: Brazilian Agricultural Research Corporation - Embrapa
 *
 * @author Camilo Carromeu <camilo.carromeu@embrapa.br>
 *
 * Bootstrap script.
 */

error_reporting (E_ALL);
umask (0022); // hosts com umask 0000 criavam .env das apps e fila de e-mails com modo 666
set_time_limit (0);
ini_set ('memory_limit', '-1');
ini_set ('register_argc_argv', '1');

if (PHP_SAPI != 'cli')
	die ("CRITICAL > This is a command-line script! It cannot be called from a browser. \n");

if (!`which git`)
	die ("CRITICAL > Git package not found! \n");

if (!(int) ini_get ('register_argc_argv'))
	die ("CRITICAL > This is a command-line script! You must enable 'register_argc_argv' directive. \n");

$vars = [
	'SERVER',
	'ORCHESTRATOR',
	'GITLAB_TOKEN',
	'SMTP_HOST',
	'SMTP_PORT',
	'SMTP_FROM',
	'LOG_MAIL'
];

$unsetted = [];

foreach ($vars as $trash => $var)
	if (getenv ($var) === FALSE)
		$unsetted [] = $var;

if (sizeof ($unsetted))
	die ("CRITICAL > Required environment variables are not set: ". implode (', ', $unsetted) ."! \n");

require_once 'class/Mail.php';

if (!Mail::isValid (getenv ('SMTP_FROM')))
	echo "WARNING > The e-mail address in 'SMTP_FROM' (in the '.env' file) appears to be invalid! \n";

if (!Mail::isValid (getenv ('LOG_MAIL')))
	echo "WARNING > The e-mail address in 'LOG_MAIL' (in the '.env' file) appears to be invalid! \n";

require_once 'plugin/Orchestrator.php';

if (!Orchestrator::exists (getenv ('ORCHESTRATOR')))
	die ("CRITICAL > Orchestrator '". getenv ('ORCHESTRATOR') ."' set in '.env' is not valid! \n");

require_once 'class/Operation.php';
require_once 'class/Menu.php';

// Operation (descrição curta do menu — até ~50 caracteres —, método, nº de parâmetros, uso, exemplos, grupo do
// menu, descrição completa do --help). Ver class/Menu.php.
$_operations = [
	'validate' => new Operation (
		'Validate builds (dry run)',
		'Controller::validate',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
				[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		],
		'deploy',
		'Validate builds without deploying (dry run): clones the latest tag of each build and checks the docker-compose.yaml (external volumes, published ports, CLI services, variables).'
	),
	'deploy' => new Operation (
		'Validate and deploy the latest tag of builds',
		'Controller::deploy',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all] [--force]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all',
			'proj-b/web@release --force'
		],
		'deploy',
		'Re-validate, prepare (i.e., create the network) and deploy the latest tag of builds. With --force, deploys again even if the latest tag is already deployed.'
	),
	'stop' => new Operation (
		'Stop builds (--down removes the containers)',
		'Controller::stop',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all] [--down]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all',
			'proj-b/web@release --down'
		],
		'deploy',
		'Stop running builds. With --down, removes the containers of the stack (docker compose down); volumes are always kept.'
	),
	'restart' => new Operation (
		'Restart builds (--upgrade renews the images)',
		'Controller::restart',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all] [--upgrade | --update]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all',
			'proj-b/web@release --upgrade'
		],
		'deploy',
		'Start stopped or restart running builds, keeping the deployed version. With --upgrade (synonym: --update), renews the images as the platform deployer does: pulls the images of the services (floating tags, such as \'latest\' or \'8.3-apache\', get the latest content), rebuilds the services with \'build:\' over a renewed base image (build --pull) and recreates the containers; then scans the upgraded builds for CVEs and reports them to the platform (as \'io cve\'), unless \'stats.cve\' is false. Use it to apply security fixes published in the base images without a new tag.'
	),
	'rollback' => new Operation (
		'Roll a build back to a previous version',
		'Controller::rollback',
		2,
		'[BUILD] [VERSION]',
		'my-project/backend@beta 3.'. date ('y.n') .'-beta.17',
		'deploy',
		'Roll back a build to a previous version (tag) of the same stage.'
	),
	'backup' => new Operation (
		'Run the backup service of builds',
		'Controller::backup',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
				[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		],
		'maintenance',
		'Back up builds by running the \'backup\' CLI service of each stack. Builds without it (e.g., frontends) are skipped.'
	),
	'sanitize' => new Operation (
		'Run the sanitize service of builds',
		'Controller::sanitize',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
				[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		],
		'maintenance',
		'Run the sanitize process (the \'sanitize\' CLI service of each stack). Builds without it are skipped.'
	),
	'cleaner' => new Operation (
		'Rotate backups (7 daily, 4 weekly, 3 monthly)',
		'Controller::cleaner',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all] [--dry-run]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all',
			'proj-b/web@release --dry-run',
			'--dry-run'
		],
		'maintenance',
		'Rotate the backup files of builds: keeps the last 7 daily, 4 weekly and 3 monthly. Only files named with the build prefix and a date are considered. With --dry-run, only shows what would be deleted.'
	),
	'health' => new Operation (
		'Report service health to the platform',
		'Controller::health',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
				[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		],
		'platform',
		'Report the health of the services of builds to the platform (core).'
	),
	'cve' => new Operation (
		'Scan images for CVEs and report to the platform',
		'Controller::cve',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
				[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		],
		'platform',
		'Scan the running images of builds for CVEs (HIGH and CRITICAL, with Trivy) and report them to the platform (core).'
	),
	'check' => new Operation (
		'Test access to GitLab (API, token and clone)',
		'Controller::check',
		0,
		'',
		[],
		'platform',
		'Test access to GitLab: reachability and latency of the API, expiration and scope of the token, and clone access (SSH key).'
	),
	'install' => new Operation (
		'Guided setup of a new build',
		'Controller::install',
		0,
		'[BUILD] [--yes]',
		[
			'',
			'proj-a/app-1@beta',
			'proj-a/app-1@beta --yes'
		],
		'setup',
		'Guided setup of a new build: creates builds/<project>_<app>_<stage>.json with the team, Sentry, Matomo and variables fetched from the platform.'
	),
	'split' => new Operation (
		'Migrate builds.json to one file per build',
		'Controller::split',
		0,
		'',
		[],
		'setup',
		'Migrate the legacy builds.json to one file per build in builds/ (the original is kept as builds.json.bkp).'
	),
	'info' => new Operation (
		'Directories of builds and useful commands',
		'Controller::info',
		0,
		'',
		[],
		'setup',
		'Show the directory of each deployed build, the latest reports to the platform and how to run other useful commands.'
	),
	'mail' => new Operation (
		'Send a test e-mail (SMTP settings)',
		'Controller::mail',
		1,
		'[MAIL-1,MAIL-2,...,MAIL-N]',
		[
			'jose.silva@embrapa.br,maria.santos@embrapa.br'
		],
		'setup',
		'Test the e-mail settings by sending a message to the given addresses.'
	)
];

$container = getenv ('ORCHESTRATOR') == 'DockerSwarm' ? '$(docker ps -q -f name=releaser)' : 'releaser';

try
{
	if ($argc < 2) throw new Exception ();

	$aux = explode (':', $argv [1]);

	if (!array_key_exists (trim ($aux [0]), $_operations)) throw new Exception ();

	$_operation = trim ($aux [0]);

	$_daemon = sizeof ($aux) == 2 && trim ($aux [1]) == 'daemon' && in_array ($aux [0], [ 'deploy', 'backup', 'sanitize', 'cleaner', 'health', 'cve', 'check' ]) ? TRUE : FALSE;
}
catch (Exception $e)
{
	// Em modo daemon a saída vira e-mail: nada de logo, cor ou escape (Menu::plain).
	$daemonCall = isset ($argv [1]) && strpos ($argv [1], ':daemon') !== FALSE;

	echo Menu::render ($_operations, $container, $daemonCall);

	// Teste rápido do acesso ao GitLab no fim do menu. Só chega aqui quem chamou o
	// `io` sem comando (ou com um inexistente); os jobs do daemon sempre passam um.
	try
	{
		require_once 'vendor/autoload.php';
		require_once 'class/Controller.php';
		require_once 'class/GitCheck.php';

		echo "\n". Menu::paint ('Checking access to GitLab...', 'dim', Menu::plain ($daemonCall)) ."\n";

		echo GitCheck::summary (GitCheck::run ()) ."\n";
	}
	catch (\Throwable $e)
	{
		echo "\nGitLab check: impossible to run (". $e->getMessage () .") \n";
	}

	exit;
}

if ($argc < 2 + $_operations [$_operation]->params || ($argc > 2 && trim ($argv [2]) == '--help'))
{
	echo Menu::help ($_operation, $_operations [$_operation], $container, $_daemon);

	exit;
}

$_path = dirname (__FILE__);

$_data = DIRECTORY_SEPARATOR .'data';

if (!file_exists ($_data) || !is_dir ($_data))
	die ("CRITICAL > The data storage volume is not mounted! \n");

$lockLifetime = intval (getenv ('LOCK_LIFETIME_MINUTES'));

$lifetimes = [
	'deploy' => $lockLifetime ? $lockLifetime : 4 * 60, // 4 hours (default)
	'backup' => 7 * 24 * 60, // 1 week
	'sanitize' => 15 * 24 * 60, // 15 days
	'cleaner' => 12 * 60, // 12 hours
	'health' => 10, // 10 minutes (roda a cada minuto)
	'cve' => 3 * 60, // 3 hours (roda a cada hora)
	'check' => 60 // 1 hour (roda uma vez por dia)
];

// O health check roda a cada minuto: no daemon, só WARNING/ERROR/CRITICAL
// viram log no Sentry (helper/sentry.php), senão seriam milhares de INFO por dia.
$_sentryQuiet = $_daemon && $_operation === 'health';

$_lock = $_data . DIRECTORY_SEPARATOR .'.lock'. DIRECTORY_SEPARATOR . $_operation;

@mkdir (dirname ($_lock));

if ($_daemon)
{
	if (file_exists ($_lock) && (!is_writable ($_lock) || time () - filemtime ($_lock) < $lifetimes [$_operation] * 60))
		die ("CRITICAL > The operation '". $_operation ."' is already being performed by a process started earlier! \n");
	else
		@unlink ($_lock);
}

require_once 'vendor/autoload.php';

require_once 'helper/error.php';
require_once 'helper/sentry.php';
require_once 'helper/output.php';

require_once 'class/GitLab.php';
require_once 'class/GitClient.php';
require_once 'class/Controller.php';
require_once 'class/Retention.php';
require_once 'class/Report.php';
require_once 'class/Trivy.php';
require_once 'class/GitCheck.php';

require_once 'plugin/DockerCompose.php';
require_once 'plugin/DockerSwarm.php';

sentryInit ($_operation, $_daemon);

set_error_handler ('handleError');

try
{
	ob_start ('sentryOutput', 1); // logs/eventos no Sentry a partir da saída (passthrough)

	if ($_daemon) ob_start ();

	$_benchmark = time ();

	if ($_daemon) file_put_contents ($_lock, date (DATE_RFC822));

	echo "INFO > Starting execution... \n";

	$_nothing = TRUE;

	$function = $_operations [$_operation]->method;

	call_user_func_array ($function, array_slice ($argv, 2));

	try { @unlink ($_lock); } catch (Exception $e) {}

	echo "\n";

	echo "FINISH > All done after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds! \n";

	if ($_daemon && !$_nothing) Mail::singleton ()->send ('SUCCESSFUL EXECUTION of Releaser', mailBody ());

	if ($_daemon) Mail::singleton ()->flush (); // fila de e-mails que não saíram em execuções anteriores

	exit (0);
}
catch (Exception $e)
{
	\Sentry\captureException ($e);

	echo "\n";

	echo "CRITICAL > ". $e->getMessage () ." \n\n";
}

try
{
	echo "FINISH > Stopped after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds! \n";

	if ($_daemon) Mail::singleton ()->send ('CRITICAL ERROR in Releaser', mailBody ());

	if ($_daemon) Mail::singleton ()->flush ();
}
catch (Exception $e)
{
	\Sentry\captureException ($e);
}
