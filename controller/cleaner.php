<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

$_dryRun = isset ($_flag) && trim ((string) $_flag) === '--dry-run';

echo "INFO > Checking status of ". sizeof ($_builds) ." build(s) to CLEAN (rotate backups)... \n";

if ($_dryRun) echo "INFO > Dry run: nothing will be deleted. \n";

$_summary = [];

$orchestrator = getenv ('ORCHESTRATOR');

foreach ($_builds as $_build => $_b)
{
	// 'auto.cleaner': false/ausente (desligado), true ou "undated" — ver Retention::policy().
	// Em modo daemon só roda quando ligado. Em execução manual roda sempre, com a
	// política do builds.json (se houver) ou a padrão.
	$auto = isset ($_b->auto) && isset ($_b->auto->cleaner) ? $_b->auto->cleaner : FALSE;

	$policy = Retention::policy ($auto);

	if ($_daemon && $policy === FALSE) continue;

	if ($policy === FALSE) $policy = Retention::DEFAULT_POLICY;

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	echo "INFO > Checking if build '". $_build ."' has has been deployed... \n";

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
		echo "ERROR > No one valid version was deployed to this build! \n\n";

		continue;
	}

	$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

	if (!file_exists ($clone) || !is_dir ($clone))
	{
		echo "ERROR > The clone to version/tag '". $_last ."' is missing! \n\n";

		continue;
	}

	echo "INFO > Rotating backups (keep last ". $policy ['daily'] ." daily, ". $policy ['weekly'] ." weekly and ". $policy ['monthly'] ." monthly)... \n";

	try
	{
		$result = $orchestrator::cleaner ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]), $policy, $_dryRun);
	}
	catch (SkipException $e)
	{
		echo "WARNING > Nothing to do. ". $e->getMessage () .". Skipping this build. \n\n";

		continue;
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to rotate backups of build. ". $e->getMessage () ."! \n\n";

		continue;
	}

	$_summary [$_build] = $result ['stats'];

	echo "SUCCESS > All done! Build '". $_build ."': ". sizeof ($result ['keep']) ." backup(s) kept, ". sizeof ($result ['delete']) ." ". ($_dryRun ? 'would be deleted' : 'deleted') .". \n";
}

if (sizeof ($_summary))
{
	echo "\n";

	echo "INFO > Summary". ($_dryRun ? ' (dry run)' : '') .": \n";

	foreach ($_summary as $build => $st)
	{
		echo "\n";
		echo $build ."\n";
		echo $st ['files_before'] ." → ". $st ['files_after'] ." file(s)\n";
		echo $orchestrator::humanSize ($st ['bytes_before']) ." → ". $orchestrator::humanSize ($st ['bytes_after']) ."\n";
		echo "freed ". $orchestrator::humanSize ($st ['bytes_deleted']) ."\n";
	}

	echo "\n";
}
