<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking the status of ". sizeof ($_builds) ." build(s) to STOP... \n";

foreach ($_builds as $_build => $_b)
{
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

	echo "INFO > Stopping version '". $_last ."'... \n";

	try
	{
		(self::singleton ()->orchestrator)::stop ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]), $_down);
	}
	catch (Exception $e)
	{
		echo "ERROR > Unable to stop version/tag '". $_last ."'. ". $e->getMessage () ."! \n\n";

		continue;
	}

	echo "SUCCESS > All done! Version '". $_last ."' of build '". $_build ."' is UNDEPLOYED and OFFLINE! \n";

	echo "INFO > To bring the application back online, use the 'restart' command or push a new tag to Git. \n";
}
