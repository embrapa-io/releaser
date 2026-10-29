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
 *                meses mais recentes que ainda têm arquivos;
 *   Y (anual):   o PRIMEIRO backup de cada um dos N anos mais recentes que têm
 *                backup. Exceção à disjunção: se um nível mais fino já mantém
 *                esse arquivo (ex.: em janeiro, o mensal guarda o dia 1º), ele
 *                só ganha a marca Y e conta para o ano, sem que outro arquivo do
 *                mesmo ano seja escolhido no lugar.
 *
 * Escolher o mais antigo nos níveis mensal e anual torna a seleção estável: uma
 * vez que um arquivo vira o "mensal" do seu mês (ou o "anual" do seu ano), ele
 * não é trocado até sair da janela. Em regime permanente, com backups diários,
 * o resultado é: os 7 últimos dias, os 4 domingos anteriores (último backup de
 * cada semana ISO), o primeiro backup de cada um dos 3 meses anteriores e o
 * primeiro backup do ano — 15 arquivos (14 quando o primeiro do ano ainda está
 * entre os mensais). Com A = 1, o anual é o do ano corrente: o do ano anterior
 * sai na primeira rotação de janeiro. Para guardá-lo o ano todo, use A = 2.
 *
 * A política vem de uma string "D/S/M/A" (diários/semanais/mensais/anuais,
 * inteiros >= 0), lida por parse () — no /data/.env, RETENTION (volume local,
 * `cleaner`) e S3_RETENTION (bucket, `cloud`). Padrão: 7/4/3/1.
 *
 * Arquivos cujo nome não contém data (ex.: um dump colocado à mão no volume)
 * não são considerados backups da política: são preservados e ignorados.
 *
 * Entre uma rotação e a próxima podem existir alguns arquivos a mais que o
 * total da política (novos backups gerados depois da última rotação).
 *
 * Backups feitos antes de um restore (`io restore`, Backup S3 da plataforma),
 * com SHIELD_MARK no nome, ficam fora da política por SHIELD_DAYS dias a contar
 * da data do nome: são mantidos com a marca R e não contam em nenhum nível.
 * Depois disso, viram backups comuns. A mesma regra está no Doctor da
 * plataforma (backend, automaton/doctor/controller/backup/s3.php).
 */
class Retention
{
    const DEFAULT_SPEC = '7/4/3/1';

    // Marca do backup feito antes de um restore (ver shielded ()).
    const SHIELD_MARK = '_before-restore_';

    const SHIELD_DAYS = 30;

    const DEFAULT_POLICY = [
        'daily' => 7,
        'weekly' => 4,
        'monthly' => 3,
        'yearly' => 1,
        // Arquivos sem data no nome: 'ignore' (preservar, nunca apagar — padrão)
        // ou 'mtime' (tratar como backups usando a data de modificação).
        'undated' => 'ignore'
    ];

    /**
     * Lê a string "D/S/M/A" (RETENTION / S3_RETENTION): quatro inteiros >= 0
     * separados por barra — quantos dias, semanas, meses e anos guardar. Vazia
     * ou NULL = padrão (7/4/3/1). Malformada lança exceção (o chamador aborta
     * sem apagar nada).
     *
     * @return array ['daily' => int, 'weekly' => int, 'monthly' => int, 'yearly' => int]
     */
    static public function parse ($spec)
    {
        // trim: o /data/.env editado no Windows traz '\r' no fim de cada valor
        $spec = trim ((string) $spec);

        if ($spec === '') $spec = self::DEFAULT_SPEC;

        if (!preg_match ('/^(\d{1,4})\/(\d{1,4})\/(\d{1,4})\/(\d{1,4})$/', $spec, $m))
            throw new Exception ("Invalid retention '". $spec ."': use 'D/W/M/Y', four integers >= 0 separated by slashes, i.e., how many daily, weekly, monthly and yearly backups to keep (default '". self::DEFAULT_SPEC ."')");

        return [ 'daily' => intval ($m [1]), 'weekly' => intval ($m [2]), 'monthly' => intval ($m [3]), 'yearly' => intval ($m [4]) ];
    }

    /** A política em texto, para a saída: "7 daily, 4 weekly, 3 monthly and 1 yearly". */
    static public function describe (array $policy)
    {
        return intval ($policy ['daily']) .' daily, '. intval ($policy ['weekly']) .' weekly, '. intval ($policy ['monthly']) .' monthly and '. intval ($policy ['yearly'] ?? 0) .' yearly';
    }

    /**
     * Normaliza o atributo `auto.cleaner` (ou `auto.cloud`) do arquivo da build
     * em uma política:
     *   false / ausente -> desligado;
     *   true            -> política de $counts (padrão: 7 diários, 4 semanais,
     *                      3 mensais e 1 anual);
     *   "undated"       -> idem + arquivos sem data no nome entram na rotação
     *                      pela data de modificação (para apps fora do padrão
     *                      de nome dos boilerplates).
     *
     * @param array|NULL $counts Resultado de parse () (RETENTION/S3_RETENTION).
     * @return array|FALSE Política normalizada ou FALSE se desligado.
     */
    static public function policy ($auto, $counts = NULL)
    {
        $policy = is_array ($counts) ? array_merge (self::DEFAULT_POLICY, $counts) : self::DEFAULT_POLICY;

        if ($auto === TRUE) return $policy;

        if (is_string ($auto) && strtolower (trim ($auto)) === 'undated')
            return array_merge ($policy, [ 'undated' => 'mtime' ]);

        return FALSE;
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
     * Backup feito antes de um restore e ainda dentro dos SHIELD_DAYS dias (pela
     * data do nome; sem data, nunca protegido): fica fora da rotação.
     */
    static public function shielded ($name, $now = NULL)
    {
        if (strpos ($name, self::SHIELD_MARK) === FALSE) return FALSE;

        $ts = self::timestampFromName ($name);

        return $ts !== NULL && $ts >= ($now === NULL ? time () : $now) - self::SHIELD_DAYS * 86400;
    }

    /**
     * Calcula o plano de rotação.
     *
     * @param array $files  Lista de ['name' => string, 'mtime' => int]. O instante
     *                      é lido do nome do arquivo; arquivos SEM data no nome
     *                      não são backups reconhecidos: ficam em 'ignore' e
     *                      nunca são apagados (o mtime só serve para ordená-los).
     * @param array $policy ['daily' => int, 'weekly' => int, 'monthly' => int, 'yearly' => int]
     * @param int|NULL $now Instante de referência da proteção do pré-restore (testes).
     * @return array ['keep' => [name => 'D/W/M/Y' (ou 'DY', 'WY', 'MY'; 'R' = pré-restore protegido)], 'delete' => [name, ...], 'ignore' => [name, ...]]
     *               todos ordenados do mais recente para o mais antigo.
     */
    static public function plan (array $files, array $policy, $now = NULL)
    {
        $items = [];
        $ignore = [];
        $shield = [];

        foreach ($files as $file)
        {
            $name = $file ['name'];

            if (self::shielded ($name, $now)) { $shield [] = [ 'name' => $name, 'ts' => self::timestampFromName ($name) ]; continue; }

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

        // Y: o primeiro (mais antigo) backup de cada um dos N anos mais recentes com
        // backup, entre TODOS os arquivos datados, mantidos ou não pelos níveis acima.
        // $items vai do mais recente ao mais antigo: a chave de cada ano entra na
        // ordem do mais recente e o valor termina no mais antigo do ano.
        $years = [];

        foreach ($items as $item)
            $years [gmdate ('Y', $item ['ts'])] = $item;

        foreach (array_slice ($years, 0, max (0, intval ($policy ['yearly'] ?? 0)), TRUE) as $item)
            $keep [$item ['name']] = array_key_exists ($item ['name'], $keep) ? $keep [$item ['name']] .'Y' : 'Y';

        $delete = [];

        foreach ($items as $item)
            if (!array_key_exists ($item ['name'], $keep))
                $delete [] = $item ['name'];

        // pré-restores protegidos: mantidos com a marca R, fora dos níveis
        foreach ($shield as $item) $keep [$item ['name']] = 'R';

        $items = array_merge ($items, $shield);

        usort ($items, function ($a, $b) { return $b ['ts'] <=> $a ['ts'] ?: strcmp ($b ['name'], $a ['name']); });

        // preserva a ordem (mais recente primeiro) também em 'keep'
        $ordered = [];

        foreach ($items as $item)
            if (array_key_exists ($item ['name'], $keep))
                $ordered [$item ['name']] = $keep [$item ['name']];

        return [ 'keep' => $ordered, 'delete' => $delete, 'ignore' => array_column ($ignore, 'name') ];
    }
}
