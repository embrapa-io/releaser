<?php

/**
 * `io upgrade [--to VERSION] [--yes] [--dry-run]`: atualiza o próprio Releaser
 * (ver class/Upgrade.php). Aqui, no contêiner atual: versão alvo, confirmação,
 * nada rodando, `docker pull` da tag exata, plano em /data/.update/plan.json e
 * o contêiner auxiliar da imagem nova, que faz a troca. Com --helper (interno),
 * é o próprio auxiliar. Variáveis do escopo: $_to, $_yes, $_dry, $_helper e
 * $_lifetimes (os do run.php).
 */

if ($_helper)
{
	$plan = Upgrade::read ($_data, 'plan.json');

	if (!isset ($plan ['id'], $plan ['container'], $plan ['image']))
		throw new Exception ("No upgrade plan in /data/". Upgrade::DIR ."/plan.json");

	echo "INFO > Upgrade helper (". Upgrade::current () ."): ". $plan ['from'] ." -> ". $plan ['to'] ." (". $plan ['mode'] .") \n";

	$last = [
		'id' => $plan ['id'],
		'from' => $plan ['from'],
		'to' => $plan ['to'],
		'image' => $plan ['image'],
		'mode' => $plan ['mode'],
		'started_at' => $plan ['started_at'],
		'result' => 'running',
		'shown' => FALSE
	];

	Upgrade::write ($_data, 'last.json', $last);

	try
	{
		$outcome = $plan ['mode'] === 'swarm' ? Upgrade::swarm ($plan) : Upgrade::swap ($plan, $_data);
	}
	catch (\Throwable $e)
	{
		$outcome = [ 'result' => 'failed', 'message' => $e->getMessage () ];
	}

	$last = array_merge ($last, $outcome, [ 'finished_at' => date (DATE_ATOM) ]);

	Upgrade::write ($_data, 'last.json', $last);

	Upgrade::lock ($_data, FALSE);

	echo "\n";

	if ($last ['result'] === 'ok')
	{
		echo "SUCCESS > Releaser upgraded from ". $last ['from'] ." to ". $last ['to'] .": ". $last ['message'] .". \n";

		return;
	}

	echo "ERROR > Releaser upgrade ". strtoupper ($last ['result']) ." (". $last ['from'] ." -> ". $last ['to'] ."): ". $last ['message'] ." \n";

	// quem chamou perdeu a sessão: o resultado vai por e-mail, além do `io info` e do menu
	$log = Upgrade::dir ($_data) . DIRECTORY_SEPARATOR .'upgrade.log';

	try
	{
		$body = is_file ($log) ? (string) file_get_contents ($log) : '';
	}
	catch (\Throwable $e)
	{
		$body = '';
	}

	Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - UPGRADE '. strtoupper ($last ['result']), $body ."\nERROR > Releaser upgrade ". strtoupper ($last ['result']) .": ". $last ['message'] ." \n");

	return;
}

$_current = Upgrade::current ();

echo "INFO > Current version: ". ($_current !== '' ? $_current : 'dev') ." (". Upgrade::repository () .") \n";

$_self = BackupVolume::container ();

if ($_self === FALSE)
{
	echo "ERROR > Unable to find this Releaser container through the Docker socket ('docker inspect' of '". gethostname () ."' failed). Upgrade by hand: https://embrapa.io/docs/releaser/#update \n";

	return;
}

$_container = Upgrade::inspect ($_self);

$_name = ltrim ((string) $_container->Name, '/');

$_service = Upgrade::service ($_container);

$_labels = $_container->Config->Labels ?? NULL;

if (!$_service && isset ($_labels->{'com.docker.compose.project'}))
{
	echo "ERROR > This Releaser container was created by Docker Compose (project '". $_labels->{'com.docker.compose.project'} ."'): recreating it here would leave the compose file behind. Change the image tag in the compose file and run 'docker compose up -d' instead. \n";

	return;
}

// variáveis definidas na criação do contêiner (não as da imagem): o auxiliar também as recebe (proxy...)
$_imageOld = Upgrade::inspect ($_container->Image, 'image');

$_userEnv = array_values (array_diff ((array) ($_container->Config->Env ?? []), (array) ($_imageOld->Config->Env ?? [])));

$_mountData = Upgrade::mount ($_container, '/data');
$_mountSocket = Upgrade::mount ($_container, Upgrade::SOCKET);

if (!$_mountData || !$_mountSocket)
{
	echo "ERROR > This container has no ". (!$_mountData ? "'/data'" : "'". Upgrade::SOCKET ."'") ." mount: the helper container could not reach it. Upgrade by hand: https://embrapa.io/docs/releaser/#update \n";

	return;
}

// versão alvo: a pedida ou a mais nova do registro (consulta agora, sem o cache)
if ($_to !== NULL)
{
	if (!Upgrade::valid ($_to))
	{
		echo "ERROR > Invalid version '". $_to ."': use the format MAJOR.YY.M-N of the published tags (e.g., 1.26.10-13). \n";

		return;
	}

	$_target = $_to;
}
else
{
	echo "INFO > Looking for the latest version of ". Upgrade::repository () ."... \n";

	$cache = Upgrade::latest ($_data, TRUE, 10);

	if (!isset ($cache ['version']) || isset ($cache ['error']))
	{
		echo "ERROR > Unable to get the published versions (". ($cache ['error'] ?? 'no answer') ."). Try again later or choose one: io upgrade --to VERSION \n";

		return;
	}

	$_target = $cache ['version'];
}

if ($_target === $_current)
{
	echo "SUCCESS > This Releaser is already running version ". $_target .". Nothing to do. \n";

	return;
}

if (Upgrade::valid ($_current) && Upgrade::compare ($_target, $_current) < 0)
{
	if ($_to === NULL)
	{
		echo "SUCCESS > This Releaser (". $_current .") is newer than the latest published version (". $_target ."). Nothing to do. \n";

		return;
	}

	echo "WARNING > ". $_target ." is OLDER than the current version (". $_current ."): this is a downgrade. \n";
}

$_ref = Upgrade::reference ($_target);

echo "INFO > Upgrade: ". ($_current !== '' ? $_current : 'dev') ." -> ". $_target ." (image ". $_ref .", always the exact tag) \n";
echo "INFO > What changes: ". ($_service ? "the image of the Docker Swarm service '". $_service ."'" : "the container '". $_name ."', recreated with the same configuration") ." \n";

// nada no meio de uma operação
$_blocked = FALSE;

if (Upgrade::running ($_data))
{
	echo "ERROR > Another upgrade is in progress (/data/.lock/". Upgrade::LOCK ."). See 'io info'. \n";

	$_blocked = TRUE;
}

$helpers = Upgrade::docker ('ps -q --filter '. escapeshellarg ('label='. Upgrade::LABEL), FALSE, TRUE);

if (!$_blocked && sizeof ($helpers ['out']))
{
	echo "ERROR > An upgrade helper container is still running (label ". Upgrade::LABEL ."): wait for it to finish (see 'io info'). \n";

	$_blocked = TRUE;
}

$_busy = Upgrade::busy ($_data, $_lifetimes);

foreach ($_busy ['stale'] as $op)
	echo "WARNING > Lock of '". $op ."' in /data/.lock has no process running (left by a stopped container): ignored. \n";

if (sizeof ($_busy ['busy']))
{
	echo ($_dry ? "WARNING" : "ERROR") ." > Not upgrading in the middle of an operation: ". implode ('; ', $_busy ['busy']) .". Try again when it finishes. \n";

	$_blocked = TRUE;
}

if ($_blocked && !$_dry) return;

if ($_service)
{
	$info = Upgrade::docker ('info --format '. escapeshellarg ('{{.Swarm.ControlAvailable}}'), FALSE, TRUE);

	if (trim ((string) ($info ['out'][0] ?? '')) !== 'true')
	{
		echo "ERROR > This node is not a Docker Swarm manager, so it cannot update the service. On a manager node, run: docker service update --image ". $_ref ." --update-failure-action rollback ". $_service ." \n";

		return;
	}

	echo "INFO > The Swarm keeps the service spec (mounts, constraints, variables) and only changes the image, rolling back by itself if the new task does not start. \n";
}
else
{
	list ($body, $extra) = Upgrade::body ($_container, $_imageOld, $_ref);

	echo "INFO > The new container keeps: \n";

	foreach (Upgrade::summary ($_container, $body) as $line) echo "       ". $line ."\n";
}

if ($_dry)
{
	echo "INFO > Dry run: nothing was pulled, stopped or created. Without --dry-run, after the confirmation: 'docker pull ". $_ref ."', a helper container of the new image does the switch (". ($_service ? "'docker service update'" : "stop, recreate, wait, 'io check'") .") and rolls back by itself if the new one fails. \n";

	return;
}

if (!$_yes)
{
	echo "Upgrade the Releaser from ". ($_current !== '' ? $_current : 'dev') ." to ". $_target ." now? (y/N): ";

	$line = fgets (STDIN);

	if ($line === FALSE || !in_array (strtolower (trim ($line)), [ 'y', 'yes' ]))
	{
		echo "\nINFO > Nothing done. \n";

		return;
	}
}

// a partir daqui, os jobs do daemon não começam (run.php) até o auxiliar terminar
Upgrade::lock ($_data, TRUE);

try
{
	echo "\nINFO > Pulling ". $_ref ."... \n";

	$pull = Upgrade::docker ('pull '. escapeshellarg ($_ref));

	echo "INFO > ". trim ((string) end ($pull ['out'])) ." \n";

	$image = Upgrade::inspect ($_ref, 'image');

	if (!in_array ('IO_RELEASER_VERSION='. $_target, (array) ($image->Config->Env ?? [])))
		echo "WARNING > The image ". $_ref ." does not declare IO_RELEASER_VERSION=". $_target .". \n";

	$plan = [
		'id' => date ('YmdHis') .'-'. bin2hex (random_bytes (3)),
		'from' => $_current !== '' ? $_current : 'dev',
		'to' => $_target,
		'image' => $_ref,
		'image_id' => $image->Id,
		'previous_image' => $_container->Image,
		'container' => $_container->Id,
		'name' => $_name,
		'mode' => $_service ? 'swarm' : 'container',
		'service' => $_service,
		'ssh' => file_exists ('/root/.ssh/id_rsa'),
		'started_at' => date (DATE_ATOM)
	];

	if (!Upgrade::write ($_data, 'plan.json', $plan))
		throw new Exception ("Unable to write /data/". Upgrade::DIR ."/plan.json");

	$log = Upgrade::dir ($_data) . DIRECTORY_SEPARATOR .'upgrade.log';

	file_put_contents ($log, "=== Releaser upgrade ". $plan ['from'] ." -> ". $plan ['to'] ." at ". $plan ['started_at'] ." === \n");

	// O auxiliar: imagem nova, socket e /data do contêiner atual, a mesma rede (menos no Swarm, cuja
	// overlay pode não aceitar contêineres avulsos) e as variáveis definidas na criação (proxy...),
	// com valor só no ambiente do `docker` (não na linha de comando).
	$_helperName = ($_service ? $_service : $_name) .'-upgrade';

	$cmd = Upgrade::DOCKER .' run -d --rm --name '. escapeshellarg ($_helperName) .' --label '. escapeshellarg (Upgrade::LABEL .'='. $plan ['id']);

	$network = (string) ($_container->HostConfig->NetworkMode ?? 'default');

	if (!$_service && !in_array ($network, [ 'default', 'bridge', 'none' ]) && strpos ($network, 'container:') !== 0)
		$cmd .= ' --network '. escapeshellarg ($network);

	foreach ((array) ($_container->HostConfig->ExtraHosts ?? []) as $host) $cmd .= ' --add-host '. escapeshellarg ($host);

	foreach ((array) ($_container->HostConfig->Dns ?? []) as $dns) $cmd .= ' --dns '. escapeshellarg ($dns);

	$env = [];

	foreach ($_userEnv as $pair)
	{
		list ($key, $value) = array_pad (explode ('=', $pair, 2), 2, '');

		if (in_array ($key, [ 'PATH', 'HOME', 'HOSTNAME', 'IO_RELEASER_VERSION' ]) || !preg_match ('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;

		$env [$key] = $value;

		$cmd .= ' -e '. $key;
	}

	$cmd .= ' -v '. escapeshellarg (Upgrade::source ($_mountSocket) .':'. Upgrade::SOCKET);
	$cmd .= ' -v '. escapeshellarg (Upgrade::source ($_mountData) .':/data');
	$cmd .= ' --entrypoint /bin/sh '. escapeshellarg ($_ref) .' -c '. escapeshellarg ('export $(cat /data/.env) && exec /usr/local/bin/php /app/run.php upgrade --helper >> /data/'. Upgrade::DIR .'/upgrade.log 2>&1');

	echo "INFO > Starting the helper container '". $_helperName ."' from the new image... \n";

	echo 'COMMAND > '. $cmd ."\n";

	$helper = BackupVolume::command ($cmd, $env);

	if ($helper ['code'] !== 0)
		throw new Exception ("Unable to start the helper container (". trim (implode (' ', array_slice (array_merge ($helper ['err'], $helper ['out']), -1))) .")");
}
catch (\Throwable $e)
{
	Upgrade::lock ($_data, FALSE);

	echo "ERROR > ". $e->getMessage () .". Nothing was changed. \n";

	return;
}

echo "INFO > The helper container does the switch on its own: this session ends when the current container stops, and the switch goes on. \n";
echo "INFO > Then run 'io info' (or just 'io') to see the result; the log is in /data/". Upgrade::DIR ."/upgrade.log. \n";

echo "\n";

// acompanha o log do auxiliar até a troca terminar (ou até esta sessão morrer com o contêiner)
$offset = 0;

$deadline = time () + 15 * 60;

while (time () < $deadline)
{
	clearstatcache ();

	try
	{
		if (is_file ($log) && filesize ($log) > $offset)
		{
			$chunk = (string) file_get_contents ($log, FALSE, NULL, $offset);

			$offset += strlen ($chunk);

			foreach (explode ("\n", rtrim ($chunk, "\n")) as $line)
				if (trim ($line) !== '' && strpos ($line, '=== Releaser upgrade') !== 0) echo '  [helper] '. $line ."\n";
		}
	}
	catch (\Throwable $e) {}

	$last = Upgrade::last ($_data);

	if (($last ['id'] ?? '') === $plan ['id'] && ($last ['result'] ?? 'running') !== 'running') break;

	sleep (1);
}

echo "\n";

$last = Upgrade::last ($_data);

if (($last ['id'] ?? '') === $plan ['id'])
	echo ($last ['result'] === 'ok' ? 'SUCCESS' : (($last ['result'] ?? '') === 'running' ? 'WARNING' : 'ERROR')) ." > Last upgrade: ". Upgrade::describe ($last) ." \n";
