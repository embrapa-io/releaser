<?php

$git = GitLab::singleton ();

echo "INFO > Checking status of all ". sizeof ($_builds) ." builds configured... \n";

foreach ($_builds as $_build => $_b)
{
	echo "\n";

	echo "=== ". $_build ." === \n\n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		continue;
	}

	if (!intval ($_b->matomo->id)) echo "WARNING > Configure a valid Matomo ID! \n";

	if (!preg_match ('/^https:\/\/[a-f0-9]{32}@bug\.embrapa\.io\/[0-9]+$/', $_b->sentry->dsn))
		echo "WARNING > Configure a valid Sentry DSN! \n";

	echo "INFO > Checking if build '". $_build ."' has new tags... \n";

	try
	{
		$project = $git->projectSearch ($_b->project);
	}
	catch (Exception $e)
	{
		$project = [];
	}

	if (!sizeof ($project))
	{
		echo "ERROR > Project '". $_b->project ."' not found! \n\n";

		continue;
	}

	$projectId = $project ['id'];

	try
	{
		$load = $git->reposSearch ($_b->project .'/'. $_b->app);
	}
	catch (Exception $e)
	{
		$load = [];
	}

	if (!sizeof ($load))
	{
		echo "ERROR > Repository '". $_b->project .'/'. $_b->app ."' not found! \n\n";

		continue;
	}

	$repos = $load [0];

	$replace = [
		'%SERVER%' => getenv ('SERVER'),
		'%STAGE%' => $_b->stage,
		'%PROJECT_UNIX%'=> $_b->project,
		'%APP_UNIX%' => $_b->app,
		'%VERSION%' => '2.'. date ('y') .'.'. date ('n') .'-'. ($_b->stage !== 'release' ? $_b->stage .'.' : '') .'7',
		'%DEPLOYER%' => 'user.name@embrapa.br',
		'%SENTRY_DSN%' => $_b->sentry->dsn,
		'%MATOMO_ID%' => $_b->matomo->id,
		'%MATOMO_TOKEN%' => md5 ($_build)
	];

	$ci = '';
	$bk = '';

	foreach (self::singleton ()->environment as $name => $value)
	{
		$line = $name .'='. str_replace (array_keys ($replace), array_values ($replace), $value) ."\n";

		$bk .= $name != 'COMPOSE_PROFILES' ? $line : "COMPOSE_PROFILES=cli\n";
		$ci .= $line;
	}

	echo "INFO > CI/DI environment variables: \n\n". $ci ."\n";

	$env = '';

	foreach ($_b->env as $variable => $value)
		$env .= $variable .'='. $value ."\n";

	if (strpos ($env, ' ') !== false)
	{
		echo "ERROR > Environment variables can not contain spaces! Check attribute 'env' at file 'builds.json'. \n\n";

		continue;
	}

	echo "INFO > Trying to clone app... ";

	unset ($clone);

	try
	{
		$clone = GitClient::singleton ()->cloneBranch ($_b->project, $_b->app, $_b->stage, $ci, $bk, $env);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossible to prepare the app from the repository. ". $e->getMessage () ."! \n\n";

		continue;
	}

	try
	{
		$isValid = (self::singleton ()->orchestrator)::validate ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to validate repository. ". $e->getMessage () ."! \n\n";

		continue;
	}

	try
	{
		GitClient::singleton ()->delete ($clone);
	}
	catch (Exception $e)
	{}

	if ($isValid)
		echo "SUCCESS > All done and build '". $_b->stage ."' to application '". $_b->project ."/". $_b->app ."' is VALID! \n";
	else
		echo "WARNING > All done, but build '". $_b->stage ."' to application '". $_b->project ."/". $_b->app ."' is INVALID! \n";
}
