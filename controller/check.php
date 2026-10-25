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

// Versão do Releaser: há uma mais nova publicada? (consulta agora; falha de rede não é erro)
$check ['release'] = Upgrade::check ($_data);

echo "INFO > Releaser version (". Upgrade::repository () .")... \n";

echo $prefix [$check ['release']['status']] ." > Releaser: ". $check ['release']['message'] ." \n";

echo "\n";

echo GitCheck::summary ($check, Menu::plain ($_daemon)) ."\n";

// No daemon (diário), e-mail só quando há o que fazer: token vencido, revogado,
// sem escopo ou perto de vencer, clone recusado ou bucket S3 inacessível (no
// máximo um e-mail por dia). GitLab fora do ar por um momento não vira e-mail
// (o deploy do próximo ciclo já avisa se persistir).
// Versão nova do Releaser: e-mail uma vez por versão (marca em /data/.update/latest.json), nunca diário.
$update = $_daemon && isset ($check ['release']['version']) && Upgrade::notify ($_data, $check ['release']['version']);

if ($update)
	echo "\nINFO > To upgrade, run on the server: docker exec -it ". (getenv ('ORCHESTRATOR') == 'DockerSwarm' ? '$(docker ps -q -f name=releaser)' : 'releaser') ." io upgrade (or 'io upgrade', with the 'io' command installed by 'io cli'). Notes of each version: https://github.com/embrapa-io/releaser#vers%C3%B5es \n";

if ($_daemon && (GitCheck::alert ($check) || $update))
{
	$subject = [];

	if (GitCheck::alert ($check)) $subject [] = (GitCheck::alert ($check, FALSE) ? 'GITLAB' : 'S3 BUCKET') .' ACCESS WARNING';

	if ($update) $subject [] = 'UPDATE AVAILABLE ('. $check ['release']['version'] .')';

	Mail::singleton ()->send ('Releaser at '. getenv ('SERVER') .' - '. implode (' and ', $subject), mailBody ());
}
