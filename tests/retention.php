<?php
/**
 * Testes da política de retenção (class/Retention.php), sem framework.
 *
 * Uso: php tests/retention.php — sai com código 1 se algum caso falhar.
 */

error_reporting (E_ALL);

require_once __DIR__ . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'class'. DIRECTORY_SEPARATOR .'Retention.php';

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

// Um backup por dia, às 02:00 UTC, de $from a $to (inclusive), com o nome no padrão dos boilerplates.
function daily ($from, $to, $prefix = 'teste_api_release_1.0.0-1_')
{
	$files = [];

	for ($ts = gmmktime (2, 0, 0, intval (substr ($from, 5, 2)), intval (substr ($from, 8, 2)), intval (substr ($from, 0, 4))), $end = gmmktime (2, 0, 0, intval (substr ($to, 5, 2)), intval (substr ($to, 8, 2)), intval (substr ($to, 0, 4))); $ts <= $end; $ts += 86400)
		$files [] = [ 'name' => $prefix . gmdate ('Y-m-d_H-i-s', $ts) .'.tar.gz', 'mtime' => $ts ];

	return $files;
}

// Data (AAAA-MM-DD) => marca, para comparar o 'keep' de forma legível.
function kept ($plan)
{
	$out = [];

	foreach ($plan ['keep'] as $name => $tag)
		$out [preg_replace ('/^.*_(\d{4}-\d{2}-\d{2})_.*$/', '$1', $name)] = $tag;

	return $out;
}

function policy ($spec, $undated = FALSE)
{
	return Retention::policy ($undated ? 'undated' : TRUE, Retention::parse ($spec));
}

// ---------------------------------------------------------------------------
// parse ()

check ('parse: vazia = padrão 7/4/3/1', Retention::parse ('') === [ 'daily' => 7, 'weekly' => 4, 'monthly' => 3, 'yearly' => 1 ]);
check ('parse: NULL = padrão', Retention::parse (NULL) === Retention::parse ('7/4/3/1'));
check ('parse: só espaços = padrão', Retention::parse ("  \r") === Retention::parse ('7/4/3/1'));
check ('parse: válida', Retention::parse ('30/12/12/5') === [ 'daily' => 30, 'weekly' => 12, 'monthly' => 12, 'yearly' => 5 ]);
check ('parse: com \r do Windows', Retention::parse ("14/8/6/2\r") === [ 'daily' => 14, 'weekly' => 8, 'monthly' => 6, 'yearly' => 2 ]);
check ('parse: zeros', Retention::parse ('0/0/0/0') === [ 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0 ]);

foreach ([ '7/4/3', '7/4/3/1/2', '7/4/-3/1', '7/4/3.5/1', 'a/b/c/d', '7,4,3,1', '7/4//1', '7 / 4 / 3 / 1', '/7/4/3/1', 'true', '12345/4/3/1' ] as $bad)
{
	try
	{
		Retention::parse ($bad);

		check ("parse: inválida '". $bad ."' lança exceção", FALSE, 'não lançou');
	}
	catch (Exception $e)
	{
		check ("parse: inválida '". $bad ."' lança exceção", strpos ($e->getMessage (), "'". trim ($bad) ."'") !== FALSE, $e->getMessage ());
	}
}

// ---------------------------------------------------------------------------
// policy ()

check ('policy: true = padrão com 1 anual', Retention::policy (TRUE) === Retention::DEFAULT_POLICY && Retention::DEFAULT_POLICY ['yearly'] === 1);
check ('policy: false/ausente = desligado', Retention::policy (FALSE) === FALSE && Retention::policy (NULL) === FALSE && Retention::policy ('x') === FALSE);
check ('policy: "undated" usa mtime', Retention::policy ('undated') ['undated'] === 'mtime' && Retention::policy (TRUE) ['undated'] === 'ignore');
check ('policy: contagens de RETENTION', Retention::policy (TRUE, Retention::parse ('1/2/3/4')) === [ 'daily' => 1, 'weekly' => 2, 'monthly' => 3, 'yearly' => 4, 'undated' => 'ignore' ]);
check ('describe', Retention::describe (Retention::DEFAULT_POLICY) === '7 daily, 4 weekly, 3 monthly and 1 yearly');

// ---------------------------------------------------------------------------
// timestampFromName ()

check ('data como sufixo', Retention::timestampFromName ('p_a_release_1.2.3-4_2026-09-07_02-00-00.tar.gz') === gmmktime (2, 0, 0, 9, 7, 2026));
check ('data como prefixo', Retention::timestampFromName ('2026_09_07_02_00_00_p_a_release_1.2.3-4.tar.gz') === gmmktime (2, 0, 0, 9, 7, 2026));
check ('sem data', Retention::timestampFromName ('p_a_release_dump.sql.gz') === NULL);

// ---------------------------------------------------------------------------
// plan (): política padrão em regime permanente (backups diários desde 2025)

$files = daily ('2025-01-01', '2026-10-23');

$plan = Retention::plan ($files, policy (''));

$expected = [
	'2026-10-23' => 'D', '2026-10-22' => 'D', '2026-10-21' => 'D', '2026-10-20' => 'D', '2026-10-19' => 'D', '2026-10-18' => 'D', '2026-10-17' => 'D',
	'2026-10-11' => 'W', '2026-10-04' => 'W', '2026-09-27' => 'W', '2026-09-20' => 'W',
	'2026-10-01' => 'M', '2026-09-01' => 'M', '2026-08-01' => 'M',
	'2026-01-01' => 'Y'
];

ksort ($expected);

$got = kept ($plan);

ksort ($got);

check ('padrão 7/4/3/1: 15 arquivos (7 D, 4 W, 3 M, 1 Y = primeiro de 2026)', $got === $expected, json_encode ($got));
check ('padrão: o resto é apagado', sizeof ($plan ['delete']) === sizeof ($files) - 15 && !sizeof ($plan ['ignore']));

// sem a geração anual, o comportamento anterior (14 arquivos)
$got = kept (Retention::plan ($files, policy ('7/4/3/0')));

check ('7/4/3/0: 14 arquivos, sem anual', sizeof ($got) === 14 && !in_array ('Y', $got) && !isset ($got ['2026-01-01']), json_encode ($got));

// anual com 2: o primeiro de 2026 e o primeiro de 2025
$got = kept (Retention::plan ($files, policy ('7/4/3/2')));

check ('7/4/3/2: primeiro de 2026 e primeiro de 2025', sizeof ($got) === 16 && ($got ['2026-01-01'] ?? '') === 'Y' && ($got ['2025-01-01'] ?? '') === 'Y', json_encode ($got));

// anual com 5, mas só há 2 anos com backup: guarda os 2
$got = kept (Retention::plan ($files, policy ('7/4/3/5')));

check ('7/4/3/5 com 2 anos de backup: 2 anuais', sizeof (array_filter ($got, function ($t) { return strpos ($t, 'Y') !== FALSE; })) === 2, json_encode ($got));

// só anuais
$got = kept (Retention::plan ($files, policy ('0/0/0/2')));

check ('0/0/0/2: só os primeiros de 2026 e 2025', $got === [ '2026-01-01' => 'Y', '2025-01-01' => 'Y' ], json_encode ($got));

// anos sem backup não contam: 2022 e 2026, nada entre eles
$gap = array_merge (daily ('2022-03-10', '2022-03-20'), daily ('2026-10-01', '2026-10-23'));

$got = kept (Retention::plan ($gap, policy ('7/4/3/2')));

check ('anos sem backup: A = 2 guarda o primeiro de 2026 e o primeiro de 2022 (10/03)', ($got ['2026-10-01'] ?? '') === 'MY' && ($got ['2022-03-10'] ?? '') === 'MY' && sizeof (array_filter ($got, function ($t) { return strpos ($t, 'Y') !== FALSE; })) === 2, json_encode ($got));

// o primeiro do ano já mantido por um nível mais fino: só ganha a marca, sem duplicar
$january = daily ('2026-01-01', '2026-02-20');

$got = kept (Retention::plan ($january, policy ('7/4/3/1')));

check ('primeiro do ano já mensal: marca MY, sem outro arquivo do ano', ($got ['2026-01-01'] ?? '') === 'MY' && sizeof ($got) === 7 + 4 + 2, json_encode ($got));

// ano novo com A = 1: o anual passa a ser o do ano corrente (o de 2026 sai)
$newYear = daily ('2026-01-01', '2027-01-05');

$got = kept (Retention::plan ($newYear, policy ('7/4/3/1')));

check ('janeiro com A = 1: anual é o 1º de 2027 (também diário), 1º de 2026 sai', ($got ['2027-01-01'] ?? '') === 'DY' && !isset ($got ['2026-01-01']), json_encode ($got));

$got = kept (Retention::plan ($newYear, policy ('7/4/3/2')));

check ('janeiro com A = 2: 1º de 2026 continua', ($got ['2027-01-01'] ?? '') === 'DY' && ($got ['2026-01-01'] ?? '') === 'Y', json_encode ($got));

// ---------------------------------------------------------------------------
// estabilidade e ausência de backups novos

$plan = Retention::plan ($files, policy (''));

$survivors = array_values (array_filter ($files, function ($f) use ($plan) { return array_key_exists ($f ['name'], $plan ['keep']); }));

$again = Retention::plan ($survivors, policy (''));

check ('idempotente: rodar de novo sobre o que ficou não apaga nada', !sizeof ($again ['delete']) && $again ['keep'] === $plan ['keep']);

// a cada dia novo, o anual de 2026 continua o mesmo
$stable = TRUE;

foreach ([ '2026-11-15', '2026-12-31' ] as $day)
{
	$got = kept (Retention::plan (daily ('2025-01-01', $day), policy ('')));

	if (($got ['2026-01-01'] ?? '') !== 'Y') $stable = FALSE;
}

check ('estável: o anual não troca ao longo do ano', $stable);

$old = daily ('2024-05-01', '2024-05-07');

$plan = Retention::plan ($old, policy (''));

check ('sem backups novos há anos: os 7 últimos continuam (nada é apagado)', sizeof ($plan ['keep']) === 7 && !sizeof ($plan ['delete']));

// a rotação incremental (um backup novo por dia, apagando a cada dia) chega ao mesmo
// conjunto que o plano calculado de uma vez sobre o histórico completo: a ordem das
// exclusões não muda a escolha, e nenhum arquivo apagado num dia seria mantido depois
foreach ([ '7/4/3/1', '7/4/3/2', '14/8/12/3', '3/1/1/1' ] as $spec)
{
	$survivors = [];
	$history = [];
	$mismatch = NULL;

	foreach (daily ('2025-01-01', '2027-03-01') as $file)
	{
		$history [] = $file;
		$survivors [] = $file;

		$plan = Retention::plan ($survivors, policy ($spec));

		$survivors = array_values (array_filter ($survivors, function ($f) use ($plan) { return !in_array ($f ['name'], $plan ['delete']); }));

		$kept = array_column ($survivors, 'name');
		$full = array_keys (Retention::plan ($history, policy ($spec)) ['keep']);

		sort ($kept);
		sort ($full);

		if ($kept !== $full && $mismatch === NULL) $mismatch = $file ['name'];
	}

	check ('incremental diário por 2 anos = plano sobre o histórico completo ('. $spec .')', $mismatch === NULL, 'primeira divergência em '. $mismatch);
}

// semana ISO na virada do ano: 28/12/2026 a 03/01/2027 é UMA semana (2026-W53); a chave
// usa o ano ISO ('o'), senão a semana se partiria em duas e um arquivo a mais ficaria
$got = kept (Retention::plan (daily ('2026-11-01', '2027-01-10'), policy ('0/4/0/0')));

check ('semana ISO na virada do ano: 4 semanais = 10/01, 03/01, 27/12 e 20/12', $got === [ '2027-01-10' => 'W', '2027-01-03' => 'W', '2026-12-27' => 'W', '2026-12-20' => 'W' ], json_encode ($got));

// virada de mês: no dia 8 o dia 1º sai dos diários e vira o mensal do mês corrente
$got = kept (Retention::plan (daily ('2026-06-01', '2026-10-08'), policy ('7/0/3/0')));

check ('virada de mês: 1º do mês corrente vira M ao sair dos diários', ($got ['2026-10-01'] ?? '') === 'M' && ($got ['2026-09-01'] ?? '') === 'M' && ($got ['2026-08-01'] ?? '') === 'M' && !isset ($got ['2026-07-01']), json_encode ($got));

// ---------------------------------------------------------------------------
// arquivos sem data no nome

$mixed = array_merge (daily ('2026-01-01', '2026-10-23'), [
	[ 'name' => 'teste_api_release_dump-manual.sql.gz', 'mtime' => gmmktime (12, 0, 0, 3, 15, 2025) ],
	[ 'name' => 'teste_api_release_latest.tar.gz', 'mtime' => gmmktime (12, 0, 0, 10, 23, 2026) ]
]);

$plan = Retention::plan ($mixed, policy (''));

check ('sem data: preservados e ignorados', $plan ['ignore'] === [ 'teste_api_release_latest.tar.gz', 'teste_api_release_dump-manual.sql.gz' ] && !in_array ('teste_api_release_dump-manual.sql.gz', $plan ['delete']) && !array_key_exists ('teste_api_release_dump-manual.sql.gz', $plan ['keep']), json_encode ($plan ['ignore']));

$plan = Retention::plan ($mixed, policy ('', TRUE));

check ('sem data em "undated" (A = 1): o de 2025, pela data de modificação, sai', !sizeof ($plan ['ignore']) && in_array ('teste_api_release_dump-manual.sql.gz', $plan ['delete']));

$plan = Retention::plan ($mixed, policy ('7/4/3/2', TRUE));

check ('sem data em "undated" (A = 2): o de 2025 vira o anual do ano', ($plan ['keep'] ['teste_api_release_dump-manual.sql.gz'] ?? '') === 'Y', json_encode (array_slice ($plan ['keep'], -3)));

check ('sem data em "undated": o de hoje vira diário', ($plan ['keep'] ['teste_api_release_latest.tar.gz'] ?? '') === 'D');

// ---------------------------------------------------------------------------
// Backup feito antes de um restore ('_before-restore_'): fora da rotação por 30 dias

$now = gmmktime (12, 0, 0, 10, 23, 2026);

$withBefore = array_merge (daily ('2026-09-01', '2026-10-23'), [
	[ 'name' => 'teste_api_release_1.0.0-1_before-restore_2026-10-20_14-00-00.tar.gz', 'mtime' => 0 ],
	[ 'name' => 'teste_api_release_1.0.0-1_before-restore_2026-09-10_14-00-00.tar.gz', 'mtime' => 0 ],
	[ 'name' => 'teste_api_release_before-restore_sem-data.tar.gz', 'mtime' => 0 ]
]);

$plan = Retention::plan ($withBefore, policy ('7/0/0/0'), $now);

check ('pré-restore de 3 dias: mantido com a marca R', ($plan ['keep'] ['teste_api_release_1.0.0-1_before-restore_2026-10-20_14-00-00.tar.gz'] ?? '') === 'R', json_encode (array_slice ($plan ['keep'], 0, 9)));

check ('pré-restore protegido não ocupa um diário: os 7 dias continuam', sizeof (array_filter ($plan ['keep'], function ($t) { return $t === 'D'; })) === 7);

check ('pré-restore de 43 dias: volta à rotação e sai com 7/0/0/0', in_array ('teste_api_release_1.0.0-1_before-restore_2026-09-10_14-00-00.tar.gz', $plan ['delete']));

check ('pré-restore sem data: ignorado como qualquer arquivo sem data', in_array ('teste_api_release_before-restore_sem-data.tar.gz', $plan ['ignore']));

check ('shielded: 30 dias exatos ainda protegido, 31 não', Retention::shielded ('x_before-restore_2026-09-23_12-00-00.tar.gz', $now) && !Retention::shielded ('x_before-restore_2026-09-22_12-00-00.tar.gz', $now));

check ('shielded: sem a marca, nunca', !Retention::shielded ('x_2026-10-22_12-00-00.tar.gz', $now));

$keys = array_keys ($plan ['keep']);

check ('keep segue do mais recente ao mais antigo com o protegido no meio', array_search ('teste_api_release_1.0.0-1_before-restore_2026-10-20_14-00-00.tar.gz', $keys) === 3, json_encode ($keys));

// ---------------------------------------------------------------------------

echo "\n". ($total - $failures) ." of ". $total ." test(s) passed. \n";

exit ($failures ? 1 : 0);
