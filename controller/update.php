<?php

/**
 * `io update <builds|--all> [--dry-run] [--no-backup]`: mantém a versão implantada e traz as imagens de registro para o conteúdo
 * mais recente, recriando só os serviços cuja imagem mudou (ver `DockerCompose::update`). No daemon (diário, depois
 * de `backup`, `cleaner` e `cloud`), só as builds com `auto.update` = true; e-mail por build apenas quando algo foi
 * atualizado ou falhou. Variáveis do escopo: $_builds, $_daemon, $_data, $_dryRun, $_backup (FALSE = `--no-backup`).
 */

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking the images of ". sizeof ($_builds) ." build(s) to UPDATE... \n";

if ($_dryRun) echo "INFO > Dry run: the images are pulled, but no container is recreated. \n";

$updated = [];

foreach ($_builds as $_build => $_b)
{
	BuildLock::release ();

	$auto = isset ($_b->auto) && isset ($_b->auto->update) ? $_b->auto->update : FALSE;

	if ($_daemon && $auto !== TRUE) continue;

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	// No daemon, a saída de cada build fica num buffer próprio: vira o corpo do e-mail quando há o que avisar.
	if ($_daemon) ob_start ();

	$_subject = NULL;

	do
	{
		if (self::restoring ($_b))
		{
			echo "WARNING > A restore is running on this build ('io restore'). Skipping. \n\n";

			break;
		}

		if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
		{
			echo "ERROR > Invalid build name! \n\n";

			break;
		}

		// Parada com `io stop`: atualizar subiria a build de novo.
		if (Lifecycle::isStopped ($_data, $_b))
		{
			echo "INFO > Stopped with 'io stop': nothing to update (use 'io restart --upgrade' to bring it back with renewed images). \n\n";

			break;
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

			break;
		}

		$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

		if (!file_exists ($clone) || !is_dir ($clone))
		{
			echo "ERROR > The clone of version/tag '". $_last ."' is missing! \n\n";

			break;
		}

		// Trava por build (class/BuildLock.php): não atualiza no meio de um deploy, backup ou outro comando na build. No
		// daemon espera o outro terminar; se não terminar, a próxima noite tenta de novo (sem e-mail). O --dry-run só baixa
		// as imagens e dispensa a trava.
		if (!$_dryRun && !self::lock ($_b, 'update')) break;

		echo "INFO > Checking the images of version '". $_last ."'... \n";

		try
		{
			$changed = (self::singleton ()->orchestrator)::update ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]), $_dryRun, $_backup, self::backupFailed ());
		}
		catch (SkipException $e)
		{
			echo "WARNING > Nothing to do. ". $e->getMessage () .". Skipping this build. \n\n";

			break;
		}
		catch (Exception $e)
		{
			echo "ERROR > Unable to update build. ". $e->getMessage () ."! \n\n";

			$_subject = '- UPDATE ERROR';

			break;
		}

		if (!sizeof ($changed))
		{
			echo "INFO > The images of build '". $_build ."' are up to date. Nothing to do. \n";

			break;
		}

		if ($_dryRun)
		{
			echo "INFO > Dry run: ". sizeof ($changed) ." service(s) of build '". $_build ."' would be recreated: ". implode (', ', array_keys ($changed)) .". \n";

			break;
		}

		echo "SUCCESS > All done! Version '". $_last ."' of build '". $_build ."' was updated: ". sizeof ($changed) ." service(s) recreated with a new image (". implode (', ', array_keys ($changed)) ."). \n";

		$_subject = $_last .' - UPDATE SUCCESS';

		$updated [$_build] = $_b;
	}
	while (FALSE);

	if (!$_daemon) continue;

	if ($_subject !== NULL)
		Mail::singleton ()->send ($_build .' '. $_subject, mailBody (), isset ($_b->team) ? $_b->team : []);
	else
		ob_end_flush ();
}

// A varredura de CVEs abaixo só lê: a trava da última build não fica presa por ela.
BuildLock::release ();

if (!sizeof ($updated)) return;

// Depois da atualização, a varredura de CVEs (o mesmo que `io cve <builds>`) e o relato ao core, para o alerta de CVE da
// plataforma enxergar a imagem nova no mesmo ciclo, sem esperar o `cve:daemon` da próxima hora.
$_builds = [];

foreach ($updated as $_build => $_b)
	if (self::stats ($_b)->cve) $_builds [$_build] = $_b;
	else echo "\nINFO > ". $_build .": CVE scan skipped ('stats.cve' is false). \n";

if (!sizeof ($_builds)) return;

echo "\n";

echo "INFO > Scanning the updated build(s) for CVEs and reporting to the core... \n";

require self::PATH .'cve.php';
