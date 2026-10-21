<?php

$check = GitCheck::run ($_data);

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

echo GitCheck::summary ($check) ."\n";

// No daemon (diário), e-mail só quando há o que fazer: token vencido, revogado,
// sem escopo ou perto de vencer, ou clone recusado. GitLab fora do ar por um
// momento não vira e-mail (o deploy do próximo ciclo já avisa se persistir).
if ($_daemon && GitCheck::alert ($check))
	Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - GITLAB ACCESS WARNING', mailBody ());
