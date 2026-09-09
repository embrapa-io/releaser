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
set_time_limit (0);
ini_set ('memory_limit', '-1');
ini_set ('register_argc_argv', '1');

if (PHP_SAPI != 'cli')
	die ("CRITICAL > This is a command-line script! You cannot call by browser. \n");

if (!`which git`)
	die ("CRITICAL > GIT package not found!");

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
	die ("CRITICAL > Required environment variables are not setted: ". implode (', ', $unsetted) ."! \n");

require_once 'class/Mail.php';

if (!Mail::isValid (getenv ('SMTP_FROM')))
	echo "WARNING > The email address entered in 'SMTP_FROM' (at '.env' file) appears to be invalid! \n";

if (!Mail::isValid (getenv ('LOG_MAIL')))
	echo "WARNING > The email address entered in 'LOG_MAIL' (at '.env' file) appears to be invalid! \n";

require_once 'plugin/Orchestrator.php';

if (!Orchestrator::exists (getenv ('ORCHESTRATOR')))
	die ("CRITICAL > Orchestrator '". getenv ('ORCHESTRATOR') ."' defined in '.env' is not valid! \n");

require_once 'class/Operation.php';

$_operations = [
	'validate' => new Operation (
		'Validate builds',
		'Controller::validate',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		]
	),
	'deploy' => new Operation (
		'Re-validate, prepare (i.e., create network) and deploy builds',
		'Controller::deploy',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all] [--force]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all',
			'proj-b/web@release --force'
		]
	),
	'stop' => new Operation (
		'Stop running builds',
		'Controller::stop',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		]
	),
	'restart' => new Operation (
		'Start stopped or restart running builds',
		'Controller::restart',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		]
	),
	'rollback' => new Operation (
		'Rollback build to previous version',
		'Controller::rollback',
		2,
		'[BUILD] [VERSION]',
		'my-project/backend@beta 3.'. date ('y.n') .'-beta.17'
	),
	'backup' => new Operation (
		'Generate backup of builds',
		'Controller::backup',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		]
	),
	'sanitize' => new Operation (
		'Run sanitize proccess',
		'Controller::sanitize',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all'
		]
	),
	'cleaner' => new Operation (
		'Rotate backups of builds (keep last 7 daily, 4 weekly and 3 monthly)',
		'Controller::cleaner',
		1,
		'[BUILD-1,BUILD-2,...,BUILD-N | --all] [--dry-run]',
		[
			'proj-a/app-1@beta,proj-b/web@release,proj-a/app-2@alpha',
			'--all',
			'proj-b/web@release --dry-run',
			'--dry-run'
		]
	),
	'info' => new Operation (
		'How to execute other util commands',
		'Controller::info',
		0,
		'',
		[]
	),
	'mail' => new Operation (
		'Test e-mail settings',
		'Controller::mail',
		1,
		'[MAIL-1,MAIL-2,...,MAIL-N]',
		[
			'jose.silva@embrapa.br,maria.santos@embrapa.br'
		]
	)
];

$container = getenv ('ORCHESTRATOR') == 'DockerSwarm' ? '$(docker ps -q -f name=releaser)' : 'releaser';

try
{
	if ($argc < 2) throw new Exception ();

	$aux = explode (':', $argv [1]);

	if (!array_key_exists (trim ($aux [0]), $_operations)) throw new Exception ();

	$_operation = trim ($aux [0]);

	$_daemon = sizeof ($aux) == 2 && trim ($aux [1]) == 'daemon' && in_array ($aux [0], [ 'deploy', 'backup', 'sanitize', 'cleaner' ]) ? TRUE : FALSE;
}
catch (Exception $e)
{
	echo "Usage: docker exec -it ". $container ." io COMMAND \n\n";

	echo "Commands: \n";

	foreach ($_operations as $op => $obj)
		echo "  ". str_pad ($op, 12) . $obj->info ."\n";

	echo "\n";

	echo "See 'docker exec -it ". $container ." io COMMAND --help' for more information on a command.";

	exit;
}

if ($argc < 2 + $_operations [$_operation]->params || ($argc > 2 && trim ($argv [2]) == '--help'))
{
	echo "Usage: docker exec -it ". $container ." io ". $_operation ." ". $_operations [$_operation]->usage;

	if (sizeof ($_operations [$_operation]->examples))
	{
		echo "\n\n";

		echo "Examples:";

		foreach ($_operations [$_operation]->examples as $ex)
			echo "\ndocker exec -it ". $container ." io ". $_operation ." ". $ex;
	}

	exit;
}

$_path = dirname (__FILE__);

$_data = DIRECTORY_SEPARATOR .'data';

if (!file_exists ($_data) || !is_dir ($_data))
	die ("CRITICAL > Volume for data storage is not mounted! \n");

$lockLifetime = intval (getenv ('LOCK_LIFETIME_MINUTES'));

$lifetimes = [
	'deploy' => $lockLifetime ? $lockLifetime : 4 * 60, // 4 hours (default)
	'backup' => 7 * 24 * 60, // 1 week
	'sanitize' => 15 * 24 * 60, // 15 days
	'cleaner' => 12 * 60 // 12 hours
];

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

	echo "FINISH > All done after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	if ($_daemon && !$_nothing) Mail::singleton ()->send ('SUCCESS EXECUTION of Releaser', mailBody ());

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
	echo "FINISH > Stopped after ". number_format (time () - $_benchmark, 0, ',', '.') ." seconds!";

	if ($_daemon) Mail::singleton ()->send ('CRITICAL ERROR of Releaser', mailBody ());
}
catch (Exception $e)
{
	\Sentry\captureException ($e);
}
