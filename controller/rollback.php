<?php

$git = GitLab::singleton ();

$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

try
{
	if (!is_array ($_builds) || sizeof ($_builds) != 1)
		throw new Exception ('Invalid build name!');

	$_b = reset ($_builds);
	$_build = key ($_builds);

	if (!preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->project) || !preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $_b->app) || !in_array ($_b->stage, [ 'alpha', 'beta', 'release' ]))
		throw new Exception ('Invalid build name!');

	if (!self::score ($_b->stage, $_version))
		throw new Exception ('Invalid version!');

	$version = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.version'. DIRECTORY_SEPARATOR . $_b->stage;

	$aux = dirname ($version);

	if (!file_exists ($aux) || !is_dir ($aux)) mkdir ($aux, 0777, TRUE);

	try
	{
		$_last = trim (file_get_contents ($version));
	}
	catch (Exception $e)
	{
		$_last = NULL;
	}

	if (is_null ($_last) || $_last == '')
		throw new Exception ("No version deployed! Use the 'deploy' command instead!");

	if ($_last == $_version)
		throw new Exception ('Version "'. $_version .'" is already deployed!');

	$rollback = $_apps . DIRECTORY_SEPARATOR . implode (DIRECTORY_SEPARATOR, [$_b->project, $_b->app]) . DIRECTORY_SEPARATOR .'.rollback'. DIRECTORY_SEPARATOR . $_b->stage;

	try
	{
		$_rollback = trim (file_get_contents ($rollback));
	}
	catch (Exception $e)
	{
		$aux = dirname ($rollback);

		if (!file_exists ($aux) || !is_dir ($aux)) mkdir ($aux, 0777, TRUE);

		$_rollback = NULL;
	}

	if ($_rollback == $_version)
		throw new Exception ('Version "'. $_version .'" is already the rolled-back version!');

	$_apps = $_data . DIRECTORY_SEPARATOR .'apps';

	echo "INFO > Trying to roll back ". $_build ." from version ". $_last ." to ". $_version ."... \n";

	echo "INFO > Checking tags... \n";

	try
	{
		$project = $git->projectSearch ($_b->project);
	}
	catch (Exception $e)
	{
		$project = [];
	}

	if (!sizeof ($project))
		throw new Exception ("Project '". $_b->project ."' not found!");

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
		throw new Exception ("Repository '". $_b->project .'/'. $_b->app ."' not found!");

	$repos = $load [0];

	try
	{
		$tags = $git->reposTags ($repos ['id']);
	}
	catch (Exception $e)
	{
		throw new Exception ("Unable to get the repository tags! ". $e->getMessage () ."!");
	}

	$match = FALSE;

	foreach ($tags as $trash => $tag)
	{
		if (!preg_match (self::VERSION_REGEX [$_b->stage], $tag ['name'])) continue;

		if ($tag ['name'] != $_version) continue;

		$match = TRUE;

		break;
	}

	if (!$match)
		throw new Exception ('Version '. $_version .' not found as a tag in branch '. $_b->stage .'!');

	echo "INFO > Deploying version ". $_version .", committed by ". $tag ['commit']['author_name'] ." <". $tag ['commit']['author_email'] ."> at ". $tag ['commit']['created_at'] .". \n";

	echo "INFO > Checking if the version was created from branch '". $_b->stage ."'... \n";

	try
	{
		$refs = $git->commitRefs ($repos ['id'], $tag ['commit']['id']);
	}
	catch (Exception $e)
	{
		throw new Exception ("Unable to get the refs of tag ". $tag ['name'] ." (commit '". $tag ['commit']['id'] ."')! ". $e->getMessage () ."!");
	}

	$checkBranch = FALSE;

	foreach ($refs as $trash => $ref)
	{
		if ($ref ['type'] != 'branch' || $ref ['name'] != $_b->stage) continue;

		$checkBranch = TRUE;

		break;
	}

	if (!$checkBranch)
		throw new Exception ("Tag ". $tag ['name'] ." was not created from branch '". $_b->stage ."'!");

	try
	{
		$milestones = $git->projectMilestones ($projectId);
	}
	catch (Exception $e)
	{
		throw new Exception ("Unable to get milestones! ". $e->getMessage () ."!");
	}

	$milestone = explode ('-', $tag ['name'])[0];

	$checkMilestone = FALSE;

	foreach ($milestones as $trash => $m)
	{
		if ($m ['title'] != $milestone) continue;

		$checkMilestone = TRUE;

		break;
	}

	if (!$checkMilestone)
		echo "WARNING > No related milestone found! As a good practice, a milestone named '". $milestone ."' is required to manage the issues related to this release. \n";

	try
	{
		echo "INFO > Trying to load '.embrapa/settings.json' from remote repository... ";

		$_embrapa = json_decode ($git->getFile ($repos ['id'], '.embrapa/settings.json'));

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		throw new Exception ("Unable to get the repository settings ('.embrapa/settings.json'). ". $e->getMessage () ."!");
	}

	echo "INFO > The rolled-back version will be deployed using orchestrator '". self::singleton ()->orchestrator ."' on server '". getenv ('SERVER') ."'! \n";

	$replace = [
		'%SERVER%' => getenv ('SERVER'),
		'%STAGE%' => $_b->stage,
		'%PROJECT_UNIX%'=> $_b->project,
		'%APP_UNIX%' => $_b->app,
		'%VERSION%' => $tag ['name'],
		'%DEPLOYER%' => $tag ['commit']['author_email'],
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

	echo "INFO > CI/CD environment variables: \n\n". $ci ."\n";

	$env = '';

	foreach ($_b->env as $variable => $value)
		$env .= $variable .'='. $value ."\n";

	if (strpos ($env, ' ') !== false)
		throw new Exception ("Environment variables cannot contain spaces! Check the 'env' attribute in the build file.");

	echo "INFO > Trying to clone the app... ";

	unset ($clone);

	try
	{
		$clone = GitClient::singleton ()->cloneTag ($_b->project, $_b->app, $_b->stage, $tag ['name'], $ci, $bk, $env, $_apps);

		echo "done! \n";
	}
	catch (Exception $e)
	{
		echo "error! \n";

		throw new Exception ("Unable to prepare the app from the repository. ". $e->getMessage () ."!");
	}

	try
	{
		(self::singleton ()->orchestrator)::deploy ($clone, implode ('_', [$_b->project, $_b->app, $_b->stage]));
	}
	catch (Exception $e)
	{
		throw new Exception ("Unable to deploy tag ". $tag ['name'] .". ". $e->getMessage () ."!");
	}

	file_put_contents ($rollback, $tag ['name'], LOCK_EX);

	echo "SUCCESS > All done! Version '". $tag ['name'] ."' of build '". $_build ."' is DEPLOYED! \n";
}
catch (Exception $e)
{
	echo "ERROR > ". $e->getMessage () ."\n";
}
