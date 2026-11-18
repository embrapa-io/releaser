<?php
/**
 * Testes da trava por build (class/BuildLock.php) e da leitura do sistema de arquivos do /data (class/DataVolume.php).
 * O outro comando é simulado gravando o arquivo da trava e trocando a consulta ao /proc (`$probe`).
 *
 * Uso: php tests/buildlock.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'BuildLock.php';
require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'DataVolume.php';

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

function build ($project, $app, $stage)
{
	return (object) [ 'project' => $project, 'app' => $app, 'stage' => $stage ];
}

$data = sys_get_temp_dir () . DIRECTORY_SEPARATOR .'releaser-buildlock-'. getmypid ();

mkdir ($data, 0700, TRUE);

$me = getmypid ();
$other = $me + 100000;

// Processos "vivos" do teste: este e, quando ligado, o outro comando.
$otherStart = NULL;

$probe = function ($pid) use ($me, $other, &$otherStart)
{
	if ($pid === $me) return '1000';

	return $pid === $other ? $otherStart : NULL;
};

$a = build ('proj', 'app', 'alpha');
$b = build ('proj', 'app', 'beta');

$fileA = BuildLock::path ($data, $a);
$fileB = BuildLock::path ($data, $b);

// --- trava livre, reentrada e troca de build -------------------------------------------------------------------------

check ('caminho da trava', $fileA === $data .'/.busy/proj_app_alpha.lock', $fileA);
check ('build livre: pega a trava', BuildLock::acquire ($data, $a, 'deploy', $probe) === TRUE);

$holder = BuildLock::parse (file_get_contents ($fileA));

check ('arquivo com pid, início e operação', $holder ['pid'] === $me && $holder ['start'] === '1000' && $holder ['operation'] === 'deploy', json_encode ($holder));
check ('mesma build de novo no mesmo processo: continua com ela', BuildLock::acquire ($data, $a, 'deploy', $probe) === TRUE && file_exists ($fileA));
check ('outra build: pega a nova e solta a anterior', BuildLock::acquire ($data, $b, 'restart', $probe) === TRUE && file_exists ($fileB) && !file_exists ($fileA));

BuildLock::release ();

check ('release apaga a trava', !file_exists ($fileB));

BuildLock::release ();

check ('release sem trava não faz nada', TRUE);

// --- outro comando rodando -------------------------------------------------------------------------------------------

$otherStart = '2000';

$since = date (DATE_ATOM, time () - 90);

$content = json_encode ([ 'pid' => $other, 'start' => '2000', 'operation' => 'deploy:daemon', 'since' => $since ]);

file_put_contents ($fileA, $content);

$busy = BuildLock::acquire ($data, $a, 'deploy', $probe);

check ('outro comando vivo: build ocupada', is_array ($busy) && $busy ['pid'] === $other && $busy ['operation'] === 'deploy:daemon', json_encode ($busy));
check ('a trava do outro fica intacta', file_get_contents ($fileA) === $content);

BuildLock::release ();

check ('release não apaga a trava de outro', file_exists ($fileA));

$message = BuildLock::message ($busy);

check ('mensagem com a operação, a data e o pid', strpos ($message, "'deploy:daemon'") !== FALSE && strpos ($message, 'since '. date ('Y-m-d H:i:s', strtotime ($since))) !== FALSE && strpos ($message, 'pid '. $other) !== FALSE, $message);
check ('outra build não é afetada', BuildLock::acquire ($data, $b, 'stop', $probe) === TRUE);

BuildLock::release ();

// --- trava abandonada ------------------------------------------------------------------------------------------------

$otherStart = NULL; // o processo morreu

check ('processo morto: assume a trava', BuildLock::acquire ($data, $a, 'deploy', $probe) === TRUE && BuildLock::parse (file_get_contents ($fileA)) ['pid'] === $me);

BuildLock::release ();

$otherStart = '9999'; // o PID existe, mas é outro processo (o contêiner reiniciou)

file_put_contents ($fileA, $content);

check ('PID reaproveitado (início diferente): assume a trava', BuildLock::acquire ($data, $a, 'deploy', $probe) === TRUE && BuildLock::parse (file_get_contents ($fileA)) ['pid'] === $me);

BuildLock::release ();

foreach ([ 'vazio' => '', 'lixo' => 'not json', 'sem pid' => '{"operation":"deploy"}' ] as $label => $garbage)
{
	file_put_contents ($fileA, $garbage);

	check ('arquivo '. $label .': assume a trava', BuildLock::acquire ($data, $a, 'deploy', $probe) === TRUE && BuildLock::parse (file_get_contents ($fileA)) ['pid'] === $me);

	BuildLock::release ();
}

check ('nenhum arquivo temporário sobra', glob ($data .'/.busy/*') === []);

// --- /proc de verdade ------------------------------------------------------------------------------------------------

if (is_readable ('/proc/self/stat'))
{
	$start = BuildLock::startOf ($me);

	check ('início deste processo lido do /proc', is_string ($start) && ctype_digit ($start), var_export ($start, TRUE));
	check ('processo inexistente: sem início', BuildLock::startOf (4194000) === NULL);
	check ('sem probe: pega e solta pela consulta real', BuildLock::acquire ($data, $a, 'deploy') === TRUE && BuildLock::parse (file_get_contents ($fileA)) ['start'] === $start);

	BuildLock::release ();

	// Outro processo de verdade segurando a trava: um `sleep` filho.
	$child = proc_open ([ 'sleep', '30' ], [], $pipes);
	$childPid = proc_get_status ($child) ['pid'];

	file_put_contents ($fileA, json_encode ([ 'pid' => $childPid, 'start' => BuildLock::startOf ($childPid), 'operation' => 'restart', 'since' => date (DATE_ATOM) ]));

	$busy = BuildLock::acquire ($data, $a, 'deploy');

	check ('processo real vivo: build ocupada', is_array ($busy) && $busy ['pid'] === $childPid, json_encode ($busy));

	proc_terminate ($child, 9);
	proc_close ($child);

	check ('processo real morto: assume a trava', BuildLock::acquire ($data, $a, 'deploy') === TRUE);

	BuildLock::release ();
}
else
	echo "skip /proc (fora do Linux)\n";

// --- sistema de arquivos do /data ------------------------------------------------------------------------------------

$mountinfo = implode ("\n", [
	'1512 1380 0:98 / / rw,relatime master:412 - overlay overlay rw,lowerdir=/var/lib/docker/overlay2/l/A,upperdir=/var/lib/docker/overlay2/b/diff',
	'1513 1512 0:101 / /proc rw,nosuid,nodev,noexec,relatime - proc proc rw',
	'1520 1512 0:55 /SSDS_plat_dev/dockerdata/volumes/releaser /data rw,relatime - nfs4 nfs-server.example:/dados/SSDS_plat_dev rw,vers=4.2,rsize=1048576,hard,proto=tcp',
	'1521 1512 8:1 /var/lib/docker/containers/abc/hostname /etc/hostname rw,relatime - ext4 /dev/sda1 rw',
	'1522 1512 0:24 /docker.sock /run/docker.sock rw,nosuid,nodev - tmpfs tmpfs rw,size=401M',
	'1523 1512 8:17 / /mnt/disco\040local rw,relatime shared:9 - xfs /dev/sdb1 rw'
]);

$fs = DataVolume::filesystem ('/data', $mountinfo);

check ('/data em NFS: tipo, origem e rede', $fs && $fs ['type'] === 'nfs4' && $fs ['source'] === 'nfs-server.example:/dados/SSDS_plat_dev' && $fs ['network'] === TRUE, json_encode ($fs));

$fs = DataVolume::filesystem ('/data/', $mountinfo);

check ('barra no fim do caminho', $fs && $fs ['mount'] === '/data');

$fs = DataVolume::filesystem ('/database', $mountinfo);

check ('/database não é /data: cai na raiz', $fs && $fs ['mount'] === '/' && $fs ['type'] === 'overlay' && $fs ['network'] === FALSE, json_encode ($fs));

$fs = DataVolume::filesystem ('/data/apps/proj', $mountinfo);

check ('subdiretório herda a montagem do /data', $fs && $fs ['mount'] === '/data' && $fs ['network'] === TRUE);

$fs = DataVolume::filesystem ('/mnt/disco local/releaser', $mountinfo);

check ('ponto de montagem com espaço (\\040), campo opcional e disco local', $fs && $fs ['mount'] === '/mnt/disco local' && $fs ['type'] === 'xfs' && $fs ['network'] === FALSE, json_encode ($fs));

check ('mountinfo vazio: não identificado', DataVolume::filesystem ('/data', '') === NULL);

foreach ([ 'nfs' => TRUE, 'nfs4' => TRUE, 'cifs' => TRUE, 'smb3' => TRUE, 'ceph' => TRUE, 'fuse.sshfs' => TRUE, 'ext4' => FALSE, 'xfs' => FALSE, 'btrfs' => FALSE, 'overlay' => FALSE, 'virtiofs' => FALSE, 'fuse.lxcfs' => FALSE ] as $type => $network)
{
	$fs = DataVolume::filesystem ('/data', '10 1 0:1 / /data rw - '. $type .' origem rw');

	check ('tipo '. $type .': '. ($network ? 'rede' : 'local'), $fs && $fs ['network'] === $network);
}

// ---------------------------------------------------------------------------------------------------------------------

@rmdir ($data .'/.busy');
@rmdir ($data);

echo "\n". ($total - $failures) ."/". $total ." ok\n";

exit ($failures ? 1 : 0);
