<?php

/**
 * CVEs (HIGH/CRITICAL) das imagens em execução de cada build, pelo Trivy no
 * próprio host, relatadas ao core num único POST. Cada imagem distinta é
 * varrida uma vez por rodada. Build com alguma varredura falha fica de fora
 * do relato: o core preserva o resultado anterior em vez de receber "limpo".
 */

$orchestrator = getenv ('ORCHESTRATOR');

$targets = [];

foreach ($_builds as $_build => $_b)
{
	$stats = self::stats ($_b);

	if ($_daemon && !$stats->cve) continue;

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name: ". $_build ."! \n";

		continue;
	}

	$namespace = implode ('_', [ $_b->project, $_b->app, $_b->stage ]);

	try
	{
		$containers = $orchestrator::containers ($namespace);
	}
	catch (Exception $e)
	{
		echo "WARNING > Unable to inspect the containers of ". $_build .": ". $e->getMessage () ." \n";

		continue;
	}

	$images = [];

	foreach ($containers as $service => $c)
		if ($c ['state'] === 'running' && $c ['image'] !== '') $images [$service] = $c ['image'];

	if (!sizeof ($images))
	{
		if (!$_daemon) echo "INFO > ". $_build .": no running containers on this host. \n";

		continue;
	}

	$targets [$_build] = [ 'b' => $_b, 'images' => $images ];
}

if (!sizeof ($targets))
{
	echo "INFO > Nothing to scan. \n";

	return;
}

echo "INFO > ". sizeof ($targets) ." build(s) to scan. Updating the vulnerability database... \n";

try
{
	Trivy::updateDB ();
}
catch (Exception $e)
{
	echo "ERROR > ". $e->getMessage () .". Hosts without access to ghcr.io cannot run the scan: set 'stats.cve' to false in the builds of this server to silence this message. \n";

	return;
}

$cache = []; // imagem => lista de CVEs (ou NULL se a varredura falhou)

$items = [];

foreach ($targets as $_build => $t)
{
	echo "\n=== ". $_build ." === \n\n";

	$services = [];

	$failed = FALSE;

	foreach ($t ['images'] as $service => $image)
	{
		if (!array_key_exists ($image, $cache))
		{
			echo "INFO > Scanning image '". $image ."' (service '". $service ."')... \n";

			try
			{
				$cache [$image] = Trivy::scan ($image);
			}
			catch (Exception $e)
			{
				echo "WARNING > Scan of '". $image ."' failed: ". $e->getMessage () .". \n";

				$cache [$image] = NULL;
			}
		}

		if ($cache [$image] === NULL) { $failed = TRUE; continue; }

		$services [$service] = $cache [$image];

		$critical = sizeof (array_filter ($cache [$image], function ($v) { return $v ['severity'] === 'CRITICAL'; }));

		echo "INFO > ". str_pad ($service, 20) . $critical ." critical, ". (sizeof ($cache [$image]) - $critical) ." high \n";
	}

	if ($failed)
	{
		echo "WARNING > Some image of ". $_build ." could not be scanned: the previous result (if any) is kept in the core. \n";

		continue;
	}

	$items [] = [
		'project' => $t ['b']->project,
		'app' => $t ['b']->app,
		'stage' => $t ['b']->stage,
		'cve' => [ 'services' => $services ]
	];
}

echo "\n";

if (!sizeof ($items))
{
	echo "WARNING > No build fully scanned: nothing to report. \n";

	return;
}

echo "INFO > Reporting the CVEs of ". sizeof ($items) ." build(s) to the core... \n";

Report::send ($items);
