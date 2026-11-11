<?php
/**
 * Testes da configuração do bucket S3 por projeto (class/Cloud.php): o arquivo
 * /data/s3/<projeto>.json no lugar das S3_* do /data/.env, a herança da
 * retenção, o arquivo inválido (nunca cai na configuração do servidor), a
 * gravação com modo 600, as mensagens, a máscara dos segredos e a linha de
 * comando usada pelo assistente do bin/io. Sem rede: o acesso ao bucket é
 * coberto por tests/cloud-integration.sh.
 *
 * Uso: php tests/cloud.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'Cloud.php';

$failures = 0;
$total = 0;

function check ($label, $condition, $detail = '')
{
	global $failures, $total;

	$total++;

	if ($condition) { echo "ok   ". $label ."\n"; return; }

	$failures++;

	echo "FAIL ". $label . ($detail !== '' ? "\n     ". $detail : '') ."\n";
}

// /data de mentira; a Cloud o lê da global $_data, como no run.php
$_data = sys_get_temp_dir () .'/cloud-'. bin2hex (random_bytes (4));

mkdir ($_data .'/builds', 0700, TRUE);

$server = [
	'S3_ENDPOINT' => 'https://s3.server.example',
	'S3_BUCKET' => 'backup',
	'S3_ACCESS_KEY' => 'server-access-key',
	'S3_SECRET_KEY' => 'server-secret-0123456789',
	'S3_REGION' => 'sa-east-1',
	'S3_RETENTION' => '5/4/3/2',
	'RETENTION' => '7/4/3/1'
];

foreach ($server as $name => $value) putenv ($name .'='. $value);

// --- só a configuração do servidor ---------------------------------------------------------------------------------

Cloud::select ('pasto-inteligente');

check ('sem arquivo: escopo é o do servidor', Cloud::scope () === NULL);
check ('sem arquivo: valores do .env', Cloud::value ('S3_BUCKET') === 'backup' && Cloud::value ('S3_SECRET_KEY') === 'server-secret-0123456789');
check ('sem arquivo: origem e nome do ajuste', Cloud::source () === "the '.env' file" && Cloud::label ('S3_BUCKET') === 'S3_BUCKET');
check ('sem arquivo: configurado', Cloud::configured () && Cloud::missing () === []);
check ('sem arquivo: retenção S3_RETENTION', Cloud::retention () === [ 'spec' => '5/4/3/2', 'where' => "S3_RETENTION in the '.env' file" ]);
check ('nenhum projeto com arquivo', Cloud::projects () === []);

// --- configuração própria ------------------------------------------------------------------------------------------

Cloud::save ('pasto-inteligente', [
	'S3_ENDPOINT' => ' https://s3.cnpxx.example ',
	'S3_BUCKET' => 'acervo',
	'S3_ACCESS_KEY' => 'pasto-inteligente',
	'S3_SECRET_KEY' => 'project-secret-abcdefghij',
	'S3_REGION' => '',
	'S3_RETENTION' => ''
]);

$file = $_data .'/s3/pasto-inteligente.json';

check ('save: arquivo no lugar certo', Cloud::file ('pasto-inteligente') === $file && file_exists ($file));
check ('save: modo 600 no arquivo e 700 na pasta', (fileperms ($file) & 0777) === 0600 && (fileperms (dirname ($file)) & 0777) === 0700, sprintf ('%o %o', fileperms ($file) & 0777, fileperms (dirname ($file)) & 0777));
check ('save: sem sobra do arquivo temporário', !file_exists ($file .'.tmp'));

$json = json_decode (file_get_contents ($file), TRUE);

check ('save: campos em minúsculas, aparados, sem os vazios', $json === [ 'endpoint' => 'https://s3.cnpxx.example', 'bucket' => 'acervo', 'access_key' => 'pasto-inteligente', 'secret_key' => 'project-secret-abcdefghij' ], json_encode ($json));

check ('com arquivo: escopo é o projeto', Cloud::scope () === 'pasto-inteligente');
check ('com arquivo: valores do projeto', Cloud::value ('S3_ENDPOINT') === 'https://s3.cnpxx.example' && Cloud::value ('S3_BUCKET') === 'acervo' && Cloud::value ('S3_SECRET_KEY') === 'project-secret-abcdefghij');
check ('com arquivo: região ausente não herda a do servidor', Cloud::value ('S3_REGION') === '');
check ('com arquivo: retenção ausente herda a S3_RETENTION do servidor', Cloud::value ('S3_RETENTION') === '5/4/3/2' && Cloud::retention () ['where'] === "S3_RETENTION in the '.env' file");
check ('com arquivo: variável que não é do S3 segue no .env', Cloud::value ('RETENTION') === '7/4/3/1');
check ('com arquivo: origem e nome do ajuste', strpos (Cloud::source (), $file) !== FALSE && strpos (Cloud::source (), "project 'pasto-inteligente'") !== FALSE && Cloud::label ('S3_BUCKET') === "'bucket'");
check ('com arquivo: ambiente do rclone com a chave do projeto', class_exists ('Cloud') && Cloud::env () ['RCLONE_CONFIG_S3_ACCESS_KEY_ID'] === 'pasto-inteligente' && Cloud::env () ['RCLONE_CONFIG_S3_REGION'] === 'auto');
check ('com arquivo: caminho no bucket do projeto', Cloud::path ('pasto-inteligente/api/release') === 'S3:acervo/pasto-inteligente/api/release');

putenv ('S3_RETENTION=');

check ('retenção: sem S3_RETENTION, a RETENTION', Cloud::retention () === [ 'spec' => '7/4/3/1', 'where' => "RETENTION in the '.env' file" ]);

putenv ('S3_RETENTION=5/4/3/2');

Cloud::save ('pasto-inteligente', [ 'S3_ENDPOINT' => 'https://s3.cnpxx.example', 'S3_BUCKET' => 'acervo', 'S3_ACCESS_KEY' => 'pasto-inteligente', 'S3_SECRET_KEY' => 'project-secret-abcdefghij', 'S3_REGION' => 'us-east-1', 'S3_RETENTION' => '14/8/6/2' ]);

check ('save de novo: valores novos lidos na mesma execução', Cloud::value ('S3_REGION') === 'us-east-1' && Cloud::value ('S3_RETENTION') === '14/8/6/2');
check ('retenção própria do projeto', Cloud::retention () ['spec'] === '14/8/6/2' && strpos (Cloud::retention () ['where'], "'retention' in the file") === 0);

Cloud::select ('calda-certa');

check ('outro projeto, sem arquivo: configuração do servidor', Cloud::scope () === NULL && Cloud::value ('S3_BUCKET') === 'backup' && Cloud::value ('S3_REGION') === 'sa-east-1');

Cloud::select (NULL);

check ('sem projeto selecionado: configuração do servidor', Cloud::value ('S3_ACCESS_KEY') === 'server-access-key');

Cloud::select ('../etc');

check ('nome de projeto inválido não seleciona nada', Cloud::scope () === NULL && Cloud::value ('S3_BUCKET') === 'backup');

// --- arquivo inválido ou incompleto: nunca cai na configuração do servidor -----------------------------------------

file_put_contents ($_data .'/s3/calda-certa.json', '{ "endpoint": ');

Cloud::forget ();

Cloud::select ('calda-certa');

check ('JSON inválido: escopo é o projeto, e não o servidor', Cloud::scope () === 'calda-certa');
check ('JSON inválido: não configurado, nada do .env', !Cloud::configured () && Cloud::value ('S3_BUCKET') === '' && Cloud::value ('S3_SECRET_KEY') === '');
check ('JSON inválido: faltam os quatro campos', Cloud::missing () === [ "'endpoint'", "'bucket'", "'access_key'", "'secret_key'" ], json_encode (Cloud::missing ()));

file_put_contents ($_data .'/s3/calda-certa.json', json_encode ([ 'endpoint' => 'https://s3.cnpxx.example', 'bucket' => 'backup', 'access_key' => 'AKIACALDACERTA000001', 'secret_key' => [ 'x' ] ]));

Cloud::forget ();

check ('campo que não é texto conta como ausente', Cloud::missing () === [ "'secret_key'" ], json_encode (Cloud::missing ()));

file_put_contents ($_data .'/s3/calda-certa.json', json_encode ([ 'endpoint' => 'https://s3.cnpxx.example', 'bucket' => 'backup', 'access_key' => 'AKIACALDACERTA000001', 'secret_key' => 'calda-secret-zyxwvutsrq' ]));

Cloud::forget ();

// --- lista de projetos ---------------------------------------------------------------------------------------------

file_put_contents ($_data .'/s3/Invalid_Name.json', '{}');
file_put_contents ($_data .'/s3/outro-projeto.json.bkp', '{}');

check ('projects: só nomes válidos com .json, em ordem', Cloud::projects () === [ 'calda-certa', 'pasto-inteligente' ], json_encode (Cloud::projects ()));

// --- máscara ---------------------------------------------------------------------------------------------------------

Cloud::select ('pasto-inteligente');

$text = 'server-secret-0123456789 project-secret-abcdefghij calda-secret-zyxwvutsrq server-access-key AKIACALDACERTA000001 pasto-inteligente_api_release_2026.tar.gz';

check ('mask: segredos e chaves de todas as configurações; chave igual ao nome do projeto fica', Cloud::mask ($text) === '*** *** *** *** *** pasto-inteligente_api_release_2026.tar.gz', Cloud::mask ($text));

// --- validação do save e remoção -----------------------------------------------------------------------------------

$refused = function ($project, $values)
{
	try { Cloud::save ($project, $values); } catch (Exception $e) { return $e->getMessage (); }

	return NULL;
};

$ok = [ 'S3_ENDPOINT' => 'https://x.example', 'S3_BUCKET' => 'b', 'S3_ACCESS_KEY' => 'k', 'S3_SECRET_KEY' => 's' ];

check ('save recusa nome de projeto inválido', strpos ((string) $refused ('../x', $ok), 'Invalid project name') === 0 && strpos ((string) $refused ('A', $ok), 'Invalid project name') === 0);
check ('save recusa campo obrigatório vazio', $refused ('novo-projeto', [ 'S3_SECRET_KEY' => ' ' ] + $ok) === "Missing 'secret_key'" && !file_exists ($_data .'/s3/novo-projeto.json'));

check ('remove: havia arquivo', Cloud::remove ('pasto-inteligente') === TRUE && !file_exists ($file));
check ('remove: o projeto volta à configuração do servidor', Cloud::scope () === NULL && Cloud::value ('S3_BUCKET') === 'backup');
check ('remove: sem arquivo, nada a fazer', Cloud::remove ('pasto-inteligente') === FALSE);

// --- io check: configuração por projeto incompleta é erro, sem tocar na rede ---------------------------------------

foreach (array_keys ($server) as $name) putenv ($name .'=');

Cloud::select (NULL);

array_map ('unlink', glob ($_data .'/s3/*'));

check ('check: nenhuma configuração = NULL', Cloud::check ($_data) === NULL);

file_put_contents ($_data .'/s3/calda-certa.json', json_encode ([ 'endpoint' => 'https://s3.cnpxx.example' ]));

Cloud::forget ();

$result = Cloud::check ($_data);

check ('check: projeto com arquivo incompleto é ERROR com alerta', is_array ($result) && $result ['status'] === 'ERROR' && !empty ($result ['alert']) && strpos ($result ['message'], "missing 'bucket', 'access_key', 'secret_key'") !== FALSE && strpos ($result ['message'], 'io cloud --setup calda-certa') !== FALSE, json_encode ($result));
check ('check: um escopo, o do projeto', sizeof ($result ['scopes']) === 1 && $result ['scopes'][0]['project'] === 'calda-certa' && $result ['scopes'][0]['endpoint'] === 'https://s3.cnpxx.example');
check ('check: a seleção anterior é devolvida', Cloud::scope () === NULL);

// --- linha de comando (assistente do bin/io) -----------------------------------------------------------------------

file_put_contents ($_data .'/builds/calda-certa_api_release.json', json_encode ([ 'project' => 'calda-certa', 'app' => 'api', 'stage' => 'release' ]));
file_put_contents ($_data .'/builds/pasto-inteligente_web_alpha.json', json_encode ([ 'project' => 'pasto-inteligente', 'app' => 'web', 'stage' => 'alpha' ]));
file_put_contents ($_data .'/builds/pasto-inteligente_api_alpha.json', json_encode ([ 'project' => 'pasto-inteligente', 'app' => 'api', 'stage' => 'alpha' ]));

$cli = function ($args, $env = []) use ($_data)
{
	$cmd = '';

	foreach ($env as $name => $value) $cmd .= $name .'='. escapeshellarg ($value) .' ';

	exec ($cmd . escapeshellarg (PHP_BINARY) .' '. escapeshellarg (__DIR__ .'/../class/Cloud.php') .' '. $args .' '. escapeshellarg ($_data) .' 2>&1', $out, $code);

	return [ 'out' => $out, 'code' => $code ];
};

$r = $cli ("projects ''");

check ('CLI projects: projetos com build e quem tem configuração própria', $r ['code'] === 0 && $r ['out'] === [ "calda-certa\town", "pasto-inteligente\tserver" ], json_encode ($r));

$r = $cli ('save pasto-inteligente', [ 'S3_ENDPOINT' => 'http://silo:9000', 'S3_BUCKET' => 'backup', 'S3_ACCESS_KEY' => 'pasto-inteligente', 'S3_SECRET_KEY' => 'se$gredo com "aspas" e \'apóstrofo\'', 'S3_REGION' => 'auto', 'S3_RETENTION' => '' ]);

check ('CLI save: grava pelo ambiente e mostra o caminho', $r ['code'] === 0 && $r ['out'] === [ $file ] && (fileperms ($file) & 0777) === 0600, json_encode ($r));

$r = $cli ('get pasto-inteligente');

check ('CLI get: uma linha por campo preenchido, valor intacto', $r ['code'] === 0 && $r ['out'] === [ 'S3_ENDPOINT=http://silo:9000', 'S3_BUCKET=backup', 'S3_ACCESS_KEY=pasto-inteligente', 'S3_SECRET_KEY=se$gredo com "aspas" e \'apóstrofo\'', 'S3_REGION=auto' ], json_encode ($r));

$r = $cli ('get projeto-sem-arquivo');

check ('CLI get: sem arquivo, nada', $r ['code'] === 0 && $r ['out'] === []);

$r = $cli ('save pasto-inteligente', [ 'S3_ENDPOINT' => 'http://silo:9000', 'S3_BUCKET' => '', 'S3_ACCESS_KEY' => 'k', 'S3_SECRET_KEY' => 's' ]);

check ('CLI save: campo faltando sai com 1 e mantém o arquivo anterior', $r ['code'] === 1 && $r ['out'] === [ "Missing 'bucket'" ] && json_decode (file_get_contents ($file), TRUE) ['bucket'] === 'backup', json_encode ($r));

$r = $cli ('remove pasto-inteligente');

check ('CLI remove: apaga o arquivo', $r ['code'] === 0 && !file_exists ($file));

$r = $cli ('desconhecido x');

check ('CLI: comando desconhecido sai com 1', $r ['code'] === 1);

// --- limpeza ---------------------------------------------------------------------------------------------------------

array_map ('unlink', array_merge (glob ($_data .'/s3/*'), glob ($_data .'/builds/*')));
rmdir ($_data .'/s3');
rmdir ($_data .'/builds');
rmdir ($_data);

echo "\n". ($total - $failures) ." of ". $total ." test(s) passed. \n";

exit ($failures ? 1 : 0);
