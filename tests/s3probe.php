<?php
/**
 * Testes da assinatura AWS Signature Version 4 do teste de gravação no bucket
 * (class/S3Probe.php), com os exemplos da documentação da AWS ("Signature
 * Calculations for the Authorization Header", exemplos de GET object, GET
 * bucket lifecycle e GET bucket list objects), e da escolha dos diretórios do
 * teste (por configuração: a do servidor e a própria de cada projeto). O teste
 * contra um bucket real foi feito com Docker (ver o PR do teste de gravação).
 *
 * Uso: php tests/s3probe.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'S3Probe.php';

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

$access = 'AKIAIOSFODNN7EXAMPLE';
$secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
$time = gmmktime (0, 0, 0, 5, 24, 2013); // 20130524T000000Z

$signature = function ($headers) { return preg_match ('/Signature=([0-9a-f]{64})$/', $headers ['authorization'], $m) ? $m [1] : ''; };

// GET object com Range
$h = S3Probe::sign ('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', [ 'Range' => 'bytes=0-9' ], S3Probe::EMPTY_HASH, $access, $secret, 'us-east-1', $time);

check ('GET object: assinatura', $signature ($h) === 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $h ['authorization']);
check ('GET object: cabeçalhos assinados', strpos ($h ['authorization'], 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,') !== FALSE);
check ('GET object: escopo', strpos ($h ['authorization'], 'Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request') !== FALSE);
check ('GET object: x-amz-date', $h ['x-amz-date'] === '20130524T000000Z');
check ('GET object: host fica de fora (o cliente HTTP o envia)', !isset ($h ['host']));

// GET bucket lifecycle: chave de query sem valor ('?lifecycle' => 'lifecycle=')
$h = S3Probe::sign ('GET', 'https://examplebucket.s3.amazonaws.com/?lifecycle', [], S3Probe::EMPTY_HASH, $access, $secret, 'us-east-1', $time);

check ('GET ?lifecycle: assinatura', $signature ($h) === 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', $h ['authorization']);

// GET bucket list objects: query ordenada
$h = S3Probe::sign ('GET', 'https://examplebucket.s3.amazonaws.com/?prefix=J&max-keys=2', [], S3Probe::EMPTY_HASH, $access, $secret, 'us-east-1', $time);

check ('GET ?max-keys&prefix: assinatura (query fora de ordem)', $signature ($h) === '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $h ['authorization']);

// Porta fora do padrão entra no host assinado (estilo de caminho, como no MinIO)
$a = S3Probe::sign ('POST', 'http://localhost:9000/backup/x?uploads', [], S3Probe::EMPTY_HASH, $access, $secret, 'auto', $time);
$b = S3Probe::sign ('POST', 'http://localhost:9001/backup/x?uploads', [], S3Probe::EMPTY_HASH, $access, $secret, 'auto', $time);

check ('porta faz parte do host assinado', $signature ($a) !== $signature ($b));

// Diretórios do teste de gravação: os das builds que usam a mesma configuração
$dir = sys_get_temp_dir () .'/s3probe-'. bin2hex (random_bytes (4));

mkdir ($dir .'/builds', 0700, TRUE);

$build = function ($project, $app, $stage, $cloud = NULL) use ($dir)
{
	$b = [ 'project' => $project, 'app' => $app, 'stage' => $stage ];

	if ($cloud !== NULL) $b ['auto'] = [ 'cloud' => $cloud ];

	file_put_contents ($dir .'/builds/'. $project .'_'. $app .'_'. $stage .'.json', json_encode ($b));
};

check ('servidor sem builds: raiz do bucket', S3Probe::prefixes ($dir) === [ '' ]);

$build ('alpha', 'api', 'beta');

check ('servidor com uma build', S3Probe::prefixes ($dir) === [ 'alpha/api/beta' ]);

$build ('beta', 'web', 'release', TRUE);

check ('servidor: só o projeto com auto.cloud', S3Probe::prefixes ($dir) === [ 'beta/web/release' ]);

file_put_contents ($dir .'/builds/zz_bad_alpha.json', '{');

check ('JSON inválido não quebra', S3Probe::prefixes ($dir) === [ 'beta/web/release' ]);

$build ('beta', 'api', 'alpha', TRUE);
$build ('gama', 'web', 'release', 'undated');

check ('servidor: um diretório por projeto com a cópia ligada', S3Probe::prefixes ($dir) === [ 'beta/api/alpha', 'gama/web/release' ]);

check ('projeto: build dele com auto.cloud', S3Probe::prefixes ($dir, 'gama') === [ 'gama/web/release' ]);
check ('projeto: sem auto.cloud, a primeira build dele', S3Probe::prefixes ($dir, 'alpha') === [ 'alpha/api/beta' ]);
check ('projeto sem build no servidor: a pasta dele', S3Probe::prefixes ($dir, 'delta') === [ 'delta' ]);

// projeto com configuração própria (<data>/s3/<projeto>.json) sai do teste da configuração do servidor
mkdir ($dir .'/s3', 0700);

file_put_contents ($dir .'/s3/beta.json', '{}');

check ('servidor: projeto com configuração própria fica de fora', S3Probe::prefixes ($dir) === [ 'gama/web/release' ]);

file_put_contents ($dir .'/s3/gama.json', '{}');

check ('servidor: sem a cópia ligada nos que restam, a primeira build deles', S3Probe::prefixes ($dir) === [ 'alpha/api/beta' ]);

file_put_contents ($dir .'/s3/alpha.json', '{}');

check ('servidor: todos com configuração própria, nada a testar', S3Probe::prefixes ($dir) === []);

$none = S3Probe::runAll ('http://127.0.0.1:1', 'backup', 'k', 's', '', []);

check ('runAll sem diretórios: ok, nada testado', $none ['ok'] === TRUE && $none ['tested'] === 0);

$down = S3Probe::runAll ('http://127.0.0.1:1', 'backup', 'chave-de-acesso', 'segredo-do-teste', '', [ 'a/b/c', 'd/e/f' ]);

check ('runAll para na primeira falha', $down ['ok'] === FALSE && $down ['tested'] === 0 && strpos ($down ['message'], 'segredo-do-teste') === FALSE, $down ['message']);

array_map ('unlink', array_merge (glob ($dir .'/builds/*'), glob ($dir .'/s3/*')));
rmdir ($dir .'/builds');
rmdir ($dir .'/s3');
rmdir ($dir);

echo "\n". ($total - $failures) ." of ". $total ." test(s) passed. \n";

exit ($failures ? 1 : 0);
