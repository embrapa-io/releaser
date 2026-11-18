<?php

/**
 * Trava por build: impede que dois comandos mexam na mesma build ao mesmo tempo.
 *
 * O lock de /data/.lock/<operação> (run.php) só vale para o daemon e é por operação: um `io deploy` manual rodava junto
 * com o `deploy:daemon` do cron, os dois achavam a mesma tag nova e o segundo apagava e reclonava o diretório
 * `apps/<proj>/<app>/<tag>` por baixo do primeiro (`cat: can't open '.env.io': Stale file handle` com o /data em NFS,
 * nov/2026). Esta trava vale para o daemon e para o comando manual, e é usada pelos comandos que trocam ou param a
 * stack (deploy, rollback, restart, stop e remove) e, desde a 1.26.11-7, pelos que leem ou gravam os dados dela
 * (backup, sanitize, cleaner, cloud e restore): um backup feito durante um deploy sairia inconsistente, e a cópia em
 * nuvem poderia enviar um arquivo que o backup ainda está gravando.
 *
 * Arquivo /data/.busy/<proj>_<app>_<stage>.lock com JSON {pid, start, operation, since}. Não depende de flock (que
 * varia entre sistemas de arquivos de rede): a criação é atômica (`fopen` em modo 'x') e o dono é conferido pelo
 * /proc. `start` é o instante de início do processo (campo 22 do /proc/<pid>/stat), para um PID reaproveitado depois
 * de o contêiner reiniciar não passar por dono. Trava de processo que já não existe é assumida por quem chegar.
 *
 * Um processo segura no máximo uma trava: `acquire` solta a anterior, e o fim do processo solta a que restar.
 */
class BuildLock
{
    const DIR = '.busy';

    // Espera depois de assumir uma trava abandonada, para dois processos que a assumam ao mesmo tempo verem o mesmo dono.
    const SETTLE = 300000;

    // Quanto os daemons de backup, sanitize, cleaner e cloud esperam por um comando em andamento na build (segundos).
    const PATIENCE = 1800;

    static private $held = NULL;

    static private $registered = FALSE;

    static public function path ($data, $b)
    {
        return rtrim ($data ? $data : '/data', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::DIR . DIRECTORY_SEPARATOR . $b->project .'_'. $b->app .'_'. $b->stage .'.lock';
    }

    /**
     * Instante de início do processo, em ticks desde o boot (campo 22 do /proc/<pid>/stat), ou NULL se ele não existe.
     * O nome do comando (campo 2) pode ter espaços e parênteses: os campos são contados depois do último ')'.
     */
    static public function startOf ($pid)
    {
        $stat = '/proc/'. intval ($pid) .'/stat';

        if (intval ($pid) <= 0 || !is_readable ($stat)) return NULL;

        try
        {
            $raw = (string) @file_get_contents ($stat);
        }
        catch (\Throwable $e)
        {
            return NULL; // o processo terminou no meio da leitura
        }

        $close = strrpos ($raw, ')');

        if ($close === FALSE) return NULL;

        $fields = preg_split ('/\s+/', trim (substr ($raw, $close + 1)));

        return isset ($fields [19]) && $fields [19] !== '' ? (string) $fields [19] : NULL;
    }

    /**
     * Conteúdo de um arquivo de trava, ou NULL se estiver vazio ou ilegível (tratado como abandonado).
     */
    static public function parse ($raw)
    {
        $holder = json_decode (trim ((string) $raw), TRUE);

        if (!is_array ($holder) || !isset ($holder ['pid']) || intval ($holder ['pid']) <= 0) return NULL;

        return [
            'pid' => intval ($holder ['pid']),
            'start' => isset ($holder ['start']) ? (string) $holder ['start'] : '',
            'operation' => isset ($holder ['operation']) ? (string) $holder ['operation'] : '?',
            'since' => isset ($holder ['since']) ? (string) $holder ['since'] : ''
        ];
    }

    /**
     * O processo que gravou a trava ainda existe? `$probe` (pid => início ou NULL) só é trocado nos testes.
     */
    static public function alive ($holder, callable $probe = NULL)
    {
        if (!is_array ($holder)) return FALSE;

        $start = $probe ? $probe ($holder ['pid']) : self::startOf ($holder ['pid']);

        return $start !== NULL && (string) $start === $holder ['start'];
    }

    /**
     * Pega a trava da build para este processo.
     *
     * @return TRUE, ou os dados de quem a segura ([pid, start, operation, since]) quando outro comando está rodando.
     */
    static public function acquire ($data, $b, $operation, callable $probe = NULL)
    {
        $file = self::path ($data, $b);

        if (self::$held === $file) return TRUE;

        self::release ();

        $dir = dirname ($file);

        if (!file_exists ($dir))
            try { @mkdir ($dir, 0700, TRUE); } catch (\Throwable $e) {}

        $pid = getmypid ();

        $start = $probe ? $probe ($pid) : self::startOf ($pid);

        $mine = json_encode ([ 'pid' => $pid, 'start' => (string) $start, 'operation' => (string) $operation, 'since' => date (DATE_ATOM) ]);

        if (self::create ($file, $mine)) return self::hold ($file);

        $holder = self::read ($file);

        if (self::alive ($holder, $probe) && $holder ['pid'] !== $pid) return $holder;

        // Trava abandonada (o processo morreu no meio, ou o contêiner reiniciou): troca atômica pelo conteúdo deste
        // processo e, depois de uma pausa, confere quem ficou. Se dois chegarem juntos, só o último a gravar continua.
        $temp = $file .'.'. $pid;

        try
        {
            if (@file_put_contents ($temp, $mine) === FALSE || !@rename ($temp, $file)) throw new Exception ('unable to replace the lock');
        }
        catch (\Throwable $e)
        {
            try { @unlink ($temp); } catch (\Throwable $e2) {}

            // Sem conseguir gravar a trava, o comando segue como antes dela existir: a falta de proteção contra um
            // comando simultâneo é menos grave do que nenhum deploy sair.
            echo "WARNING > Unable to write the lock of this build at '". $file ."' (check the permissions and the free space of the data directory). Going on without it. \n";

            return TRUE;
        }

        usleep (self::SETTLE);

        $now = self::read ($file);

        if (is_array ($now) && $now ['pid'] === $pid) return self::hold ($file);

        return is_array ($now) ? $now : [ 'pid' => 0, 'start' => '', 'operation' => '?', 'since' => '' ];
    }

    /**
     * Como `acquire`, mas espera o comando em andamento terminar, até `$timeout` segundos. É o que os daemons de
     * backup, sanitize, cleaner e cloud usam: pular a build deixaria a noite sem backup por causa de um deploy de
     * poucos minutos.
     *
     * @return TRUE, ou os dados de quem ainda segura a trava no fim da espera.
     */
    static public function wait ($data, $b, $operation, $timeout = self::PATIENCE, $interval = 5, callable $probe = NULL)
    {
        $deadline = microtime (TRUE) + $timeout;

        $told = FALSE;

        while (TRUE)
        {
            $busy = self::acquire ($data, $b, $operation, $probe);

            if ($busy === TRUE) return TRUE;

            if (microtime (TRUE) >= $deadline) return $busy;

            if (!$told)
            {
                $told = TRUE;

                echo "INFO > ". self::message ($busy) ." Waiting up to ". intval (ceil ($timeout / 60)) ." minute(s) for it to end... \n";
            }

            usleep ((int) ($interval * 1000000));
        }
    }

    /**
     * Solta a trava deste processo (se houver). Nunca apaga a de outro.
     */
    static public function release ()
    {
        if (self::$held === NULL) return;

        $file = self::$held;

        self::$held = NULL;

        $holder = self::read ($file);

        if (!is_array ($holder) || $holder ['pid'] !== getmypid ()) return;

        try { @unlink ($file); } catch (\Throwable $e) {}
    }

    /**
     * Texto para o WARNING de quem encontrou a build ocupada.
     */
    static public function message ($holder)
    {
        $since = is_array ($holder) && $holder ['since'] !== '' ? strtotime ($holder ['since']) : FALSE;

        return "Another command is running on this build: '". (is_array ($holder) ? $holder ['operation'] : '?') ."'".
            ($since ? ' since '. date ('Y-m-d H:i:s', $since) : '') .
            (is_array ($holder) && $holder ['pid'] ? ' (pid '. $holder ['pid'] .')' : '') .'.';
    }

    static private function hold ($file)
    {
        self::$held = $file;

        if (!self::$registered)
        {
            self::$registered = TRUE;

            register_shutdown_function ([ __CLASS__, 'release' ]);
        }

        return TRUE;
    }

    // Criação atômica: falha se o arquivo já existe (o helper/error.php transforma o warning em exceção, mesmo com @).
    static private function create ($file, $content)
    {
        try
        {
            $handle = @fopen ($file, 'x');
        }
        catch (\Throwable $e)
        {
            return FALSE;
        }

        if (!$handle) return FALSE;

        fwrite ($handle, $content);
        fclose ($handle);

        return TRUE;
    }

    static private function read ($file)
    {
        try
        {
            return file_exists ($file) ? self::parse (@file_get_contents ($file)) : NULL;
        }
        catch (\Throwable $e)
        {
            return NULL;
        }
    }
}
