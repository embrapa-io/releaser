<?php

/**
 * `io backup <builds|--all>`: roda o serviço `backup` da versão implantada de cada build. No daemon (diário), só as builds
 * com `auto.backup` = true, e desde a `1.26.11-9` a build cujo backup NÃO foi feito manda um e-mail
 * (`<build> - BACKUP ERROR`, para `LOG_MAIL` e `team`): o serviço falhou, o clone da versão sumiu, o nome da build é
 * inválido ou outro comando segurou a build além da espera do daemon. Até então a noite sem backup só aparecia no Sentry.
 * Backup feito não manda e-mail (seria um por build, toda noite), nem o que não é falha: build sem versão implantada, sem
 * serviço de backup (SkipException) ou com um `io restore` em andamento. Variáveis do escopo: $_builds, $_daemon, $_data.
 */

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking the status of ". sizeof ($_builds) ." build(s) to BACK UP... \n";

foreach ($_builds as $_build => $_b)
{
	BuildLock::release ();

	// Arquivo de build sem o atributo `auto` (ou sem `auto.backup`): fora do daemon, como as demais sem `auto.backup`. A
	// leitura direta quebrava o comando inteiro nesse caso, e a trava da operação ficava para trás.
	if ($_daemon && !(isset ($_b->auto) && isset ($_b->auto->backup) && $_b->auto->backup)) continue;

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	// No daemon, a saída de cada build fica num buffer próprio: vira o corpo do e-mail quando o backup não foi feito.
	if ($_daemon) ob_start ();

	$_failed = FALSE;

	do
	{
		if (self::restoring ($_b))
		{
			echo "WARNING > A restore is running on this build ('io restore'). Skipping. \n\n";

			break;
		}

		// Trava por build (class/BuildLock.php): não faz o backup no meio de um deploy, restart ou outro comando na build.
		// No daemon, espera o outro terminar; se não terminar, esta noite fica sem backup.
		if (!self::lock ($_b, 'backup'))
		{
			$_failed = TRUE;

			break;
		}

		echo "INFO > Checking if build '". $_build ."' has been deployed... \n";

		if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
		{
			echo "ERROR > Invalid build name! \n\n";

			$_failed = TRUE;

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

		// Nada implantado ainda: não há o que copiar, e não é falha de backup.
		if (!is_string ($_last) || $_last == '' || !self::score ($_b->stage, $_last))
		{
			echo "ERROR > No valid version has been deployed to this build! \n\n";

			break;
		}

		$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

		if (!file_exists ($clone) || !is_dir ($clone))
		{
			echo "ERROR > The clone of version/tag '". $_last ."' is missing! \n\n";

			$_failed = TRUE;

			break;
		}

		echo "INFO > Backing up version '". $_last ."'... \n";

		try
		{
			(self::singleton ()->orchestrator)::backup ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
		}
		catch (SkipException $e)
		{
			echo "WARNING > Nothing to do. ". $e->getMessage () .". Skipping this build. \n\n";

			break;
		}
		catch (Exception $e)
		{
			echo "ERROR > Unable to back up build. ". $e->getMessage () ."! \n\n";

			$_failed = TRUE;

			break;
		}

		echo "SUCCESS > All done! Version '". $_last ."' of build '". $_build ."' was successfully backed up! \n";

		// Dica da cópia em nuvem (só em execução manual: no daemon o 'cloud' roda logo depois do 'backup').
		// Sem S3 configurado (no arquivo do projeto ou no .env), nada; com 'auto.cloud' desligado, avisa que o daemon não copiará.
		Cloud::select ($_b->project ?? NULL);

		if (!$_daemon && Cloud::configured ())
		{
			$autoCloud = isset ($_b->auto) && isset ($_b->auto->cloud) ? $_b->auto->cloud : FALSE;

			echo "INFO > To copy this backup to the S3 bucket now, run: io cloud ". $_build . (Retention::policy ($autoCloud) === FALSE ? ". The daily daemon will not copy it unless the build file has \"auto\": { \"cloud\": true }" : '') ." \n";
		}
	}
	while (FALSE);

	if (!$_daemon) continue;

	if ($_failed)
	{
		echo "WARNING > The daily backup of build '". $_build ."' was NOT done. The next attempt is in the next daily run; to try now: io backup ". $_build ." \n";

		Mail::singleton ()->send ($_build .' - BACKUP ERROR', mailBody (), isset ($_b->team) ? $_b->team : []);
	}
	else
		ob_end_flush ();
}
