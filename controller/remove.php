<?php

/**
 * `io remove <builds> [--yes]`: tira a build deste servidor e da plataforma, SEM APAGAR DADOS.
 *
 * 1. para e remove os contêineres (o mesmo `stop` com `--down` do `io stop --down`: `docker compose down
 *    --remove-orphans`, sem `-v`; no Swarm, `stack rm`) — volumes ficam;
 * 2. pede ao core que esqueça a build (DELETE /releaser/build): versão, saúde e CVEs saem do Dashboard e dos alertas;
 * 3. desativa o arquivo da build (`.json` → `.bkp`), para nenhum daemon mexer nela de novo.
 *
 * Nunca apaga volume, clone de versão (nem o que bind mounts gravaram dentro dele) ou backup: lista o que ficou e
 * como apagar à mão. Se o passo 1 falha, nada mais é feito na build (ela segue no ar e no card).
 */

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

$orchestrator = getenv ('ORCHESTRATOR');

if (!$_yes)
{
	if (!(function_exists ('posix_isatty') && posix_isatty (STDIN) && posix_isatty (STDOUT)))
		throw new Exception ("'remove' asks for confirmation: outside a terminal, use '--yes'");

	echo "The following build(s) will be REMOVED from this server and from the platform: ". implode (', ', array_keys ($_builds)) .". \n";
	echo "Containers are removed; volumes, clones and backups are kept. Continue? (y/N): ";

	$line = fgets (STDIN);

	if ($line === FALSE || !in_array (strtolower (trim ($line)), [ 'y', 'yes' ]))
	{
		echo "\nINFO > Nothing done. \n";

		return;
	}
}

echo "INFO > Removing ". sizeof ($_builds) ." build(s)... \n";

foreach ($_builds as $_build => $_b)
{
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

	// Antes de mexer em qualquer coisa: a build precisa ter arquivo próprio, que é o que se desativa.
	try
	{
		Lifecycle::disabledName ($_b->_file);
	}
	catch (Exception $e)
	{
		echo "ERROR > ". $e->getMessage () ."! Nothing was done to this build. \n\n";

		continue;
	}

	$namespace = Lifecycle::namespace ($_b);

	// 1. contêineres
	$version = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

	$_last = file_exists ($version) ? trim ((string) file_get_contents ($version)) : '';

	$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

	if ($_last !== '' && self::score ($_b->stage, $_last) && file_exists ($clone) && is_dir ($clone))
	{
		echo "INFO > Removing the containers of version '". $_last ."' (volumes are kept)... \n";

		try
		{
			(self::singleton ()->orchestrator)::stop ($clone, $namespace, TRUE);
		}
		catch (Exception $e)
		{
			echo "ERROR > Unable to remove the containers: ". $e->getMessage () ."! Nothing else was done: the build is still configured and reported to the platform. \n\n";

			continue;
		}
	}
	else
	{
		$left = [];

		try { $left = $orchestrator::containers ($namespace); } catch (Exception $e) {}

		if (sizeof ($left))
		{
			echo "ERROR > No deployed version to stop, but there are containers of this build on the host (". implode (', ', array_keys ($left)) ."). Remove them first (docker compose down, without -v). Nothing was done. \n\n";

			continue;
		}

		echo "INFO > No version deployed by this Releaser: there are no containers to remove. \n";
	}

	// 2. plataforma
	echo "INFO > Asking the platform to forget the build... \n";

	$forget = Report::forget ($_b->project, $_b->app, $_b->stage);

	if ($forget ['ok'])
		echo $forget ['forgotten'] ? "INFO > The platform forgot the build: it left the Dashboard and the CVE alerts. \n" : "INFO > The platform did not know this build anymore. \n";
	else
		echo "WARNING > ". $forget ['message'] .". The build may stay in the Dashboard card: remove it there ('Remover do Card', in the status of the stage) or it leaves by itself after 30 days without contact. \n";

	// 3. arquivo da build
	try
	{
		$disabled = Lifecycle::disable ($_b->_file);

		echo "INFO > Build file disabled: '". $_b->_file ."' is now '". $disabled ."'. \n";
	}
	catch (Exception $e)
	{
		echo "ERROR > Unable to disable the build file: ". $e->getMessage () ."! Rename or move it by hand, or the daemons will keep handling this build. \n\n";

		continue;
	}

	Lifecycle::clearStopped ($_data, $_b);

	echo "INFO > Kept on this server (nothing was deleted): \n";

	foreach (Lifecycle::kept ($_data, $_b, $orchestrator::volumes ($namespace), trim ((string) getenv ('BACKUP_DIR'))) as $line)
		echo $line ." \n";

	echo "INFO > To bring it back: rename '". $disabled ."' to '". $_b->_file ."' and run 'io deploy ". $_build ." --force'. \n";

	echo "SUCCESS > Build '". $_build ."' was removed from this server and from the platform. Its data is still here. \n";
}
