<?php
/**
 * Testes da parte pura do `io update` (plugin/Orchestrator.php): quais serviços rodam uma imagem diferente da que a
 * referência do compose aponta agora (`compareImages`), que referências podem mudar de conteúdo (`floating`) e o ID
 * abreviado das mensagens (`shortId`). O comportamento ponta a ponta está em tests/update-integration.sh.
 *
 * Uso: php tests/update.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'plugin'. DIRECTORY_SEPARATOR .'Orchestrator.php';

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

$old = 'sha256:'. str_repeat ('a', 64);
$new = 'sha256:'. str_repeat ('b', 64);

// --- referências que podem mudar de conteúdo -------------------------------------------------------------------------

foreach ([
	'postgres:17-alpine' => TRUE,
	'jc21/nginx-proxy-manager:2' => TRUE,
	'ghcr.io/getarcaneapp/manager:v3' => TRUE,
	'busybox' => TRUE,
	'registry.example:5000/time/app:latest' => TRUE,
	'postgres:17@sha256:'. str_repeat ('c', 64) => FALSE,
	'postgres@sha256:'. str_repeat ('c', 64) => FALSE,
	$old => FALSE,
	str_repeat ('a', 64) => FALSE,
	'0123456789ab' => FALSE,
	'' => FALSE
] as $reference => $floating)
	check ("referência '". (strlen ($reference) > 40 ? substr ($reference, 0, 37) .'...' : $reference) ."': ". ($floating ? 'pode mudar' : 'fixa'), Orchestrator::floating ($reference) === $floating);

// --- comparação ------------------------------------------------------------------------------------------------------

$running = [
	'web' => [ 'image' => $old, 'reference' => 'nginx:1' ],
	'db' => [ 'image' => $old, 'reference' => 'postgres:17' ],
	'cache' => [ 'image' => $old, 'reference' => 'redis:8' ]
];

$out = Orchestrator::compareImages ($running, [ 'nginx:1' => $new, 'postgres:17' => $old, 'redis:8' => $old ]);

check ('só o serviço com imagem nova é listado', array_keys ($out) === [ 'web' ], json_encode (array_keys ($out)));
check ('com a referência e os dois IDs', $out ['web'] === [ 'reference' => 'nginx:1', 'running' => $old, 'available' => $new ]);

$out = Orchestrator::compareImages ($running, [ 'nginx:1' => $old, 'postgres:17' => $old, 'redis:8' => $old ]);

check ('nada mudou: lista vazia', $out === []);

$out = Orchestrator::compareImages ($running, [ 'nginx:1' => $new, 'postgres:17' => $new, 'redis:8' => $new ]);

check ('vários serviços, em ordem de nome', array_keys ($out) === [ 'cache', 'db', 'web' ]);

$out = Orchestrator::compareImages ($running, [ 'nginx:1' => NULL, 'postgres:17' => $old ]);

check ('imagem que não está no host (NULL) ou sem consulta: fora da lista', $out === []);

$out = Orchestrator::compareImages ([
	'a' => [ 'image' => $old, 'reference' => 'postgres:17@sha256:'. str_repeat ('c', 64) ],
	'b' => [ 'image' => $old, 'reference' => $old ],
	'c' => [ 'image' => '', 'reference' => 'nginx:1' ],
	'd' => [ 'reference' => 'nginx:1' ],
	'e' => [ 'image' => $old ]
], [ 'postgres:17@sha256:'. str_repeat ('c', 64) => $new, $old => $new, 'nginx:1' => $new ]);

check ('fixada por digest, referência que é um ID e contêiner sem imagem ou sem referência: fora da lista', $out === [], json_encode ($out));

$out = Orchestrator::compareImages ([ 'web' => [ 'image' => $old, 'reference' => 'nginx:1' ], 'web2' => [ 'image' => $new, 'reference' => 'nginx:1' ] ], [ 'nginx:1' => $new ]);

check ('dois serviços com a mesma imagem: só o que ficou para trás', array_keys ($out) === [ 'web' ]);

check ('lista vazia de contêineres', Orchestrator::compareImages ([], [ 'nginx:1' => $new ]) === []);

// --- ID abreviado ----------------------------------------------------------------------------------------------------

check ('ID abreviado sem o prefixo sha256:', Orchestrator::shortId ('sha256:0123456789abcdef0123') === '0123456789ab');
check ('ID abreviado sem prefixo', Orchestrator::shortId ('0123456789abcdef0123') === '0123456789ab');

echo "\n". ($total - $failures) ."/". $total ." ok\n";

exit ($failures ? 1 : 0);
