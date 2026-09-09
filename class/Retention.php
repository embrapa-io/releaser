<?php

/**
 * Política de retenção de backups (GFS — Grandfather-Father-Son) por CONTAGEM
 * de períodos com backup, não por idade — a ausência de backups novos nunca
 * provoca exclusão: se hoje não houve backup, o de 7 dias atrás continua entre
 * os 7 dias mais recentes e é preservado.
 *
 * Os níveis são disjuntos (um arquivo é contado em um único nível), do mais
 * fino para o mais grosso:
 *   D (diário):  o backup mais recente de cada um dos N dias mais recentes;
 *   W (semanal): entre os arquivos restantes, o mais recente de cada uma das N
 *                semanas ISO mais recentes que ainda não têm arquivo mantido
 *                (a semana parcialmente coberta pelos diários é pulada);
 *   M (mensal):  entre os arquivos restantes, o MAIS ANTIGO de cada um dos N
 *                meses mais recentes que ainda têm arquivos.
 *
 * Escolher o mais antigo no nível mensal torna a seleção estável: uma vez que
 * um arquivo vira o "mensal" do seu mês, ele não é trocado até sair da janela.
 * Em regime permanente, com backups diários, o resultado é: os 7 últimos dias,
 * os 4 domingos anteriores (último backup de cada semana ISO) e o primeiro
 * backup de cada um dos 3 meses anteriores — 14 arquivos.
 *
 * Arquivos cujo nome não contém data (ex.: um dump colocado à mão no volume)
 * não são considerados backups da política: são preservados e ignorados.
 *
 * Entre uma rotação e a próxima podem existir alguns arquivos a mais que o
 * total da política (novos backups gerados depois da última rotação).
 */
class Retention
{
    const DEFAULT_POLICY = [
        'daily' => 7,
        'weekly' => 4,
        'monthly' => 3,
        // Arquivos sem data no nome: 'ignore' (preservar, nunca apagar — padrão)
        // ou 'mtime' (tratar como backups usando a data de modificação).
        'undated' => 'ignore'
    ];

    /**
     * Normaliza o atributo `auto.cleaner` do builds.json em uma política.
     * Aceita TRUE (política padrão), FALSE/ausente (desligado) ou um objeto com
     * qualquer combinação de `daily`, `weekly`, `monthly` (inteiros >= 0) e
     * `undated` ('ignore' | 'mtime').
     *
     * @return array|FALSE Política normalizada ou FALSE se desligado.
     */
    static public function policy ($auto)
    {
        if ($auto === NULL || $auto === FALSE) return FALSE;

        $policy = self::DEFAULT_POLICY;

        if ($auto === TRUE) return $policy;

        if (is_object ($auto)) $auto = get_object_vars ($auto);

        if (!is_array ($auto)) return FALSE;

        foreach ([ 'daily', 'weekly', 'monthly' ] as $key)
            if (array_key_exists ($key, $auto) && is_numeric ($auto [$key]) && intval ($auto [$key]) >= 0)
                $policy [$key] = intval ($auto [$key]);

        if (array_key_exists ('undated', $auto) && in_array ($auto ['undated'], [ 'ignore', 'mtime' ], TRUE))
            $policy ['undated'] = $auto ['undated'];

        return $policy;
    }

    /**
     * Extrai o instante do backup a partir do nome do arquivo. Reconhece as
     * duas convenções em uso nos boilerplates da plataforma:
     *   2026_09_07_02_00_00_proj_app_stage_1.2.3-4.tar.gz  (data como prefixo)
     *   proj_app_stage_1.2.3-4_2026-09-07_02-00-00.tar.gz  (data como sufixo)
     * e, de forma geral, qualquer AAAA?MM?DD?HH?MM?SS com separadores - _ T :
     *
     * @return int|NULL Unix timestamp (UTC) ou NULL se não houver data no nome.
     */
    static public function timestampFromName ($name)
    {
        if (!preg_match ('/(?<!\d)(\d{4})[-_](\d{2})[-_](\d{2})[-_T](\d{2})[-_:](\d{2})[-_:](\d{2})(?!\d)/', $name, $m))
            return NULL;

        $ts = gmmktime (intval ($m [4]), intval ($m [5]), intval ($m [6]), intval ($m [2]), intval ($m [3]), intval ($m [1]));

        return $ts === FALSE ? NULL : $ts;
    }

    /**
     * Calcula o plano de rotação.
     *
     * @param array $files  Lista de ['name' => string, 'mtime' => int]. O instante
     *                      é lido do nome do arquivo; arquivos SEM data no nome
     *                      não são backups reconhecidos: ficam em 'ignore' e
     *                      nunca são apagados (o mtime só serve para ordená-los).
     * @param array $policy ['daily' => int, 'weekly' => int, 'monthly' => int]
     * @return array ['keep' => [name => 'D/W/M'], 'delete' => [name, ...], 'ignore' => [name, ...]]
     *               todos ordenados do mais recente para o mais antigo.
     */
    static public function plan (array $files, array $policy)
    {
        $items = [];
        $ignore = [];

        foreach ($files as $file)
        {
            $name = $file ['name'];

            $ts = self::timestampFromName ($name);

            if ($ts === NULL)
            {
                if (($policy ['undated'] ?? 'ignore') !== 'mtime') { $ignore [] = [ 'name' => $name, 'ts' => intval ($file ['mtime']) ]; continue; }

                $ts = intval ($file ['mtime']);
            }

            $items [] = [ 'name' => $name, 'ts' => $ts ];
        }

        usort ($ignore, function ($a, $b) { return $b ['ts'] <=> $a ['ts']; });

        usort ($items, function ($a, $b) { return $b ['ts'] <=> $a ['ts'] ?: strcmp ($b ['name'], $a ['name']); });

        $levels = [
            'D' => [ 'limit' => intval ($policy ['daily']),   'key' => 'Y-m-d', 'pick' => 'newest', 'skipCovered' => FALSE ],
            'W' => [ 'limit' => intval ($policy ['weekly']),  'key' => 'o-\WW', 'pick' => 'newest', 'skipCovered' => TRUE ],
            'M' => [ 'limit' => intval ($policy ['monthly']), 'key' => 'Y-m',   'pick' => 'oldest', 'skipCovered' => FALSE ]
        ];

        $keep = [];

        foreach ($levels as $tag => $level)
        {
            // períodos deste nível que já possuem algum arquivo mantido por níveis mais finos
            $covered = [];

            foreach ($items as $item)
                if (array_key_exists ($item ['name'], $keep))
                    $covered [gmdate ($level ['key'], $item ['ts'])] = TRUE;

            // arquivos ainda não mantidos, agrupados por período (ordem: do mais recente ao mais antigo)
            $groups = [];

            foreach ($items as $item)
                if (!array_key_exists ($item ['name'], $keep))
                    $groups [gmdate ($level ['key'], $item ['ts'])][] = $item;

            $count = 0;

            foreach ($groups as $key => $group)
            {
                if ($level ['skipCovered'] && array_key_exists ($key, $covered)) continue;

                if ($count >= $level ['limit']) break;

                $chosen = $level ['pick'] === 'oldest' ? $group [sizeof ($group) - 1] : $group [0];

                $keep [$chosen ['name']] = $tag;

                $count++;
            }
        }

        $delete = [];

        foreach ($items as $item)
            if (!array_key_exists ($item ['name'], $keep))
                $delete [] = $item ['name'];

        // preserva a ordem (mais recente primeiro) também em 'keep'
        $ordered = [];

        foreach ($items as $item)
            if (array_key_exists ($item ['name'], $keep))
                $ordered [$item ['name']] = $keep [$item ['name']];

        return [ 'keep' => $ordered, 'delete' => $delete, 'ignore' => array_column ($ignore, 'name') ];
    }
}
