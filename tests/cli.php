<?php
/**
 * Testes do comando que recria o contêiner do Releaser (Cli::command, pura), gravado no wrapper `io` do host
 * para quando o contêiner tiver sido removido, e da sintaxe do script gerado (sh -n). O comportamento com o
 * contêiner parado e removido foi ensaiado em Docker, com terminal (ver o commit da 1.26.11-4).
 *
 * Uso: php tests/cli.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'BackupVolume.php';
require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'Cli.php';

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

// inspect de um contêiner criado pelo comando da documentação
$container = function (array $over = [])
{
	$base = [
		'Name' => '/releaser',
		'Image' => 'sha256:0123',
		'Config' => [ 'Image' => 'embrapa/releaser', 'Labels' => [ 'maintainer' => 'x' ], 'Env' => [ 'PATH=/usr/bin', 'IO_RELEASER_VERSION=1.26.11-4' ] ],
		'HostConfig' => [
			'Binds' => [ '/root/releaser:/data', '/var/run/docker.sock:/var/run/docker.sock' ],
			'RestartPolicy' => [ 'Name' => 'always', 'MaximumRetryCount' => 0 ],
			'NetworkMode' => 'bridge',
			'ExtraHosts' => NULL,
			'Dns' => [],
			'AutoRemove' => FALSE
		]
	];

	return json_decode (json_encode (array_replace_recursive ($base, $over)));
};

$imageEnv = [ 'PATH=/usr/bin', 'IO_RELEASER_VERSION=1.26.11-4' ];

$r = Cli::command ($container (), '1.26.11-4', $imageEnv);

check ('comando da documentação, com a tag exata da versão', $r ['command'] === 'docker run -d --name releaser -v /root/releaser:/data -v /var/run/docker.sock:/var/run/docker.sock --restart always embrapa/releaser:1.26.11-4', $r ['command']);
check ('nome do contêiner e nenhum aviso', $r ['name'] === 'releaser' && $r ['note'] === '');

$r = Cli::command ($container ([ 'Config' => [ 'Image' => 'embrapa/releaser:1.26.10-9' ] ]), '1.26.11-4', $imageEnv);

check ('imagem criada com outra tag: vale a versão em execução', str_ends_with ($r ['command'], ' embrapa/releaser:1.26.11-4'), $r ['command']);

$r = Cli::command ($container ([ 'Config' => [ 'Image' => 'registry.example:5000/io/releaser:latest' ] ]), '1.26.11-4', $imageEnv);

check ('repositório próprio (com porta) é mantido', str_ends_with ($r ['command'], ' registry.example:5000/io/releaser:1.26.11-4'), $r ['command']);

$r = Cli::command ($container ([ 'Config' => [ 'Image' => 'releaser-local' ] ]), '0.26.11-dev.4', $imageEnv);

$r2 = Cli::command ($container ([ 'Config' => [ 'Image' => 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef' ] ]), '1.26.11-4', $imageEnv);

check ('imagem dada pelo ID: repositório padrão', str_ends_with ($r2 ['command'], ' embrapa/releaser:1.26.11-4'), $r2 ['command']);

check ('versão de desenvolvimento: a imagem como o contêiner a nomeia', str_ends_with ($r ['command'], ' releaser-local'), $r ['command']);

$r = Cli::command ($container ([ 'Name' => '/io-releaser', 'HostConfig' => [
	'Binds' => [ '/srv/dados do io:/data', '/var/run/docker.sock:/var/run/docker.sock', '/etc/letsencrypt:/etc/letsencrypt:ro' ],
	'RestartPolicy' => [ 'Name' => 'on-failure', 'MaximumRetryCount' => 5 ],
	'NetworkMode' => 'rede-interna',
	'ExtraHosts' => [ 'git.embrapa.io:10.0.0.5' ],
	'Dns' => [ '10.0.0.1', '10.0.0.2' ]
] ]), '1.26.11-4', $imageEnv);

check ('demais opções da criação: montagens, reinício, hosts, DNS e rede', $r ['command'] === "docker run -d --name io-releaser -v '/srv/dados do io:/data' -v /var/run/docker.sock:/var/run/docker.sock -v /etc/letsencrypt:/etc/letsencrypt:ro --restart on-failure:5 --add-host git.embrapa.io:10.0.0.5 --dns 10.0.0.1 --dns 10.0.0.2 --network rede-interna embrapa/releaser:1.26.11-4", $r ['command']);

$r = Cli::command ($container ([ 'HostConfig' => [ 'RestartPolicy' => [ 'Name' => 'no' ] ] ]), '1.26.11-4', $imageEnv);

check ('sem política de reinício, nada de --restart', strpos ($r ['command'], '--restart') === FALSE && $r ['command'] !== '');

$r = Cli::command ($container ([ 'HostConfig' => [ 'AutoRemove' => TRUE ] ]), '1.26.11-4', $imageEnv);

check ('contêiner com --rm', strpos ($r ['command'], ' --rm ') !== FALSE && strpos ($r ['command'], '--restart') === FALSE);

$r = Cli::command ($container ([ 'Config' => [ 'Env' => [ 'PATH=/usr/bin', 'IO_RELEASER_VERSION=1.26.11-4', 'HTTPS_PROXY=http://usuario:senha@proxy:3128', 'NO_PROXY=localhost' ] ] ]), '1.26.11-4', $imageEnv);

check ('variáveis da criação: só os nomes, no aviso', strpos ($r ['note'], 'HTTPS_PROXY, NO_PROXY') !== FALSE && strpos ($r ['command'] . $r ['note'], 'senha') === FALSE && strpos ($r ['command'], 'PROXY') === FALSE, $r ['note']);

$r = Cli::command ($container ([ 'Config' => [ 'Env' => [ 'PATH=/usr/bin', 'X=1' ] ] ]), '1.26.11-4', NULL);

check ('variáveis da imagem desconhecidas: sem aviso', $r ['note'] === '' && $r ['command'] !== '');

$none = [ 'name' => '', 'command' => '', 'note' => '' ];

check ('serviço do Swarm fica de fora', Cli::command ($container ([ 'Config' => [ 'Labels' => [ 'com.docker.swarm.service.name' => 'releaser' ] ] ]), '1.26.11-4', $imageEnv) === $none);
check ('contêiner do Docker Compose fica de fora', Cli::command ($container ([ 'Config' => [ 'Labels' => [ 'com.docker.compose.project' => 'releaser' ] ] ]), '1.26.11-4', $imageEnv) === $none);

$noData = $container ();

$noData->HostConfig->Binds = [ '/var/run/docker.sock:/var/run/docker.sock' ];

check ('sem /data montado, nada a oferecer', Cli::command ($noData, '1.26.11-4', $imageEnv) === $none);

$noSocket = $container ();

$noSocket->HostConfig->Binds = [ '/root/releaser:/data' ];

check ('sem o socket do Docker, nada a oferecer', Cli::command ($noSocket, '1.26.11-4', $imageEnv) === $none);
check ('inspect vazio não quebra', Cli::command (new stdClass, '1.26.11-4', $imageEnv) === $none);

// o script gerado é sh válido, com e sem o comando de recriação, e com aspas simples nos valores
$valid = function ($script)
{
	$file = tempnam (sys_get_temp_dir (), 'io-wrapper-');

	file_put_contents ($file, $script);

	exec ('sh -n '. escapeshellarg ($file) .' 2>&1', $output, $return);

	unlink ($file);

	return $return === 0 ? TRUE : implode (' ', $output);
};

putenv ('IO_RELEASER_VERSION=1.26.11-4');

$script = Cli::script ($none);

check ('script sem comando de recriação: sh válido', $valid ($script) === TRUE, (string) $valid ($script));
check ('script sem comando: valores vazios', strpos ($script, "created=''\nrecreate=''\nnote=''\n") !== FALSE);
check ('script: cabeçalho e versão', strpos ($script, Cli::MARK) !== FALSE && strpos ($script, '# IO_WRAPPER_VERSION=1.26.11-4') !== FALSE);

$quoted = [ 'name' => 'releaser', 'command' => "docker run -d --name releaser -v '/srv/it's here:/data' embrapa/releaser:1.26.11-4", 'note' => "It's a note" ];

$script = Cli::script ($quoted);

check ('script com aspas simples nos valores: sh válido', $valid ($script) === TRUE, (string) $valid ($script));

// o sh devolve o valor gravado sem alteração
$file = tempnam (sys_get_temp_dir (), 'io-wrapper-');

file_put_contents ($file, implode ("\n", array_filter (explode ("\n", $script), function ($line) { return preg_match ('/^(created|recreate|note)=/', $line); })) ."\nprintf '%s\\n%s\\n%s\\n' \"\$created\" \"\$recreate\" \"\$note\"\n");

exec ('sh '. escapeshellarg ($file), $output);

unlink ($file);

check ('valores gravados no script voltam intactos', $output === [ $quoted ['name'], $quoted ['command'], $quoted ['note'] ], json_encode ($output));

echo "\n". ($total - $failures) ." of ". $total ." test(s) passed. \n";

exit ($failures ? 1 : 0);
