<?php
/**
 * Testes das partes puras do `io upgrade` (class/Upgrade.php), sem framework e
 * sem Docker: formato e ordem das versões, escolha da mais nova entre as tags,
 * corpo do POST /containers/create a partir do `docker inspect` e o resumo do
 * resultado. A troca em si foi testada com Docker (ver o PR do `io upgrade`).
 *
 * Uso: php tests/upgrade.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'Upgrade.php';

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

// --- versões -----------------------------------------------------------------

check ('1.26.10-13 está no formato', Upgrade::valid ('1.26.10-13'));
check ('latest fica de fora', !Upgrade::valid ('latest'));
check ('desenvolvimento (0.*) fica de fora', !Upgrade::valid ('0.26.10-1'));
check ('desenvolvimento (*-dev.*) fica de fora', !Upgrade::valid ('0.26.10-dev.3') && !Upgrade::valid ('1.26.10-dev.3'));
check ('tag com sufixo fica de fora', !Upgrade::valid ('1.26.10-13-alpine') && !Upgrade::valid ('v1.26.10-13'));

check ('ordem numérica, não alfabética (N)', Upgrade::compare ('1.26.10-13', '1.26.10-9') > 0);
check ('ordem numérica, não alfabética (mês)', Upgrade::compare ('1.26.10-1', '1.26.9-9') > 0);
check ('ano manda sobre o mês', Upgrade::compare ('1.27.1-1', '1.26.12-30') > 0);
check ('iguais', Upgrade::compare ('1.26.10-13', '1.26.10-13') === 0);
check ('fora do formato é menor que qualquer versão', Upgrade::compare ('latest', '1.23.7-1') < 0);

$tags = [ 'latest', '1.26.10-13', '1.26.10-9', '0.26.10-dev.4', '1.26.9-9', '1.25.4-2', 'foo' ];

check ('a mais nova entre as tags do Docker Hub', Upgrade::newest ($tags) === '1.26.10-13', (string) Upgrade::newest ($tags));
check ('nenhuma tag no formato', Upgrade::newest ([ 'latest', 'dev' ]) === NULL);

// --- corpo do create a partir do inspect ----------------------------------------

$image = json_decode (json_encode ([
	'Id' => 'sha256:old',
	'Config' => [
		'Env' => [ 'PATH=/app/bin:/usr/bin', 'IO_RELEASER_VERSION=1.26.10-12' ],
		'Entrypoint' => [ '/sbin/tini', '--' ],
		'Cmd' => [ '/usr/sbin/crond', '-f' ],
		'WorkingDir' => '/app',
		'Labels' => [ 'org.opencontainers.image.title' => 'releaser' ]
	]
]));

$container = json_decode (json_encode ([
	'Id' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
	'Name' => '/releaser',
	'Image' => 'sha256:old',
	'Config' => [
		'Hostname' => 'abcdef123456',
		'Image' => 'embrapa/releaser:1.26.10-12',
		'Env' => [ 'HTTPS_PROXY=http://proxy:3128', 'PATH=/app/bin:/usr/bin', 'IO_RELEASER_VERSION=1.26.10-12' ],
		'Entrypoint' => [ '/sbin/tini', '--' ],
		'Cmd' => [ '/usr/sbin/crond', '-f' ],
		'WorkingDir' => '/app',
		'Labels' => [ 'org.opencontainers.image.title' => 'releaser', 'team' => 'io' ]
	],
	'HostConfig' => [
		'Binds' => [ '/root/releaser:/data', '/var/run/docker.sock:/var/run/docker.sock' ],
		'RestartPolicy' => [ 'Name' => 'unless-stopped', 'MaximumRetryCount' => 0 ],
		'NetworkMode' => 'bridge',
		'ExtraHosts' => [ 'gitlab.local:10.0.0.1' ]
	],
	'Mounts' => [
		[ 'Type' => 'bind', 'Source' => '/root/releaser', 'Destination' => '/data', 'RW' => TRUE ],
		[ 'Type' => 'bind', 'Source' => '/var/run/docker.sock', 'Destination' => '/var/run/docker.sock', 'RW' => TRUE ]
	],
	'NetworkSettings' => [ 'Networks' => [ 'bridge' => [ 'Aliases' => NULL, 'IPAMConfig' => NULL, 'Links' => NULL ] ] ]
]));

list ($body, $extra) = Upgrade::body ($container, $image, 'embrapa/releaser:1.26.10-13');

check ('imagem nova pela tag exata', $body->Image === 'embrapa/releaser:1.26.10-13');
check ('só as variáveis da criação (sem as da imagem antiga, nem IO_RELEASER_VERSION)', $body->Env === [ 'HTTPS_PROXY=http://proxy:3128' ], json_encode ($body->Env));
check ('só os rótulos da criação', json_encode ($body->Labels) === '{"team":"io"}', json_encode ($body->Labels));
check ('rótulos vazios viram {} no JSON (o Docker recusa [])', json_encode (Upgrade::body ($container, json_decode (json_encode ([ 'Config' => [ 'Labels' => [ 'team' => 'io', 'org.opencontainers.image.title' => 'releaser' ] ] ])), 'x')[0]->Labels) === '{}');
check ('ENTRYPOINT e CMD da imagem antiga não passam (valem os da nova)', !property_exists ($body, 'Entrypoint') && !property_exists ($body, 'Cmd') && !property_exists ($body, 'WorkingDir'));
check ('hostname padrão (ID abreviado) não passa', !property_exists ($body, 'Hostname'));
check ('HostConfig inteiro: montagens, restart e extra hosts', $body->HostConfig->Binds === $container->HostConfig->Binds && $body->HostConfig->RestartPolicy->Name === 'unless-stopped' && $body->HostConfig->ExtraHosts === [ 'gitlab.local:10.0.0.1' ]);
check ('rede principal no create, sem extras', isset ($body->NetworkingConfig->EndpointsConfig->bridge) && $extra === []);
check ('o inspect original não é alterado', $container->Config->Image === 'embrapa/releaser:1.26.10-12' && sizeof ($container->Config->Env) === 3);

$custom = json_decode (json_encode ($container));
$custom->Config->Cmd = [ 'io', 'info' ];
$custom->Config->Hostname = 'releaser-host';
$custom->HostConfig->NetworkMode = 'apps';
$custom->NetworkSettings->Networks = json_decode (json_encode ([ 'apps' => [ 'Aliases' => [ 'releaser', 'abcdef123456' ] ], 'monitoring' => [ 'Aliases' => NULL ] ]));

list ($body, $extra) = Upgrade::body ($custom, $image, 'embrapa/releaser:1.26.10-13');

check ('CMD sobrescrito na criação passa', $body->Cmd === [ 'io', 'info' ]);
check ('hostname definido na criação passa', $body->Hostname === 'releaser-host');
check ('rede principal com o alias, sem o ID antigo', $body->NetworkingConfig->EndpointsConfig->apps->Aliases === [ 'releaser' ], json_encode ($body->NetworkingConfig));
check ('demais redes ligadas depois do create', array_keys ($extra) === [ 'monitoring' ]);

// volta atrás recriando (contêiner criado com --rm): Config inteira, com a imagem anterior
list ($body, $extra) = Upgrade::body ($container, NULL, 'sha256:old');

check ('volta atrás: Config inteira com a imagem anterior', $body->Image === 'sha256:old' && sizeof ($body->Env) === 3 && $body->Entrypoint === [ '/sbin/tini', '--' ]);

$host = json_decode (json_encode ($container));
$host->HostConfig->NetworkMode = 'host';

check ('rede host: sem NetworkingConfig', !property_exists (Upgrade::body ($host, $image, 'x')[0], 'NetworkingConfig'));

$failure = json_decode (json_encode ($container));
$failure->HostConfig->RestartPolicy = json_decode ('{"Name":"on-failure","MaximumRetryCount":5}');

check ('política de restart para o docker update', Upgrade::policy ($container) === 'unless-stopped' && Upgrade::policy ($failure) === 'on-failure:5');

// --- resultado -------------------------------------------------------------------

check ('resumo de troca bem-sucedida', strpos (Upgrade::describe ([ 'from' => '1.26.10-12', 'to' => '1.26.10-13', 'result' => 'ok', 'finished_at' => 'X' ]), '1.26.10-12 -> 1.26.10-13: ok') === 0);
check ('resumo de volta atrás diz em que versão ficou', strpos (Upgrade::describe ([ 'from' => '1.26.10-12', 'to' => '1.26.10-13', 'result' => 'rolled back', 'message' => 'm' ]), 'still on 1.26.10-12') !== FALSE);
check ('nunca rodou', Upgrade::describe ([]) === 'never run');

// ---------------------------------------------------------------------------

echo "\n". ($total - $failures) ." of ". $total ." test(s) passed. \n";

exit ($failures ? 1 : 0);
