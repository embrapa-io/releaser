<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

// o próprio Releaser: versão, a mais nova publicada (cache do menu/`io check`) e a última troca (`io upgrade`)
echo "\n";

echo "=== Releaser === \n";

$latest = Upgrade::latest ($_data);

$update = Upgrade::available ($_data);

echo "Version: ". (Upgrade::current () !== '' ? Upgrade::current () : 'dev') ." (". Upgrade::repository () .", ". getenv ('ORCHESTRATOR') .") \n";

echo "Latest published: ". (isset ($latest ['version']) ? $latest ['version'] .' (checked at '. ($latest ['checked_at'] ?? '?') .')' : 'unknown'. (isset ($latest ['error']) ? ' ('. $latest ['error'] .')' : '')) . ($update !== NULL ? " - UPDATE AVAILABLE: run 'io upgrade'" : '') ." \n";

echo "Last upgrade: ". Upgrade::describe (Upgrade::last ($_data)) ." \n";

if ($_noBuilds !== NULL) throw $_noBuilds;

self::singleton ();

foreach ($_builds as $_build => $_b)
{
	echo "\n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name: ". $_build ."! \n";

		continue;
	}

	echo "=== ". $_build ." === \n";

	echo "Configured in: ". (isset ($_b->_file) ? str_replace ($_data . DIRECTORY_SEPARATOR, '', $_b->_file) : '?') ." \n";

	$stats = self::stats ($_b);

	$report = Report::last ($_b->project, $_b->app, $_b->stage);

	foreach ([ 'version', 'health', 'cve' ] as $signal)
	{
		if (!$stats->$signal) { echo "Report of ". $signal .": disabled (stats.". $signal ." = false) \n"; continue; }

		if (!isset ($report [$signal])) { echo "Report of ". $signal .": never sent to the core \n"; continue; }

		echo "Report of ". $signal .": ". ($report [$signal]['status'] === 200 ? 'accepted' : 'REFUSED ('. $report [$signal]['status'] .': '. $report [$signal]['reason'] .')') ." at ". $report [$signal]['at'] ." \n";
	}

	// última cópia em nuvem (io cloud / cloud:daemon), de /data/.cloud/<p>_<a>_<s>.json
	$cloud = Cloud::last ($_b->project, $_b->app, $_b->stage);

	$autoCloud = isset ($_b->auto) && isset ($_b->auto->cloud) ? $_b->auto->cloud : FALSE;

	if (!isset ($cloud ['status']))
		echo "Cloud copy (S3): ". (Retention::policy ($autoCloud) === FALSE ? 'disabled (auto.cloud = false)' : (Cloud::configured () ? 'never run' : 'bucket not configured (S3_* in .env)')) ." \n";
	elseif ($cloud ['status'] === 'ok')
		echo "Cloud copy (S3): ok at ". $cloud ['at'] ." (". intval ($cloud ['uploaded'] ?? 0) ." uploaded, ". Orchestrator::humanSize ($cloud ['bytes_uploaded'] ?? 0) ."; ". intval ($cloud ['deleted'] ?? 0) ." deleted; ". intval ($cloud ['files_after'] ?? 0) ." file(s), ". Orchestrator::humanSize ($cloud ['bytes_after'] ?? 0) ." in the bucket) \n";
	else
		echo "Cloud copy (S3): FAILED at ". $cloud ['at'] .": ". (string) ($cloud ['error'] ?? '?') ." (last success: ". ($cloud ['success_at'] ?? 'never') .") \n";

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
		echo "ERROR > No valid version has been deployed to build ". $_build ."! \n";

		continue;
	}

	$rollback = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.rollback'. DIRECTORY_SEPARATOR . $_b->stage;

	try
	{
		$_rollback = trim (file_get_contents ($rollback));
	}
	catch (Exception $e)
	{
		$aux = dirname ($rollback);

		if (!file_exists ($aux) || !is_dir ($aux)) mkdir ($aux, 0777, TRUE);

		$_rollback = NULL;
	}

	if (!is_null ($_rollback) && self::score ($_b->stage, $_rollback) && $_rollback != $_last)
	{
		$_version = $_rollback;
		$isRollbacked = TRUE;
	}
	else
	{
		$_version = $_last;
		$isRollbacked = FALSE;
	}

	$clone = 'apps'. DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_version]);

	if (!file_exists ($_data . DIRECTORY_SEPARATOR . $clone) || !is_dir ($_data . DIRECTORY_SEPARATOR . $clone))
	{
		echo "ERROR > The clone of version/tag '". $_version ."' of build '". $_build ."' is missing! \n";

		continue;
	}

	echo "For ". $_build ." (version ". $_version ."): \n";
	echo "cd ". $clone ." \n";

	if ($isRollbacked) echo "Important! This version was rolled back from version ". $_last .". \n";
}

$disabled = self::disabledBuilds ($_data);

if (sizeof ($disabled))
{
	echo "\n";

	echo "Disabled builds (files in 'builds/' without the .json extension): \n";

	foreach ($disabled as $entry) echo "  builds/". $entry ."\n";
}

echo "\n";

echo "Once in the directory, you can run the commands of orchestrator ". self::singleton ()->orchestrator .": \n";

echo (self::singleton ()->orchestrator)::reference ();
