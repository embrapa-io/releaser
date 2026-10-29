<?php
/**
 * Testes da referência da imagem de cada container (plugin/Orchestrator.php,
 * parseReferences), que o `io health` manda ao core como `image` de cada
 * serviço: o texto do compose ("postgres:18-alpine"), e não o ID que o `docker
 * ps` mostra quando a tag foi puxada de novo depois que o container subiu.
 *
 * Uso: php tests/references.php — sai com código 1 se algum caso falhar.
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

$id = function ($short) { return $short . str_repeat ('0', 64 - strlen ($short)); };

$refs = Orchestrator::parseReferences ([
	$id ('8a1b2c3d4e5f') .'|postgres:18-alpine',
	$id ('111111111111') .'|127.0.0.1:5000/pasto-certo_pwa_release_pwa',
	$id ('222222222222') .'|sha256:'. str_repeat ('ab', 32),
	$id ('333333333333') .'|c293117fcecd',
	$id ('444444444444') .'|nginx:1.27@sha256:'. str_repeat ('cd', 32),
	$id ('555555555555') .'|php@sha256:'. str_repeat ('ef', 32),
	$id ('666666666666') .'|registry.example:5000/org/app:1.2.3@sha256:'. str_repeat ('01', 32),
	$id ('777777777777') .'|',
	'linha sem separador',
	''
]);

check ('ID completo do inspect vira a chave de 12 caracteres', ($refs ['8a1b2c3d4e5f'] ?? NULL) === 'postgres:18-alpine', json_encode ($refs));
check ('registro local com porta é mantido', ($refs ['111111111111'] ?? NULL) === '127.0.0.1:5000/pasto-certo_pwa_release_pwa');
check ('Config.Image que é sha256:<ID> é descartado', !isset ($refs ['222222222222']));
check ('Config.Image que é ID curto é descartado', !isset ($refs ['333333333333']));
check ('Swarm: digest fixado pelo serviço sai quando há tag', ($refs ['444444444444'] ?? NULL) === 'nginx:1.27');
check ('fixada só por digest, sem tag, fica como está', ($refs ['555555555555'] ?? NULL) === 'php@sha256:'. str_repeat ('ef', 32));
check ('registro com porta, tag e digest: sai só o digest', ($refs ['666666666666'] ?? NULL) === 'registry.example:5000/org/app:1.2.3');
check ('referência vazia e linhas inválidas são ignoradas', !isset ($refs ['777777777777']) && sizeof ($refs) === 5, json_encode (array_keys ($refs)));
check ('sem IDs, references não chama o Docker', Orchestrator::references ([]) === []);

echo "\n". ($total - $failures) ."/". $total ." ok\n";

exit ($failures ? 1 : 0);
