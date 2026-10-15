<?php

/**
 * Migra o /data/builds.json (legado, todas as builds num arquivo só) para
 * /data/builds/<projeto>_<app>_<estágio>.json, um arquivo por build. O
 * original vira builds.json.bkp. Builds que já existam em builds/ não são
 * sobrescritas: ficam no .bkp e são apontadas no final.
 */

$legacy = $_data . DIRECTORY_SEPARATOR .'builds.json';

if (!file_exists ($legacy))
{
	echo "INFO > There is no '". $legacy ."' to migrate: builds are already in '". $_data . DIRECTORY_SEPARATOR ."builds/'. \n";

	return;
}

$loaded = json_decode ((string) file_get_contents ($legacy));

if (!is_array ($loaded))
	throw new Exception ("Malformed JSON in '". $legacy ."'");

$dir = $_data . DIRECTORY_SEPARATOR .'builds';

if (!file_exists ($dir) && !@mkdir ($dir, 0755, TRUE))
	throw new Exception ("Impossible to create directory '". $dir ."'");

$written = 0;
$skipped = [];

foreach ($loaded as $i => $b)
{
	if (!is_object ($b) || !isset ($b->project, $b->app, $b->stage) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $b->app) || !in_array ($b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Entry #". ($i + 1) ." of builds.json is invalid (project, app or stage): skipped. \n";

		$skipped [] = '#'. ($i + 1);

		continue;
	}

	$file = $dir . DIRECTORY_SEPARATOR . $b->project .'_'. $b->app .'_'. $b->stage .'.json';

	if (file_exists ($file))
	{
		echo "WARNING > '". basename ($file) ."' already exists: kept as is (the entry of builds.json was NOT applied). \n";

		$skipped [] = $b->project .'/'. $b->app .'@'. $b->stage;

		continue;
	}

	if (file_put_contents ($file, json_encode ($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ."\n", LOCK_EX) === FALSE)
		throw new Exception ("Impossible to write '". $file ."'");

	echo "INFO > ". $b->project .'/'. $b->app .'@'. $b->stage ." -> builds/". basename ($file) ." \n";

	$written++;
}

if (!rename ($legacy, $legacy .'.bkp'))
	throw new Exception ("Impossible to rename '". $legacy ."' to '". basename ($legacy) .".bkp'");

echo "\n";

echo "SUCCESS > ". $written ." build(s) migrated to '". $dir ."'. The old file was kept as 'builds.json.bkp'. \n";

if (sizeof ($skipped))
	echo "WARNING > Not migrated (still only in builds.json.bkp): ". implode (', ', $skipped) ." \n";
