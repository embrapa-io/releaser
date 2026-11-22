<?php
/**
 * Testes das partes puras da varredura de CVEs (class/Trivy.php): o que vai ao
 * core de cada vulnerabilidade (`vulnerabilities`) e os digests da imagem
 * varrida (`repoDigests`).
 *
 * O formato é o do Trivy 0.76: para pacote de linguagem instalado, o `Target`
 * do resultado é um agregado ("Python", "Node.js") e o arquivo fica no
 * `PkgPath` de cada vulnerabilidade.
 *
 * Uso: php tests/trivy.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'Trivy.php';

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

$a = 'sha256:'. str_repeat ('a', 64);
$b = 'sha256:'. str_repeat ('b', 64);

$scan = json_decode (json_encode ([
	'Metadata' => [ 'RepoTags' => [ 'dpage/pgadmin4:9' ], 'RepoDigests' => [ 'dpage/pgadmin4@'. $a, 'mirror.example/dpage/pgadmin4@'. strtoupper ($a), 'outro@'. $b, 'lixo', 7 ] ],
	'Results' => [
		[
			'Target' => 'dpage/pgadmin4:9 (alpine 3.24.3)', 'Class' => 'os-pkgs', 'Type' => 'alpine',
			'Vulnerabilities' => [
				[ 'VulnerabilityID' => 'CVE-2026-1', 'PkgName' => 'brotli-libs', 'InstalledVersion' => '1.2.0-r1', 'FixedVersion' => '1.2.1-r0', 'Severity' => 'CRITICAL', 'Title' => 't' ],
				[ 'VulnerabilityID' => 'CVE-2026-2', 'PkgName' => 'zlib', 'Severity' => 'MEDIUM' ],
				[ 'PkgName' => 'sem-id', 'Severity' => 'HIGH' ]
			]
		],
		[
			'Target' => 'Python', 'Class' => 'lang-pkgs', 'Type' => 'python-pkg',
			'Vulnerabilities' => [
				[ 'VulnerabilityID' => 'CVE-2026-1', 'PkgName' => 'brotli', 'InstalledVersion' => '1.2.0', 'FixedVersion' => '1.2.1', 'Severity' => 'high', 'PkgPath' => 'venv/lib/python3.15/site-packages/brotli-1.2.0.dist-info/METADATA' ],
				[ 'VulnerabilityID' => 'CVE-2026-3', 'PkgName' => 'setuptools', 'InstalledVersion' => '70.3.0', 'FixedVersion' => '78.1.1', 'Severity' => 'HIGH' ]
			]
		],
		[ 'Target' => 'Node.js', 'Class' => 'lang-pkgs', 'Type' => 'node-pkg' ]
	]
]));

$list = Trivy::vulnerabilities ($scan);

check ('só HIGH e CRITICAL com VulnerabilityID entram', sizeof ($list) === 3, json_encode ($list));
check ('pacote do sistema: class, type e target do resultado, sem path', $list [0] === [ 'id' => 'CVE-2026-1', 'package' => 'brotli-libs', 'installed' => '1.2.0-r1', 'fixed' => '1.2.1-r0', 'severity' => 'CRITICAL', 'title' => 't', 'class' => 'os-pkgs', 'type' => 'alpine', 'target' => 'dpage/pgadmin4:9 (alpine 3.24.3)' ], json_encode ($list [0]));
check ('pacote de linguagem instalado: target agregado e o arquivo em path', $list [1]['target'] === 'Python' && $list [1]['path'] === 'venv/lib/python3.15/site-packages/brotli-1.2.0.dist-info/METADATA' && $list [1]['severity'] === 'HIGH', json_encode ($list [1]));
check ('vulnerabilidade sem PkgPath: sem a chave path', !array_key_exists ('path', $list [2]), json_encode ($list [2]));

check ('digests do RepoDigests, em minúsculas e sem repetir', Trivy::repoDigests ($scan) === [ $a, $b ], json_encode (Trivy::repoDigests ($scan)));
check ('imagem construída no host: sem digest', Trivy::repoDigests (json_decode ('{"Metadata":{},"Results":[]}')) === []);
check ('saída sem Metadata: sem digest', Trivy::repoDigests (json_decode ('{"Results":[]}')) === [] && Trivy::repoDigests (NULL) === []);
check ('imagem ainda não varrida: sem digest', Trivy::digests ('nginx:stable-alpine') === []);

echo "\n". ($total - $failures) ."/". $total ." ok\n";

exit ($failures ? 1 : 0);
