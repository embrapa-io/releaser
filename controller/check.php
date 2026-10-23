<?php

$check = GitCheck::run ($_data, TRUE);

$prefix = [ 'OK' => 'SUCCESS', 'WARNING' => 'WARNING', 'ERROR' => 'ERROR' ];

echo "\n";

echo "INFO > GitLab API at ". GitCheck::url () ." (GITLAB_URL)... \n";

echo $prefix [$check ['api']['status']] ." > API: ". $check ['api']['message'] ." \n";

if ($check ['token'])
{
	$t = $check ['token'];

	if (isset ($t ['name']))
	{
		echo "INFO > Token name: ". ($t ['name'] != '' ? $t ['name'] : '?') ." \n";
		echo "INFO > Token scopes: ". implode (', ', $t ['scopes']) ." \n";
		echo "INFO > Token expiration: ". ($t ['expires'] ? $t ['expires'] : 'never') ." \n";
		echo "INFO > Token last used at: ". ($t ['used'] ? $t ['used'] : '?') ." \n";
	}

	echo $prefix [$t ['status']] ." > Token (GITLAB_TOKEN): ". $t ['message'] ." \n";

	if ($t ['status'] != 'OK')
		echo "INFO > Generate a new token at ". GitCheck::url () ."/-/user_settings/personal_access_tokens (scope 'read_api'), put it in GITLAB_TOKEN at /data/.env and run 'io check' again. \n";
}
else
	echo "WARNING > Token (GITLAB_TOKEN): not checked, the API did not answer \n";

echo "\n";

echo "INFO > Clone access (GITLAB_SSH)... \n";

echo $prefix [$check ['clone']['status']] ." > ". $check ['clone']['via'] .": ". $check ['clone']['message'] ." \n";

echo "\n";

// Cópia em nuvem: só quando alguma variável S3_* está no .env (ver class/Cloud.php).
if ($check ['cloud'])
{
	echo "INFO > S3 bucket '". (Cloud::value ('S3_BUCKET') !== '' ? Cloud::value ('S3_BUCKET') : '?') ."' at ". (Cloud::value ('S3_ENDPOINT') !== '' ? Cloud::value ('S3_ENDPOINT') : '?') ." (S3_ENDPOINT)... \n";

	echo $prefix [$check ['cloud']['status']] ." > S3: ". $check ['cloud']['message'] ." \n";

	echo "\n";
}

echo GitCheck::summary ($check, Menu::plain ($_daemon)) ."\n";

// No daemon (diário), e-mail só quando há o que fazer: token vencido, revogado,
// sem escopo ou perto de vencer, clone recusado ou bucket S3 inacessível (no
// máximo um e-mail por dia). GitLab fora do ar por um momento não vira e-mail
// (o deploy do próximo ciclo já avisa se persistir).
if ($_daemon && GitCheck::alert ($check))
	Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - '. (GitCheck::alert ($check, FALSE) ? 'GITLAB' : 'S3 BUCKET') .' ACCESS WARNING', mailBody ());
