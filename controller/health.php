<?php

/**
 * Saúde dos serviços de cada build (estado dos containers no host), relatada
 * ao core num único POST. No daemon roda a cada minuto, em silêncio: só
 * WARNING/ERROR saem (e nunca e-mail — $_nothing fica TRUE).
 */

$orchestrator = getenv ('ORCHESTRATOR');

$items = [];

foreach ($_builds as $_build => $_b)
{
	$stats = self::stats ($_b);

	if ($_daemon && !$stats->health) continue;

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		if (!$_daemon) echo "ERROR > Invalid build name: ". $_build ."! \n";

		continue;
	}

	$namespace = implode ('_', [ $_b->project, $_b->app, $_b->stage ]);

	try
	{
		$containers = $orchestrator::containers ($namespace);
	}
	catch (Exception $e)
	{
		echo "WARNING > Impossible to inspect containers of ". $_build .": ". $e->getMessage () ." \n";

		continue;
	}

	if (!sizeof ($containers))
	{
		if (!$_daemon) echo "INFO > ". $_build .": no containers in this host (not deployed yet?). \n";

		continue;
	}

	$services = [];

	foreach ($containers as $service => $c)
		$services [$service] = [
			'state' => $c ['state'],
			'status' => $c ['status'],
			'healthy' => $c ['healthy'],
			'size' => $c ['size'],
			'created' => $c ['created'],
			'running' => $c ['running']
		];

	if (!$_daemon)
	{
		echo "\n=== ". $_build ." === \n\n";

		foreach ($services as $service => $s)
			echo "INFO > ". str_pad ($service, 20) . str_pad ($s ['state'], 12) . str_pad ($s ['healthy'], 18) . $s ['status'] ." \n";

		echo "\n";
	}

	$item = [
		'project' => $_b->project,
		'app' => $_b->app,
		'stage' => $_b->stage,
		'services' => $services
	];

	// A versão implantada vai junto (lida do .version da build): assim o estágio aparece
	// no Dashboard já na primeira rodada, sem esperar o próximo deploy. O core só
	// registra quando ela muda.
	if ($stats->version)
	{
		$file = $_data . DIRECTORY_SEPARATOR .'apps'. DIRECTORY_SEPARATOR . $_b->project . DIRECTORY_SEPARATOR . $_b->app . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

		$deployed = file_exists ($file) ? trim ((string) @file_get_contents ($file)) : '';

		if ($deployed !== '' && self::score ($_b->stage, $deployed)) $item ['version'] = $deployed;
	}

	$items [] = $item;
}

if (!sizeof ($items))
{
	if (!$_daemon) echo "INFO > Nothing to report. \n";

	return;
}

if (!$_daemon) echo "INFO > Reporting the health of ". sizeof ($items) ." build(s) to the core... \n";

Report::send ($items, $_daemon);
