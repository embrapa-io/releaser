<?php

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking status of ". sizeof ($_builds) ." build(s) to RESTART". ($_upgrade ? " with UPGRADE of images" : "") ."... \n";

$upgraded = [];

foreach ($_builds as $_build => $_b)
{
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

	echo "INFO > Restarting version '". $_last ."'". ($_upgrade ? " with renewed images" : "") ."... \n";

	try
	{
		(self::singleton ()->orchestrator)::restart ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]), $_upgrade);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to restart build. ". $e->getMessage () ."! \n\n";

		continue;
	}

	echo "SUCCESS > All done! The version '". $_last ."' of build '". $_build ."' was successfully ". ($_upgrade ? "upgraded and restarted" : "restarted") ."! \n";

	if ($_upgrade) $upgraded [$_build] = $_b;
}

if (!$_upgrade || !sizeof ($upgraded)) return;

// Depois do upgrade, a varredura de CVEs (o mesmo que `io cve <builds>`) e o relato ao core, para o alerta de
// CVE da plataforma enxergar a correção no mesmo ciclo, sem esperar o `cve:daemon` da próxima hora.
$_builds = [];

foreach ($upgraded as $_build => $_b)
	if (self::stats ($_b)->cve) $_builds [$_build] = $_b;
	else echo "\nINFO > ". $_build .": CVE scan skipped ('stats.cve' is false). \n";

if (!sizeof ($_builds)) return;

echo "\n";

// No Swarm, o `stack deploy` só agenda as tarefas: espera os containers novos subirem antes de varrer.
if (getenv ('ORCHESTRATOR') === 'DockerSwarm')
{
	echo "INFO > Waiting 30 seconds for the Swarm services to converge before the CVE scan... \n";

	sleep (30);
}

echo "INFO > Scanning the upgraded build(s) for CVEs and reporting to the core... \n";

require self::PATH .'cve.php';
