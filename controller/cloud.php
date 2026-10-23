<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

$_dryRun = $_mode === '--dry-run';

$_list = $_mode === '--list';

echo "INFO > Checking the status of ". sizeof ($_builds) ." build(s) to copy to the CLOUD (S3 bucket)... \n";

if ($_dryRun) echo "INFO > Dry run: nothing will be uploaded to or deleted from the bucket. \n";

// 'auto.cloud': false/ausente (desligado), true ou "undated" — ver Retention::policy().
// Em modo daemon só rodam as builds com a cópia ligada; em execução manual, todas.
$_enabled = array_filter ($_builds, function ($b) { return Retention::policy (isset ($b->auto) && isset ($b->auto->cloud) ? $b->auto->cloud : FALSE) !== FALSE; });

if ($_daemon && !sizeof ($_enabled)) return;

// Bucket configurado uma única vez no /data/.env (ver class/Cloud.php). No daemon, a
// falta de configuração é só um aviso (sem e-mail): a cópia fica parada até alguém configurar.
if (!Cloud::configured ())
{
	if ($_daemon)
		echo "WARNING > Cloud copy is on ('auto.cloud') for ". sizeof ($_enabled) ." build(s), but the S3 bucket is not configured in the '.env' file (missing ". implode (', ', Cloud::missing ()) ."). Skipping. \n";
	else
		echo "ERROR > The S3 bucket is not configured! Set in the '.env' file: ". implode (', ', Cloud::missing ()) ." (and, optionally, S3_REGION and S3_RETENTION). See https://embrapa.io/docs/releaser/ \n";

	return;
}

// Quantos backups guardar no bucket: S3_RETENTION ('D/W/M/Y'); vazio herda RETENTION
// (padrão 7/4/3/1). Valor malformado aborta antes de tocar no bucket.
$_spec = Cloud::value ('S3_RETENTION') !== '' ? Cloud::value ('S3_RETENTION') : Cloud::value ('RETENTION');

try
{
	$_counts = Retention::parse ($_spec);

	Cloud::bucket ();
}
catch (Exception $e)
{
	echo "ERROR > ". $e->getMessage () .". Fix the '.env' file". (strpos ($e->getMessage (), 'retention') !== FALSE ? ' ('. (Cloud::value ('S3_RETENTION') !== '' ? 'S3_RETENTION' : 'RETENTION') .')' : '') .". Nothing was uploaded or deleted! \n";

	if ($_daemon)
		Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - INVALID CLOUD SETTINGS', mailBody ());

	return;
}

$_summary = [];

$orchestrator = getenv ('ORCHESTRATOR');

foreach ($_builds as $_build => $_b)
{
	$auto = isset ($_b->auto) && isset ($_b->auto->cloud) ? $_b->auto->cloud : FALSE;

	$policy = Retention::policy ($auto, $_counts);

	if ($_daemon && $policy === FALSE) continue;

	if ($policy === FALSE) $policy = Retention::policy (TRUE, $_counts);

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		continue;
	}

	$namespace = implode ('_', [$_b->project, $_b->app, $_b->stage]);

	$dir = implode ('/', [$_b->project, $_b->app, $_b->stage]);

	if ($_list)
	{
		try
		{
			$orchestrator::cloudList ($namespace, $dir, $policy);
		}
		catch (Exception $e)
		{
			echo "ERROR > Unable to list the bucket for this build. ". $e->getMessage () ."! \n\n";
		}

		continue;
	}

	echo "INFO > Checking if build '". $_build ."' has been deployed... \n";

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

	echo "INFO > Copying backups to the bucket (rotation there: keep last ". Retention::describe ($policy) .")... \n";

	try
	{
		$result = $orchestrator::cloud ($clone, $namespace, $dir, $policy, $_dryRun);
	}
	catch (SkipException $e)
	{
		echo "WARNING > Nothing to do. ". $e->getMessage () .". Skipping this build. \n\n";

		continue;
	}
	catch (Exception $e)
	{
		$error = Cloud::mask ($e->getMessage ());

		echo "ERROR > Unable to copy the backups of build to the bucket. ". $error ."! \n\n";

		if (!$_dryRun) Cloud::remember ($_b->project, $_b->app, $_b->stage, NULL, $error);

		continue;
	}

	if (!$_dryRun) Cloud::remember ($_b->project, $_b->app, $_b->stage, $result ['stats']);

	$_summary [$_build] = $result ['stats'];

	echo "SUCCESS > All done! Build '". $_build ."': ". $result ['stats']['uploaded'] ." file(s) ". ($_dryRun ? 'would be uploaded' : 'uploaded') .", ". $result ['stats']['kept'] ." kept and ". $result ['stats']['deleted'] ." ". ($_dryRun ? 'would be deleted' : 'deleted') ." in the bucket. \n";
}

if (sizeof ($_summary))
{
	echo "\n";

	echo "INFO > Summary". ($_dryRun ? ' (dry run)' : '') .": \n";

	foreach ($_summary as $build => $st)
	{
		echo "\n";
		echo $build ."\n";
		echo ($_dryRun ? "would upload " : "uploaded ") . $st ['uploaded'] ." file(s), ". $orchestrator::humanSize ($st ['bytes_uploaded']) . ($st ['skipped'] ? " (". $st ['skipped'] ." old file(s) outside the retention not uploaded)" : '') ."\n";
		echo ($_dryRun ? "would keep " : "kept ") . $st ['kept'] .", ". ($_dryRun ? "would delete " : "deleted ") . $st ['deleted'] ." file(s), ". $orchestrator::humanSize ($st ['bytes_deleted']) ."\n";
		echo "bucket: ". $st ['files_before'] ." → ". $st ['files_after'] ." file(s), ". $orchestrator::humanSize ($st ['bytes_before']) ." → ". $orchestrator::humanSize ($st ['bytes_after']) ."\n";
	}

	echo "\n";
}
