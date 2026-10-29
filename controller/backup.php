<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking the status of ". sizeof ($_builds) ." build(s) to BACK UP... \n";

foreach ($_builds as $_build => $_b)
{
	if ($_daemon && !$_b->auto->backup) continue;

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	if (self::restoring ($_b))
	{
		echo "WARNING > A restore is running on this build ('io restore'). Skipping. \n\n";

		continue;
	}

	echo "INFO > Checking if build '". $_build ."' has been deployed... \n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		continue;
	}

	$version = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

	try
	{
		$_last = trim (file_get_contents ($version));
	}
	catch (Exception $e)
	{
		$_last = NULL;
	}

	if (!is_string ($_last) || $_last == '' || !self::score ($_b->stage, $_last))
	{
		echo "ERROR > No valid version has been deployed to this build! \n\n";

		continue;
	}

	$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

	if (!file_exists ($clone) || !is_dir ($clone))
	{
		echo "ERROR > The clone of version/tag '". $_last ."' is missing! \n\n";

		continue;
	}

	echo "INFO > Backing up version '". $_last ."'... \n";

	try
	{
		(self::singleton ()->orchestrator)::backup ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
	}
	catch (SkipException $e)
	{
		echo "WARNING > Nothing to do. ". $e->getMessage () .". Skipping this build. \n\n";

		continue;
	}
	catch (Exception $e)
	{
		echo "ERROR > Unable to back up build. ". $e->getMessage () ."! \n\n";

		continue;
	}

	echo "SUCCESS > All done! Version '". $_last ."' of build '". $_build ."' was successfully backed up! \n";

	// Dica da cópia em nuvem (só em execução manual: no daemon o 'cloud' roda logo depois do 'backup').
	// Sem S3 configurado no .env, nada; com 'auto.cloud' desligado, avisa que o daemon não copiará.
	if (!$_daemon && Cloud::configured ())
	{
		$autoCloud = isset ($_b->auto) && isset ($_b->auto->cloud) ? $_b->auto->cloud : FALSE;

		echo "INFO > To copy this backup to the S3 bucket now, run: io cloud ". $_build . (Retention::policy ($autoCloud) === FALSE ? ". The daily daemon will not copy it unless the build file has \"auto\": { \"cloud\": true }" : '') ." \n";
	}
}
