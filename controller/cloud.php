<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

$_dryRun = $_mode === '--dry-run';

$_list = $_mode === '--list';

$_verify = $_mode === '--verify';

echo "INFO > Checking the status of ". sizeof ($_builds) ." build(s) to ". ($_verify ? 'VERIFY in' : 'copy to') ." the CLOUD (S3 bucket)... \n";

if ($_dryRun) echo "INFO > Dry run: nothing will be uploaded to or deleted from the bucket. \n";

if ($_verify) echo "INFO > Verify: the files in both the volume and the bucket are compared by hash; nothing is uploaded or deleted. \n";

// 'auto.cloud': false/ausente (desligado), true ou "undated" — ver Retention::policy().
// Em modo daemon só rodam as builds com a cópia ligada; em execução manual, todas.
$_enabled = array_filter ($_builds, function ($b) { return Retention::policy (isset ($b->auto) && isset ($b->auto->cloud) ? $b->auto->cloud : FALSE) !== FALSE; });

if ($_daemon && !sizeof ($_enabled)) return;

// Bucket de cada build: a configuração própria do projeto (/data/s3/<projeto>.json, gravada pelo
// `io cloud --setup <projeto>`), se houver; senão a do servidor (S3_* no /data/.env). Ver class/Cloud.php.
// Cada configuração em uso é validada uma vez, antes de tocar em qualquer bucket: as builds de uma
// configuração com problema ficam de fora e as demais seguem.
$_settings = [];

foreach ($_daemon ? $_enabled : $_builds as $_b)
{
	Cloud::select ($_b->project ?? NULL);

	$key = (string) Cloud::scope ();

	if (!isset ($_settings [$key])) $_settings [$key] = [ 'project' => Cloud::scope (), 'counts' => NULL, 'builds' => 0 ];

	$_settings [$key]['builds']++;
}

$_invalid = FALSE;

foreach ($_settings as $key => $setting)
{
	Cloud::select ($setting ['project']);

	// No daemon, a falta de configuração é só um aviso (sem e-mail): a cópia fica parada até alguém configurar.
	if (!Cloud::configured ())
	{
		if ($_daemon)
			echo "WARNING > Cloud copy is on ('auto.cloud') for ". $setting ['builds'] ." build(s), but the S3 bucket is not configured in ". Cloud::source () ." (missing ". implode (', ', Cloud::missing ()) ."). Skipping. \n";
		else
			echo "ERROR > The S3 bucket is not configured! Set in ". Cloud::source () .": ". implode (', ', Cloud::missing ()) ." (and, optionally, ". Cloud::label ('S3_REGION') ." and ". Cloud::label ('S3_RETENTION') ."), or run 'io cloud --setup". ($setting ['project'] !== NULL ? ' '. $setting ['project'] : '') ."'. See https://embrapa.io/docs/releaser/ \n";

		continue;
	}

	// Quantos backups guardar no bucket ('D/W/M/Y'): a retenção do projeto, a S3_RETENTION ou, na falta
	// delas, a RETENTION (padrão 7/4/3/1). Valor malformado, ou nome de bucket inválido, tira as builds dessa
	// configuração da rodada antes de tocar no bucket.
	$retention = Cloud::retention ();

	try
	{
		$counts = Retention::parse ($retention ['spec']);

		Cloud::bucket ();

		$_settings [$key]['counts'] = $counts;
	}
	catch (Exception $e)
	{
		echo "ERROR > ". $e->getMessage () .". Fix ". (strpos ($e->getMessage (), 'retention') !== FALSE ? $retention ['where'] : Cloud::source ()) .". Nothing was uploaded or deleted". (sizeof ($_settings) > 1 ? ' for the '. $setting ['builds'] .' build(s) that use these settings' : '') ."! \n";

		$_invalid = TRUE;
	}
}

Cloud::select (NULL);

if ($_daemon && $_invalid)
	Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - INVALID CLOUD SETTINGS', mailBody ());

if (!sizeof (array_filter ($_settings, function ($setting) { return $setting ['counts'] !== NULL; }))) return;

$_summary = [];

$orchestrator = getenv ('ORCHESTRATOR');

foreach ($_builds as $_build => $_b)
{
	$auto = isset ($_b->auto) && isset ($_b->auto->cloud) ? $_b->auto->cloud : FALSE;

	if ($_daemon && Retention::policy ($auto) === FALSE) continue;

	// daqui em diante, tudo o que a Cloud lê e envia é com a configuração desta build
	Cloud::select ($_b->project ?? NULL);

	$_counts = $_settings [(string) Cloud::scope ()]['counts'] ?? NULL;

	// configuração ausente ou inválida, já acusada acima
	if ($_counts === NULL) continue;

	$policy = Retention::policy ($auto, $_counts);

	if ($policy === FALSE) $policy = Retention::policy (TRUE, $_counts);

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	if (self::restoring ($_b))
	{
		echo "WARNING > A restore is running on this build ('io restore'). Skipping. \n\n";

		continue;
	}

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

	if ($_verify)
	{
		try
		{
			$result = $orchestrator::cloudVerify ($clone, $namespace, $dir);
		}
		catch (SkipException $e)
		{
			echo "WARNING > Nothing to do. ". $e->getMessage () .". Skipping this build. \n\n";

			continue;
		}
		catch (Exception $e)
		{
			echo "ERROR > Verification of build failed. ". Cloud::mask ($e->getMessage ()) ."! \n\n";

			continue;
		}

		echo "SUCCESS > All done! Build '". $_build ."': ". $result ['verified'] ." file(s) verified in the bucket, identical to the volume. \n";

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

Cloud::select (NULL);

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
