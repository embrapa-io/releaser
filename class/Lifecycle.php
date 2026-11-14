<?php

/**
 * Ciclo de vida de uma build neste servidor, do ponto de vista do relato ao core (desde a 1.26.11-5):
 *
 * - **Parada** (`io stop`): grava /data/.stopped/<p>_<a>_<s>.json ({version, at}) e avisa o core
 *   (`state: "stopped"`), que apaga a saúde e as CVEs da build e mostra a versão como parada. Enquanto a marca
 *   existe, os daemons de saúde e de CVEs pulam a build: sem isso, os contêineres parados (sem `--down`) seriam
 *   relatados como `exited` e tirariam a marca no core. `io restart`, `io deploy` e `io rollback` apagam a marca.
 * - **Removida** (`io remove`): para e remove os contêineres (como `io stop --down`), pede ao core que esqueça a
 *   build (DELETE /releaser/build) e desativa o arquivo dela (`.json` → `.bkp`). **Nunca** apaga volume, clone ou
 *   backup: dado se apaga à mão, por uma pessoa.
 *
 * Funções puras sobre o diretório de dados recebido, testadas em tests/lifecycle.php.
 */
class Lifecycle
{
    const DIR = '.stopped';

    static public function namespace ($b)
    {
        return $b->project .'_'. $b->app .'_'. $b->stage;
    }

    static public function marker ($data, $b)
    {
        return $data . DIRECTORY_SEPARATOR . self::DIR . DIRECTORY_SEPARATOR . self::namespace ($b) .'.json';
    }

    static public function isStopped ($data, $b)
    {
        return file_exists (self::marker ($data, $b));
    }

    /** {version, at} da parada, ou NULL. */
    static public function stopped ($data, $b)
    {
        $file = self::marker ($data, $b);

        if (!file_exists ($file)) return NULL;

        $info = json_decode ((string) @file_get_contents ($file), TRUE);

        return is_array ($info) ? $info : [ 'version' => '', 'at' => '' ];
    }

    static public function markStopped ($data, $b, $version)
    {
        $dir = $data . DIRECTORY_SEPARATOR . self::DIR;

        if (!file_exists ($dir) && !@mkdir ($dir, 0755, TRUE) && !is_dir ($dir))
            throw new Exception ("Unable to create directory '". $dir ."'");

        $file = self::marker ($data, $b);

        // A primeira parada vale: um segundo `io stop` não muda a data.
        $previous = self::stopped ($data, $b);

        $info = [ 'version' => (string) $version, 'at' => is_array ($previous) && !empty ($previous ['at']) ? $previous ['at'] : date (DATE_ATOM) ];

        if (@file_put_contents ($file, json_encode ($info, JSON_UNESCAPED_SLASHES) ."\n", LOCK_EX) === FALSE)
            throw new Exception ("Unable to write '". $file ."'");

        return $info;
    }

    /** Apaga a marca de parada. Devolve TRUE se a build estava parada. */
    static public function clearStopped ($data, $b)
    {
        $file = self::marker ($data, $b);

        if (!file_exists ($file)) return FALSE;

        @unlink ($file);

        return TRUE;
    }

    /**
     * Nome do arquivo desativado de uma build: `<arquivo>.json` → `<arquivo>.bkp`; se já existe um `.bkp` (remoção
     * anterior), `<arquivo>.bkp-AAAAMMDD-HHMMSS`, para nunca sobrescrever. Lança se o arquivo não é um `.json` de
     * /data/builds (o legado builds.json, com várias builds, não se desativa por build: `io split` antes).
     */
    static public function disabledName ($file, $now = NULL)
    {
        if (!preg_match ('/^(.+)\.json$/', (string) $file, $m) || basename (dirname ($file)) !== 'builds')
            throw new Exception ("The build is configured in '". $file ."', not in its own file in '/data/builds/'. Run 'io split' first, to move each build to its own file");

        $target = $m [1] .'.bkp';

        if (!file_exists ($target)) return $target;

        return $m [1] .'.bkp-'. date ('Ymd-His', $now === NULL ? time () : $now);
    }

    /** Desativa o arquivo da build (renomeia, sem apagar). Devolve o novo caminho. */
    static public function disable ($file, $now = NULL)
    {
        $target = self::disabledName ($file, $now);

        if (!@rename ($file, $target))
            throw new Exception ("Unable to rename '". $file ."' to '". $target ."'");

        return $target;
    }

    /**
     * O que o `io remove` deixa no servidor, para a mensagem final: volumes (nomes), clone das versões e backups.
     * $volumes vem de Orchestrator::volumes (só leitura).
     */
    static public function kept ($data, $b, array $volumes, $backup)
    {
        $lines = [];

        foreach ($volumes as $v)
            $lines [] = "  - volume '". $v ."' (to delete it: docker volume rm ". $v .")";

        $lines [] = "  - clones of the versions in '". $data . DIRECTORY_SEPARATOR .'apps'. DIRECTORY_SEPARATOR . $b->project . DIRECTORY_SEPARATOR . $b->app ."' (relative bind mounts of the app keep their data inside them)";

        if ($backup !== '') $lines [] = "  - backups of the build in '". $backup ."'";

        return $lines;
    }
}
