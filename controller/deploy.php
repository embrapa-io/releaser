<?php

$git = GitLab::singleton ();

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

echo "INFO > Checking status of ". sizeof ($_builds) ." builds configured... \n";

foreach ($_builds as $_build => $_b)
{
	if ($_daemon && !$_b->auto->deploy) continue;

	echo "\n";

	echo "=== ". $_build ." === \n\n";

	echo "INFO > Checking if build '". $_build ."' has new tags... \n";

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
	{
		echo "ERROR > Invalid build name! \n\n";

		$_nothing = FALSE;

		continue;
	}

	if (!intval ($_b->matomo->id)) echo "WARNING > Configure a valid Matomo ID! \n";

	if (!preg_match ('/^https:\/\/[a-f0-9]{32}@bug\.embrapa\.io\/[0-9]+$/', $_b->sentry->dsn))
		echo "WARNING > Configure a valid Sentry DSN! \n";

	try
	{
		$project = $git->projectSearch ($_b->project);
	}
	catch (Exception $e)
	{
		$project = [];
	}

	if (!sizeof ($project))
	{
		echo "ERROR > Project '". $_b->project ."' not found! \n\n";

		$_nothing = FALSE;

		continue;
	}

	$projectId = $project ['id'];

	try
	{
		$load = $git->reposSearch ($_b->project .'/'. $_b->app);
	}
	catch (Exception $e)
	{
		$load = [];
	}

	if (!sizeof ($load))
	{
		echo "ERROR > Repository '". $_b->project .'/'. $_b->app ."' not found! \n\n";

		$_nothing = FALSE;

		continue;
	}

	$repos = $load [0];

	try
	{
		$tags = $git->reposTags ($repos ['id']);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossible to get repository tags! ". $e->getMessage () ."! \n\n";

		$_nothing = FALSE;

		continue;
	}

	$_tags = [];

	$_newer = [ 'score' => 0 ];

	foreach ($tags as $trash => $tag)
		if (preg_match (self::VERSION_REGEX [$_b->stage], $tag ['name']))
		{
			$tag ['score'] = self::score ($_b->stage, $tag ['name']);

			if ($tag ['score'] > $_newer ['score'])
				$_newer = $tag;

			$_tags [] = $tag;
		}

	if (!sizeof ($_tags) || !$_newer ['score'])
	{
		echo "INFO > No tags found! \n\n";

		continue;
	}

	$version = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

	$aux = dirname ($version);

	if (!file_exists ($aux) || !is_dir ($aux)) mkdir ($aux, 0777, TRUE);

	try
	{
		$_last = $_force ? NULL : trim (file_get_contents ($version));
	}
	catch (Exception $e)
	{
		$_last = NULL;
	}

	if (is_string ($_last) && $_last !== '' && self::score ($_b->stage, $_last) >= $_newer ['score'])
	{
		echo "INFO > No new tags found! \n\n";

		continue;
	}

	$_nothing = FALSE;

	if ($_daemon) ob_start ();

	echo "INFO > New tag found to build '". $_build ."'! \n";

	if ($_last)
		echo "INFO > Updating from ". $_last ." to ". $_newer ['name'];
	else
		echo "INFO > Deploying it's first version ". $_newer ['name'];

	echo ", commited by ". $_newer ['commit']['author_name'] ." <". $_newer ['commit']['author_email'] ."> at ". $_newer ['commit']['created_at'] .". \n";

	echo "INFO > Checking if new tag has been created from branch '". $_b->stage ."'... ";

	try
	{
		$refs = $git->commitRefs ($repos ['id'], $_newer ['commit']['id']);
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossible to get refs to tag ". $_newer ['name'] ." (commit '". $_newer ['commit']['id'] ."')! ". $e->getMessage () ."! \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	$checkBranch = FALSE;

	foreach ($refs as $trash => $ref)
	{
		if ($ref ['type'] != 'branch' || $ref ['name'] != $_b->stage) continue;

		$checkBranch = TRUE;

		break;
	}

	if (!$checkBranch)
	{
		echo "error! \n";

		echo "ERROR > New tag ". $_newer ['name'] ." has been not created from branch '". $_b->stage ."'! Please, delete this tag and re-create from correct branch. \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	echo "ok! \n";

	try
	{
		$milestones = $git->projectMilestones ($projectId);
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossible to get milestones! ". $e->getMessage () ."! \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	$milestone = explode ('-', $_newer ['name'])[0];

	$checkMilestone = FALSE;

	foreach ($milestones as $trash => $m)
	{
		if ($m ['title'] != $milestone) continue;

		$checkMilestone = TRUE;

		break;
	}

	if (!$checkMilestone)
		echo "WARNING > No related milestone found! Has good practice, a milestone named '". $milestone ."' is required to manage issues releated to this release. \n";

	try
	{
		echo "INFO > Trying to load '.embrapa/settings.json' from remote repository... ";

		$_embrapa = json_decode ($git->getFile ($repos ['id'], '.embrapa/settings.json'));

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to get repository settings ('.embrapa/settings.json'). ". $e->getMessage () ."! \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	echo "INFO > New version will be deployed using orchestrator '". self::singleton ()->orchestrator ."' at server '". getenv ('SERVER') ."'! \n";

	$replace = [
		'%SERVER%' => getenv ('SERVER'),
		'%STAGE%' => $_b->stage,
		'%PROJECT_UNIX%'=> $_b->project,
		'%APP_UNIX%' => $_b->app,
		'%VERSION%' => $_newer ['name'],
		'%DEPLOYER%' => $_newer ['commit']['author_email'],
		'%SENTRY_DSN%' => $_b->sentry->dsn,
		'%MATOMO_ID%' => $_b->matomo->id,
		'%MATOMO_TOKEN%' => $_b->matomo->token
	];

	$ci = '';
	$bk = '';

	foreach (self::singleton ()->environment as $name => $value)
	{
		$line = $name .'='. str_replace (array_keys ($replace), array_values ($replace), $value) ."\n";

		$bk .= $name != 'COMPOSE_PROFILES' ? $line : "COMPOSE_PROFILES=cli\n";
		$ci .= $line;
	}

	echo "INFO > CI/DI environment variables: \n\n". $ci ."\n";

	$env = '';

	foreach ($_b->env as $variable => $value)
		$env .= $variable .'='. $value ."\n";

	if (strpos ($env, ' ') !== false)
	{
		echo "ERROR > Environment variables can not contain spaces! Check attribute 'env' at file 'builds.json'. \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	echo "INFO > Trying to clone app... ";

	unset ($clone);

	try
	{
		$clone = GitClient::singleton ()->cloneTag ($_b->project, $_b->app, $_b->stage, $_newer ['name'], $ci, $bk, $env, $_apps);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		echo "ERROR > Impossibe to clone repository. ". $e->getMessage () ."! \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	try
	{
		(self::singleton ()->orchestrator)::deploy ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
	}
	catch (Exception $e)
	{
		echo "ERROR > Impossibe to deploy tag ". $_newer ['name'] .". ". $e->getMessage () ."! \n\n";

		if ($_daemon) Mail::singleton ()->send ($_build .' - RELEASE ERROR', mailBody (), $_b->team);

		continue;
	}

	file_put_contents ($version, $_newer ['name'], LOCK_EX);

	try
	{
		@unlink ($_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.rollback'. DIRECTORY_SEPARATOR . $_b->stage);
	}
	catch (Exception $e)
	{}

	echo "SUCCESS > All done! Version '". $_newer ['name'] ."' in '". $_b->stage ."' stage of application '". $_b->project ."/". $_b->app ."' is DEPLOYED! \n";

	if ($_daemon) Mail::singleton ()->send ($_build .' '. $_newer ['name'] .' - RELEASE SUCCESS', mailBody (), $_b->team);
}
