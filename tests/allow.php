<?php
/**
 * Testes das partes puras da autorização do socket do Docker por build: Controller::allow (leitura do atributo 'allow'
 * do arquivo da build), DockerCompose::socketMounts (serviços do compose interpolado que montam o socket) e
 * DockerCompose::mentionsSocket (o texto do compose cita o socket? usado pelo 'io install' para decidir se pergunta).
 * A validação de ponta a ponta está em tests/validate-integration.sh; o 'io install', em tests/install-integration.sh.
 *
 * Uso: php tests/allow.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

$root = __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR;

require_once $root .'plugin'. DIRECTORY_SEPARATOR .'Orchestrator.php';
require_once $root .'plugin'. DIRECTORY_SEPARATOR .'DockerCompose.php';
require_once $root .'class'. DIRECTORY_SEPARATOR .'Controller.php';

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

// Controller::allow: só o booleano TRUE em allow.socket autoriza

$build = function ($json) { return json_decode ($json); };

check ('allow.socket = true autoriza', Controller::allow ($build ('{"allow":{"socket":true}}')) === [ 'socket' => TRUE ]);
check ('allow.socket = false não autoriza', Controller::allow ($build ('{"allow":{"socket":false}}')) === [ 'socket' => FALSE ]);
check ('arquivo sem o atributo allow não autoriza', Controller::allow ($build ('{"project":"p","auto":{"deploy":true}}')) === [ 'socket' => FALSE ]);
check ('allow vazio não autoriza', Controller::allow ($build ('{"allow":{}}')) === [ 'socket' => FALSE ]);
check ('"true" (texto) não autoriza', Controller::allow ($build ('{"allow":{"socket":"true"}}')) === [ 'socket' => FALSE ]);
check ('1 (número) não autoriza', Controller::allow ($build ('{"allow":{"socket":1}}')) === [ 'socket' => FALSE ]);
check ('"yes" não autoriza', Controller::allow ($build ('{"allow":{"socket":"yes"}}')) === [ 'socket' => FALSE ]);
check ('allow: true (sem dizer o quê) não autoriza', Controller::allow ($build ('{"allow":true}')) === [ 'socket' => FALSE ]);
check ('allow como lista não autoriza', Controller::allow ($build ('{"allow":["socket"]}')) === [ 'socket' => FALSE ]);
check ('socket dentro de auto não autoriza', Controller::allow ($build ('{"auto":{"socket":true}}')) === [ 'socket' => FALSE ]);
check ('socket: true solto na raiz não autoriza', Controller::allow ($build ('{"socket":true}')) === [ 'socket' => FALSE ]);
check ('chave desconhecida em allow é ignorada', Controller::allow ($build ('{"allow":{"socket":true,"privileged":true}}')) === [ 'socket' => TRUE ]);
check ('build que não é objeto não autoriza', Controller::allow (NULL) === [ 'socket' => FALSE ] && Controller::allow ('x') === [ 'socket' => FALSE ]);

// DockerCompose::socketMounts: sobre o compose já interpolado (formato longo dos volumes, como o 'config' devolve)

$bind = function ($source, $target) { return [ 'type' => 'bind', 'source' => $source, 'target' => $target ]; };
$named = function ($source, $target) { return [ 'type' => 'volume', 'source' => $source, 'target' => $target ]; };

$config = [ 'services' => [
	'panel' => [ 'volumes' => [ $named ('data', '/data'), $bind ('/var/run/docker.sock', '/var/run/docker.sock') ] ],
	'web' => [ 'volumes' => [ $named ('data', '/data') ] ],
	'backup' => [ 'volumes' => [ $bind ('/run/docker.sock', '/docker.sock') ] ],
	'restore' => [ 'volumes' => [ $bind ('/etc', '/host-etc') ] ],
	'sanitize' => [],
	'odd' => [ 'volumes' => [ $named ('/var/run/docker.sock', '/var/run/docker.sock') ] ]
] ];

check ('acha os serviços que montam o socket, por /var/run e por /run', DockerCompose::socketMounts ($config) === [ 'panel', 'backup' ], json_encode (DockerCompose::socketMounts ($config)));
check ('com a lista de serviços, só entre eles', DockerCompose::socketMounts ($config, [ 'backup', 'restore', 'sanitize', 'test' ]) === [ 'backup' ]);
check ('socket montado noutro caminho do contêiner conta', DockerCompose::socketMounts ([ 'services' => [ 'a' => [ 'volumes' => [ $bind ('/var/run/docker.sock', '/tmp/d.sock') ] ] ] ]) === [ 'a' ]);
check ('outro arquivo montado no caminho do socket não conta', DockerCompose::socketMounts ([ 'services' => [ 'a' => [ 'volumes' => [ $bind ('/etc/passwd', '/var/run/docker.sock') ] ] ] ]) === []);
check ('volume nomeado não conta', DockerCompose::socketMounts ($config, [ 'odd' ]) === []);
check ('compose sem serviços ou ilegível não acusa nada', DockerCompose::socketMounts (FALSE) === [] && DockerCompose::socketMounts ([]) === [] && DockerCompose::socketMounts ([ 'services' => 'x' ]) === []);

// DockerCompose::mentionsSocket: sobre o texto do arquivo, sem interpolar

check ('forma curta', DockerCompose::mentionsSocket ("    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n"));
check ('somente leitura e entre aspas', DockerCompose::mentionsSocket ("      - \"/var/run/docker.sock:/var/run/docker.sock:ro\"\n"));
check ('forma longa', DockerCompose::mentionsSocket ("      - type: bind\n        source: /var/run/docker.sock\n        target: /var/run/docker.sock\n"));
check ('pelo caminho /run', DockerCompose::mentionsSocket ("      - /run/docker.sock:/var/run/docker.sock\n"));
check ('origem em variável, alvo no caminho padrão', DockerCompose::mentionsSocket ("      - \${SOCK}:/var/run/docker.sock\n"));
check ('lista em linha', DockerCompose::mentionsSocket ("    volumes: [ \"/var/run/docker.sock:/var/run/docker.sock\" ]\n"));
check ('compose sem o socket', !DockerCompose::mentionsSocket ("services:\n  web:\n    image: nginx\n    volumes:\n      - data:/data\n"));
check ('outro arquivo de nome parecido não conta', !DockerCompose::mentionsSocket ("      - data:/srv/var/run/docker.sock.d\n      - x:/opt/run/docker_sock\n"));
check ('texto vazio', !DockerCompose::mentionsSocket ('') && !DockerCompose::mentionsSocket (NULL));

echo "\n". ($failures ? $failures ." de ". $total ." caso(s) falharam" : "TUDO OK (". $total ." casos)") ."\n";

exit ($failures ? 1 : 0);
