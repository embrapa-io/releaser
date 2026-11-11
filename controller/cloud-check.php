<?php

/**
 * `io cloud --check [PROJETO]`: testa o acesso aos buckets da cópia em nuvem, com a listagem e a gravação (um
 * upload multipart aberto e abortado, que não deixa nada no bucket), como o `io check`, mas só o S3.
 *
 * Sem projeto: cada configuração em uso, a do servidor (S3_* no .env) e a própria de cada projeto com arquivo em
 * /data/s3/ (Cloud::check). Com o projeto: a configuração que as builds dele usam, a própria ou a do servidor,
 * com a gravação testada na pasta dele, com a cópia ligada ou não (Cloud::checkProject).
 *
 * Variáveis do escopo: $_project (NULL ou o nome do projeto), $_data.
 */

$prefix = [ 'OK' => 'SUCCESS', 'WARNING' => 'WARNING', 'ERROR' => 'ERROR' ];

echo "\n";

if ($_project === NULL)
{
	$check = Cloud::check ($_data);

	if ($check === NULL)
	{
		echo "ERROR > The S3 bucket is not configured: there is no S3_* in the '.env' file and no settings of a project in '". dirname (Cloud::file ('project')) ."'. Run 'io cloud --setup'. \n";

		return;
	}

	foreach ($check ['scopes'] as $scope)
	{
		echo "INFO > ". Cloud::describe ($scope) ."... \n";

		echo $prefix [$scope ['status']] ." > S3: ". $scope ['message'] ." \n";

		echo "\n";
	}

	return;
}

if (!preg_match (Cloud::PROJECT, $_project))
{
	echo "ERROR > Invalid project name '". $_project ."'. Use the unix name of the project: io cloud --check my-project \n";

	return;
}

// builds do projeto neste servidor, com a situação da cópia diária de cada uma
$builds = [];

foreach (S3Probe::builds ($_data) as $b)
	if ($b->project === $_project)
		$builds [] = $b->project .'/'. $b->app .'@'. $b->stage .' ('. (isset ($b->auto) && is_object ($b->auto) && !empty ($b->auto->cloud) ? 'auto.cloud on' : 'auto.cloud off') .')';

if (!sizeof ($builds) && Cloud::own ($_project) === NULL)
{
	echo "ERROR > There is no build of project '". $_project ."' on this server. \n";

	return;
}

if (sizeof ($builds))
	echo "INFO > Builds of project '". $_project ."' on this server: ". implode (', ', $builds) .". \n";
else
	echo "WARNING > There is no build of project '". $_project ."' on this server, only its S3 settings. \n";

$scope = Cloud::checkProject ($_data, $_project);

if ($scope === NULL)
{
	echo "ERROR > The S3 bucket is not configured for project '". $_project ."': it has no settings of its own and there is no S3_* in the '.env' file. Run 'io cloud --setup ". $_project ."' (or 'io cloud --setup', for all builds of this server). \n";

	return;
}

if ($scope ['project'] === NULL)
	echo "INFO > Project '". $_project ."' has no S3 settings of its own: its builds use the ones of the server. \n";

echo "INFO > ". Cloud::describe ($scope) ."... \n";

echo $prefix [$scope ['status']] ." > S3: ". $scope ['message'] ." \n";
