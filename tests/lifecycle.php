<?php
/**
 * Testes da marca de parada (`io stop`) e da desativação do arquivo da build (`io remove`), em class/Lifecycle.php.
 * O comportamento ponta a ponta (contêineres, volumes e o relato ao core) está em tests/remove-integration.sh.
 *
 * Uso: php tests/lifecycle.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'Lifecycle.php';

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

function throws ($fn)
{
	try { $fn (); } catch (Exception $e) { return $e->getMessage (); }

	return NULL;
}

$data = sys_get_temp_dir () . DIRECTORY_SEPARATOR .'releaser-lifecycle-'. getmypid ();

mkdir ($data . DIRECTORY_SEPARATOR .'builds', 0755, TRUE);

$b = (object) [ 'project' => 'pasto-certo', 'app' => 'pwa', 'stage' => 'release' ];
$other = (object) [ 'project' => 'pasto-certo', 'app' => 'pwa', 'stage' => 'beta' ];

echo "Marca de parada\n";

check ('namespace', Lifecycle::namespace ($b) === 'pasto-certo_pwa_release');
check ('marca em /data/.stopped/<ns>.json', Lifecycle::marker ($data, $b) === $data .'/.stopped/pasto-certo_pwa_release.json');
check ('sem marca: não parada', !Lifecycle::isStopped ($data, $b) && Lifecycle::stopped ($data, $b) === NULL);
check ('clearStopped sem marca devolve FALSE', Lifecycle::clearStopped ($data, $b) === FALSE);

$first = Lifecycle::markStopped ($data, $b, '1.26.11-3');

check ('markStopped cria a pasta e a marca', Lifecycle::isStopped ($data, $b));
check ('marca guarda versão e data', $first ['version'] === '1.26.11-3' && strtotime ($first ['at']) > 0);
check ('outro estágio não é afetado', !Lifecycle::isStopped ($data, $other));

file_put_contents (Lifecycle::marker ($data, $b), json_encode ([ 'version' => '1.26.11-3', 'at' => '2026-11-01T10:00:00-03:00' ]));

$again = Lifecycle::markStopped ($data, $b, '1.26.11-3');

check ('segundo stop mantém a data da primeira parada', $again ['at'] === '2026-11-01T10:00:00-03:00');

file_put_contents (Lifecycle::marker ($data, $b), 'lixo');

check ('marca ilegível ainda conta como parada', Lifecycle::isStopped ($data, $b) && is_array (Lifecycle::stopped ($data, $b)));
check ('clearStopped devolve TRUE e apaga', Lifecycle::clearStopped ($data, $b) === TRUE && !Lifecycle::isStopped ($data, $b));

echo "\nDesativação do arquivo da build\n";

$file = $data .'/builds/pasto-certo_pwa_release.json';

file_put_contents ($file, '{}');

check ('nome desativado: .json → .bkp', Lifecycle::disabledName ($file) === $data .'/builds/pasto-certo_pwa_release.bkp');

$msg = throws (function () use ($data) { Lifecycle::disabledName ($data .'/builds.json'); });

check ('legado builds.json é recusado e sugere io split', $msg !== NULL && strpos ($msg, 'io split') !== FALSE, (string) $msg);
check ('arquivo sem .json é recusado', throws (function () use ($data) { Lifecycle::disabledName ($data .'/builds/pasto-certo_pwa_release.bkp'); }) !== NULL);
check ('.json fora de /data/builds é recusado', throws (function () use ($data) { Lifecycle::disabledName ($data .'/outra/pasto-certo_pwa_release.json'); }) !== NULL);

$target = Lifecycle::disable ($file);

check ('disable renomeia, sem apagar o conteúdo', !file_exists ($file) && file_get_contents ($target) === '{}');

file_put_contents ($file, '{"segunda":1}');

$now = mktime (14, 30, 5, 11, 13, 2026);
$second = Lifecycle::disable ($file, $now);

check ('com .bkp existente, usa .bkp-AAAAMMDD-HHMMSS', $second === $data .'/builds/pasto-certo_pwa_release.bkp-20261113-143005', $second);
check ('o .bkp anterior não é sobrescrito', file_get_contents ($target) === '{}' && file_get_contents ($second) === '{"segunda":1}');

echo "\nO que fica no servidor\n";

$kept = Lifecycle::kept ($data, $b, [ 'pasto-certo_pwa_release_data', 'pasto-certo_pwa_release_backup' ], '/root/backup');

check ('um item por volume, com o comando manual', sizeof (array_filter ($kept, function ($l) { return strpos ($l, 'docker volume rm pasto-certo_pwa_release_') !== FALSE; })) === 2);
check ('clone das versões e backups listados', strpos (implode ("\n", $kept), $data .'/apps/pasto-certo/pwa') !== FALSE && strpos (implode ("\n", $kept), '/root/backup') !== FALSE);
check ('sem BACKUP_DIR, sem a linha de backups', sizeof (Lifecycle::kept ($data, $b, [], '')) === 1);

exec ('rm -rf '. escapeshellarg ($data));

echo "\n". ($total - $failures) ."/". $total ." ok". ($failures ? ", ". $failures ." FAIL" : "") ."\n";

exit ($failures ? 1 : 0);
