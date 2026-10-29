<?php
/**
 * `io restore <build>`: restaura na build um backup do volume local ou do bucket S3 (io cloud), com o mesmo
 * contrato e as mesmas etapas do restore da plataforma (Doctor):
 *
 *   preparing       build implantada (versão e clone), volume de backup, nenhum outro restore nela (trava);
 *   before-restore  roda o 'backup' da build e marca o arquivo novo com '_before-restore_' antes da data (fora da
 *                   rotação por 30 dias, Retention::shielded); com S3, envia-o ao bucket. Se falhar, NADA é
 *                   restaurado;
 *   download        só da origem S3: espaço livre >= 2x o arquivo, copyto para o volume, tamanho conferido;
 *   verify          SHA-256 (o informado, se houver) e leitura do tar.gz, numa passada só (BackupVolume::inspect);
 *   restore         serviço 'restore' da app com BACKUP_FILE_TO_RESTORE (o mesmo do Doctor).
 *
 * O arquivo baixado do bucket é apagado do volume no fim (a cópia continua no bucket); o escolhido no volume nunca.
 * Resultado em /data/.restore/<proj>_<app>_<stage>.json (io info) e por e-mail (LOG_MAIL e equipe da build).
 *
 * Variáveis do escopo: $_builds (uma build), $_opts (ver Controller::restore), $_data.
 */

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

$orchestrator = getenv ('ORCHESTRATOR');

$_build = array_key_first ($_builds);

$_b = $_builds [$_build];

$GROUPS = [ 'alpha', 'beta', 'release', 'upload' ];

$NAME = '/^[A-Za-z0-9._-]+\.tar\.gz$/';

$tty = function_exists ('posix_isatty') && posix_isatty (STDIN) && posix_isatty (STDOUT);

$ask = function ($prompt)
{
	echo $prompt;

	$line = fgets (STDIN);

	return $line === FALSE ? NULL : trim ($line);
};

$confirm = function ($prompt) use ($ask, $_opts)
{
	if ($_opts ['yes']) return TRUE;

	$answer = $ask ($prompt .' (y/N): ');

	return $answer !== NULL && in_array (strtolower ($answer), [ 'y', 'yes', 's', 'sim' ]);
};

echo "\n";

echo "=== ". $_build ." === \n\n";

if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
{
	echo "ERROR > Invalid build name! \n\n";

	return;
}

$namespace = implode ('_', [$_b->project, $_b->app, $_b->stage]);

$appDir = $_b->project .'/'. $_b->app;

// --- preparing --------------------------------------------------------------------------------------------------

$version = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

$_last = file_exists ($version) ? trim ((string) file_get_contents ($version)) : '';

if ($_last === '' || !self::score ($_b->stage, $_last))
{
	echo "ERROR > No valid version has been deployed to this build! \n\n";

	return;
}

$clone = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app, $_last]);

if (!file_exists ($clone) || !is_dir ($clone))
{
	echo "ERROR > The clone of version/tag '". $_last ."' is missing! \n\n";

	return;
}

if (self::restoring ($_b))
{
	echo "ERROR > Another restore is running on this build! \n\n";

	return;
}

try
{
	$volume = $orchestrator::volume ($clone, $namespace);
}
catch (Exception $e)
{
	echo "ERROR > ". $e->getMessage () ."! \n\n";

	return;
}

echo "INFO > Deployed version: '". $_last ."'. Backup volume: '". $volume ."'. \n";

// --- o que pode ser restaurado ------------------------------------------------------------------------------------
// Volume: os arquivos da MESMA app nos três estágios ('{proj}_{app}_{estágio}_...'). Bucket (com S3 configurado):
// <proj>/<app>/<alpha|beta|release|upload>/<arquivo>. O mesmo arquivo nos dois lados (nome e tamanho) é uma entrada só.

$entries = [];

$add = function ($group, $name, $size, $mtime, $origin) use (&$entries)
{
	$key = $group .'/'. $name;

	if (isset ($entries [$key]) && $entries [$key]['size'] === $size) { $entries [$key]['origins'][$origin] = TRUE; return; }

	if (isset ($entries [$key])) $key .= '#'. $origin;

	$ts = Retention::timestampFromName ($name);

	$entries [$key] = [ 'group' => $group, 'name' => $name, 'size' => $size, 'ts' => $ts !== NULL ? $ts : $mtime, 'dated' => $ts !== NULL, 'origins' => [ $origin => TRUE ] ];
};

try
{
	foreach (BackupVolume::files ($volume) as $f)
		if (preg_match ('/^'. preg_quote ($_b->project .'_'. $_b->app .'_', '/') .'(alpha|beta|release)_/', $f ['name'], $m) && substr ($f ['name'], -7) === '.tar.gz')
			$add ($m [1], $f ['name'], intval ($f ['size']), intval ($f ['mtime']), 'local');
}
catch (Exception $e)
{
	echo "ERROR > ". $e->getMessage () ."! \n\n";

	return;
}

$s3 = Cloud::configured ();

if ($s3)
{
	try
	{
		echo "INFO > Bucket: '". Cloud::url ($appDir) ."' at '". Cloud::value ('S3_ENDPOINT') ."' \n";

		foreach (Cloud::tree ($appDir) as $f)
		{
			$parts = explode ('/', $f ['path']);

			if (sizeof ($parts) !== 2 || !in_array ($parts [0], $GROUPS) || $parts [1] === '' || $parts [1][0] === '.') continue;

			$add ($parts [0], $parts [1], intval ($f ['size']), intval ($f ['mtime']), 's3');
		}
	}
	catch (Exception $e)
	{
		echo "WARNING > ". Cloud::mask ($e->getMessage ()) .". Only the local volume is listed. \n";

		$s3 = FALSE;
	}
}
else
	echo "INFO > The S3 bucket is not configured ('io cloud --setup'): only the local volume is listed. \n";

uasort ($entries, function ($a, $b) { return $b ['ts'] <=> $a ['ts'] ?: strcmp ($b ['name'], $a ['name']); });

$entries = array_values ($entries);

$kind = function ($e)
{
	if (strpos ($e ['name'], Retention::SHIELD_MARK) !== FALSE) return 'before-restore';

	return $e ['dated'] ? 'backup' : 'undated';
};

$table = function () use ($entries, $kind, $orchestrator)
{
	echo "\n";

	echo "  #   ". str_pad ('SOURCE', 10) . str_pad ('GROUP', 9) . str_pad ('KIND', 16) . str_pad ('SIZE', 10) . str_pad ('DATE', 18) ."FILE \n";

	foreach ($entries as $i => $e)
		echo str_pad ($i + 1, 3, ' ', STR_PAD_LEFT) .'  '. str_pad (implode ('+', array_keys ($e ['origins'])), 10) . str_pad ($e ['group'], 9) . str_pad ($kind ($e), 16) . str_pad ($orchestrator::humanSize ($e ['size']), 10) . str_pad (date ('Y-m-d H:i', $e ['ts']), 18) . $e ['name'] ."\n";

	echo "\n";
};

if (!sizeof ($entries))
{
	echo "WARNING > No backup of app '". $appDir ."' found in the volume". ($s3 ? " nor in the bucket" : '') ."! \n\n";

	return;
}

if ($_opts ['list'])
{
	echo "INFO > ". sizeof ($entries) ." file(s) can be restored on '". $_build ."' (newest first; dates in ". date_default_timezone_get () ."): \n";

	$table ();

	return;
}

// --- escolha --------------------------------------------------------------------------------------------------------

if ($_opts ['file'] !== NULL)
{
	// 'nome' ou 'grupo/nome' (para desfazer a ambiguidade de um nome em mais de um grupo, ex.: upload/)
	$want = $_opts ['file'];

	$matches = array_values (array_filter ($entries, function ($e) use ($want, $_opts) {
		return ($e ['name'] === $want || $e ['group'] .'/'. $e ['name'] === $want) && ($_opts ['from'] === NULL || isset ($e ['origins'][$_opts ['from']]));
	}));

	if (!sizeof ($matches))
	{
		echo "ERROR > File '". $want ."' not found". ($_opts ['from'] !== NULL ? " in the ". ($_opts ['from'] === 's3' ? 'bucket' : 'volume') : '') .". Use 'io restore ". $_build ." --list'. \n\n";

		return;
	}

	if (sizeof ($matches) > 1)
	{
		echo "ERROR > File '". $want ."' is in more than one place (". implode (', ', array_map (function ($e) { return $e ['group'] .' / '. implode ('+', array_keys ($e ['origins'])); }, $matches)) ."). Use '--file GROUP/NAME' and, if needed, '--from local|s3'. \n\n";

		return;
	}

	$chosen = $matches [0];
}
else
{
	echo "INFO > Backups that can be restored on '". $_build ."' (newest first; dates in ". date_default_timezone_get () ."): \n";

	$table ();

	$answer = $ask ("Number of the file to restore (empty to cancel): ");

	if ($answer === NULL || $answer === '' || !ctype_digit ($answer) || intval ($answer) < 1 || intval ($answer) > sizeof ($entries))
	{
		echo "\nINFO > Nothing done. \n\n";

		return;
	}

	$chosen = $entries [intval ($answer) - 1];
}

if (!preg_match ($NAME, $chosen ['name']))
{
	echo "ERROR > Invalid file name '". $chosen ['name'] ."': only letters, numbers, '.', '-' and '_', ending in '.tar.gz'. Rename it in the bucket (or in the volume) first. \n\n";

	return;
}

$source = isset ($chosen ['origins']['local']) && $_opts ['from'] !== 's3' ? 'local' : 's3';

echo "INFO > Chosen: '". $chosen ['group'] ."/". $chosen ['name'] ."' (". $orchestrator::humanSize ($chosen ['size']) .", from the ". ($source === 's3' ? 'bucket' : 'local volume') ."). \n";

if ($chosen ['group'] === 'upload')
{
	echo "WARNING > This file was uploaded by hand to the bucket ('upload/'): it must have the same structure as the files generated by the 'backup' service of the app, because the 'restore' service of the app reads it. \n";

	if ($_opts ['yes'] && !$_opts ['upload'])
	{
		echo "ERROR > With '--yes', restoring a file from 'upload/' requires '--accept-upload'. Nothing done. \n\n";

		return;
	}

	if (!$_opts ['upload'] && !$confirm ("Restore a file uploaded by hand?"))
	{
		echo "\nINFO > Nothing done. \n\n";

		return;
	}
}
elseif ($chosen ['group'] !== $_b->stage)
{
	echo "WARNING > This backup is from another stage ('". $chosen ['group'] ."'). ". (in_array ($chosen ['group'], [ 'release' ]) ? "Production data in a test stage may include personal data (LGPD): make sure it is allowed. " : '') ."\n";

	if ($_opts ['yes'] && !$_opts ['cross'])
	{
		echo "ERROR > With '--yes', restoring a file from another stage requires '--accept-cross-stage'. Nothing done. \n\n";

		return;
	}

	if (!$_opts ['cross'] && !$confirm ("Restore a backup of stage '". $chosen ['group'] ."' on stage '". $_b->stage ."'?"))
	{
		echo "\nINFO > Nothing done. \n\n";

		return;
	}
}

if (!$chosen ['dated'])
	echo "WARNING > The file name has no date: the date shown is the modification time. \n";

$expected = $_opts ['sha256'];

if ($expected === NULL && !$_opts ['yes'] && $tty)
{
	$answer = $ask ("SHA-256 of the file, if you have it (empty to skip): ");

	if ($answer !== NULL && $answer !== '')
	{
		if (!preg_match ('/^[0-9a-fA-F]{64}$/', $answer))
		{
			echo "ERROR > Invalid SHA-256: 64 hexadecimal characters. Nothing done. \n\n";

			return;
		}

		$expected = strtolower ($answer);
	}
}

if (!$_opts ['check'] && !$_opts ['yes'])
{
	echo "\n";
	echo "The current data of '". $_build ."' will be REPLACED by '". $chosen ['name'] ."'. A backup of the current data is made first (before-restore)". ($s3 ? " and sent to the bucket" : '') .". \n";

	$typed = $ask ("Type the name of the build to confirm (". $_build ."): ");

	if ($typed !== $_build)
	{
		echo "\nINFO > Nothing done. \n\n";

		return;
	}
}

// --- execução -------------------------------------------------------------------------------------------------------

$stateDir = ($_data ? $_data : '/data') . DIRECTORY_SEPARATOR .'.restore';

if (!file_exists ($stateDir)) mkdir ($stateDir, 0700, TRUE);

$lock = $stateDir . DIRECTORY_SEPARATOR . $namespace .'.lock';
$stateFile = $stateDir . DIRECTORY_SEPARATOR . $namespace .'.json';

file_put_contents ($lock, (string) getmypid (), LOCK_EX);

$state = [
	'build' => $_build,
	'version' => $_last,
	'file' => $chosen ['name'],
	'group' => $chosen ['group'],
	'source' => $source,
	'size' => $chosen ['size'],
	'check' => $_opts ['check'],
	'sha256' => NULL,
	'sha256_expected' => $expected,
	'before' => NULL,
	'status' => 'RUNNING',
	'step' => 'preparing',
	'message' => '',
	'started' => date (DATE_ATOM),
	'finished' => NULL,
	'server' => getenv ('SERVER')
];

$save = function () use (&$state, $stateFile)
{
	try
	{
		file_put_contents ($stateFile, json_encode ($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
	}
	catch (Exception $e)
	{
		echo "WARNING > Unable to record the state of the restore: ". $e->getMessage () ." \n";
	}
};

$step = function ($name) use (&$state, $save)
{
	$state ['step'] = $name;

	$save ();

	echo "\n";

	echo "INFO > Step '". $name ."'... \n";
};

$downloaded = NULL;
$output = [];

$save ();

try
{
	// before-restore: o backup da build agora, marcado e fora da rotação por 30 dias
	if (!$_opts ['check'])
	{
		$step ('before-restore');

		list ($own) = BackupVolume::split (BackupVolume::files ($volume), $namespace);

		$known = array_column ($own, 'name');

		$orchestrator::backup ($clone, $namespace);

		$generated = NULL;

		for ($try = 0; $try < 3 && $generated === NULL; $try++)
		{
			if ($try) sleep (2);

			list ($own) = BackupVolume::split (BackupVolume::files ($volume), $namespace);

			$new = array_values (array_filter ($own, function ($f) use ($known) { return !in_array ($f ['name'], $known) && substr ($f ['name'], -7) === '.tar.gz'; }));

			usort ($new, function ($a, $b) { return $b ['mtime'] <=> $a ['mtime']; });

			if (sizeof ($new)) $generated = $new [0];
		}

		if ($generated === NULL)
			throw new Exception ("The backup service ran, but no new .tar.gz of this build was found in the volume: nothing was restored");

		$before = preg_match ('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', $generated ['name'])
			? preg_replace ('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', Retention::SHIELD_MARK .'$1.tar.gz', $generated ['name'])
			: preg_replace ('/\.tar\.gz$/', '', $generated ['name']) . Retention::SHIELD_MARK . gmdate ('Y-m-d_H-i-s') .'.tar.gz';

		BackupVolume::rename ($volume, $generated ['name'], $before);

		$state ['before'] = $before;

		echo "INFO > Backup before the restore: '". $before ."' (". $orchestrator::humanSize ($generated ['size']) ."), kept out of the rotation for ". Retention::SHIELD_DAYS ." days. \n";

		if ($s3)
		{
			$dir = $appDir .'/'. $_b->stage;

			Cloud::upload ($volume, $dir, [ $before ]);

			$present = array_column (Cloud::files ($dir), 'size', 'name');

			if (!isset ($present [$before]) || intval ($present [$before]) !== intval ($generated ['size']))
				throw new Exception ("The backup before the restore is missing (or has a different size) in the bucket: nothing was restored");

			echo "INFO > Sent to '". Cloud::url ($dir) ."'. \n";
		}

		$save ();
	}

	$file = $chosen ['name'];

	// download (só da origem S3)
	if ($source === 's3')
	{
		$step ('download');

		$free = BackupVolume::free ($volume);

		if ($free < 2 * $chosen ['size'])
			throw new Exception ("Not enough space in the backup volume: ". $orchestrator::humanSize ($free) ." free, ". $orchestrator::humanSize (2 * $chosen ['size']) ." needed (twice the file): nothing was restored");

		// nunca sobrescreve um arquivo do volume com o mesmo nome (ex.: um de upload/ ou de outro servidor)
		$present = array_column (BackupVolume::files ($volume), 'size', 'name');

		if (isset ($present [$file]))
			$file = preg_replace ('/\.tar\.gz$/', '', $file) .'.restore-'. gmdate ('Ymd-His') .'.tar.gz';

		Cloud::download ($volume, $appDir .'/'. $chosen ['group'], $chosen ['name'], $file);

		$downloaded = $file;

		$present = array_column (BackupVolume::files ($volume), 'size', 'name');

		if (!isset ($present [$file]) || intval ($present [$file]) !== intval ($chosen ['size']))
			throw new Exception ("The downloaded file has a different size than in the bucket: nothing was restored");

		echo "INFO > Downloaded to the volume as '". $file ."'. \n";
	}

	// verify: SHA-256 e tar.gz numa passada só
	$step ('verify');

	$inspect = BackupVolume::inspect ($volume, $file);

	$state ['sha256'] = $inspect ['sha256'];

	echo "INFO > SHA-256: ". $inspect ['sha256'] ." \n";

	if ($expected !== NULL && $expected !== $inspect ['sha256'])
		throw new Exception ("SHA-256 does not match: expected ". $expected .", got ". $inspect ['sha256'] .": nothing was restored");

	if (!$inspect ['tar'])
		throw new Exception ("The file is not a valid tar.gz (truncated or corrupted?)". ($inspect ['error'] !== '' ? ': '. $inspect ['error'] : '') .": nothing was restored");

	echo "INFO > The tar.gz is readable". ($expected !== NULL ? " and the SHA-256 matches" : '') .". \n";

	if ($_opts ['check'])
	{
		$state ['status'] = 'DONE';
		$state ['message'] = 'Check only: the file is valid; nothing was restored.';
	}
	else
	{
		$step ('restore');

		$output = $orchestrator::restore ($clone, $namespace, $file);

		$state ['status'] = 'DONE';
		$state ['message'] = 'Restored from '. $chosen ['group'] .'/'. $chosen ['name'] .' ('. $source .'). Backup before the restore: '. $state ['before'] .'.';
	}
}
catch (Exception $e)
{
	$state ['status'] = 'ERROR';
	$state ['message'] = Cloud::mask ($e->getMessage ()) . ($state ['before'] !== NULL && $state ['step'] === 'restore' ? '. To revert, restore '. $state ['before'] : '');
}
finally
{
	if ($downloaded !== NULL)
	{
		try
		{
			BackupVolume::delete ($volume, [ $downloaded ]);
		}
		catch (Exception $e)
		{
			echo "WARNING > Unable to delete the downloaded file '". $downloaded ."' from the volume: ". $e->getMessage () ." \n";
		}
	}

	$state ['finished'] = date (DATE_ATOM);

	$save ();

	try { if (file_exists ($lock)) unlink ($lock); } catch (Exception $e) {}
}

echo "\n";

if ($state ['status'] === 'DONE')
	echo "SUCCESS > ". $state ['message'] ." \n";
else
	echo "ERROR > Restore of build '". $_build ."' failed at step '". $state ['step'] ."': ". $state ['message'] ."! \n";

if (!$_opts ['check'])
{
	$body = implode ("\n", [
		($state ['status'] === 'DONE' ? 'Restore completed.' : 'Restore FAILED at step \''. $state ['step'] .'\'.'),
		'',
		'Build: '. $_build .' (version '. $_last .')',
		'Server: '. getenv ('SERVER'),
		'File: '. $chosen ['group'] .'/'. $chosen ['name'] .' ('. $orchestrator::humanSize ($chosen ['size']) .', from the '. ($source === 's3' ? 'bucket' : 'local volume') .')',
		'SHA-256: '. ($state ['sha256'] ?? '-'),
		'Backup before the restore: '. ($state ['before'] ?? '-'),
		'Started: '. $state ['started'],
		'Finished: '. $state ['finished'],
		'',
		$state ['message'],
		'',
		sizeof ($output) ? "Last lines of the 'restore' service:\n\n". implode ("\n", $output) : ''
	]);

	Mail::singleton ()->send ($_build .' - RESTORE '. ($state ['status'] === 'DONE' ? 'SUCCESS' : 'ERROR'), $body, isset ($_b->team) && is_array ($_b->team) ? $_b->team : []);
}
