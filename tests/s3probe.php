<?php
/**
 * Testes da assinatura AWS Signature Version 4 do teste de gravação no bucket
 * (class/S3Probe.php), com os exemplos da documentação da AWS ("Signature
 * Calculations for the Authorization Header", exemplos de GET object, GET
 * bucket lifecycle e GET bucket list objects), e da escolha do prefixo. O teste
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

// Prefixo: build com auto.cloud tem preferência; sem builds, ''
$dir = sys_get_temp_dir () .'/s3probe-'. bin2hex (random_bytes (4));

mkdir ($dir .'/builds', 0700, TRUE);

check ('prefixo sem builds', S3Probe::prefix ($dir) === '');

file_put_contents ($dir .'/builds/alpha_api_beta.json', json_encode ([ 'project' => 'alpha', 'app' => 'api', 'stage' => 'beta' ]));

check ('prefixo com uma build', S3Probe::prefix ($dir) === 'alpha/api/beta');

file_put_contents ($dir .'/builds/beta_web_release.json', json_encode ([ 'project' => 'beta', 'app' => 'web', 'stage' => 'release', 'auto' => [ 'cloud' => TRUE ] ]));

check ('prefixo prefere a build com auto.cloud', S3Probe::prefix ($dir) === 'beta/web/release');

file_put_contents ($dir .'/builds/zz_bad_alpha.json', '{');

check ('JSON inválido não quebra', S3Probe::prefix ($dir) === 'beta/web/release');

array_map ('unlink', glob ($dir .'/builds/*'));
rmdir ($dir .'/builds');
rmdir ($dir);

echo "\n". ($total - $failures) ." of ". $total ." test(s) passed. \n";

exit ($failures ? 1 : 0);
