<?php

/**
 * Cópia dos backups em nuvem: um bucket S3 compatível (AWS S3, Cloudflare R2,
 * Backblaze B2, MinIO...), configurado uma única vez no /data/.env:
 *
 *   S3_ENDPOINT    URL do serviço (ex.: https://<conta>.r2.cloudflarestorage.com)
 *   S3_BUCKET      nome do bucket (precisa existir: o Releaser não o cria)
 *   S3_ACCESS_KEY  chave de acesso
 *   S3_SECRET_KEY  segredo
 *   S3_REGION      opcional; padrão 'auto' (R2). AWS exige a região real e o
 *                  MinIO, em geral, 'us-east-1'
 *   S3_RETENTION   opcional; 'D/W/M/Y' da rotação no bucket (padrão: RETENTION)
 *
 * Layout: <bucket>/<projeto>/<app>/<estágio>/<arquivo>, para um bucket servir a
 * vários servidores. Só arquivos com o prefixo '{projeto}_{app}_{estágio}_' são
 * considerados, no volume e no bucket.
 *
 * O rclone (da própria imagem) é configurado SÓ por variáveis de ambiente do
 * processo (env ()): sem arquivo de configuração e sem segredo na linha de
 * comando. Listar, apagar e testar o bucket rodam no próprio contêiner do
 * Releaser; enviar precisa do volume da build e roda no contêiner auxiliar
 * (BackupVolume), que recebe as mesmas variáveis por `-e NOME`.
 */
class Cloud
{
    const RCLONE = '/usr/bin/rclone';

    // Nome do remote do rclone: RCLONE_CONFIG_S3_* (ver env ()).
    const REMOTE = 'S3';

    const REQUIRED = [ 'S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY' ];

    const CHECK_TIMEOUT = 5; // segundos (io check)

    const SLOW = 2000; // milissegundos

    // Opções comuns às chamadas que só leem ou apagam no bucket (o envio usa as padrão do rclone).
    const FLAGS = '--contimeout 15s --timeout 60s --retries 3 --low-level-retries 5';

    // trim: o /data/.env editado no Windows traz '\r' no fim de cada valor
    static public function value ($name)
    {
        return trim ((string) getenv ($name));
    }

    /** Variáveis obrigatórias que faltam no /data/.env. */
    static public function missing ()
    {
        return array_values (array_filter (self::REQUIRED, function ($name) { return self::value ($name) === ''; }));
    }

    static public function configured ()
    {
        return !sizeof (self::missing ());
    }

    /** Alguma variável S3_* foi definida (configuração completa ou pela metade)? */
    static public function touched ()
    {
        foreach (array_merge (self::REQUIRED, [ 'S3_REGION', 'S3_RETENTION' ]) as $name)
            if (self::value ($name) !== '') return TRUE;

        return FALSE;
    }

    static public function bucket ()
    {
        $bucket = self::value ('S3_BUCKET');

        if (!preg_match ('/^[A-Za-z0-9][A-Za-z0-9._-]{1,254}$/', $bucket))
            throw new Exception ("Invalid bucket name '". $bucket ."' in S3_BUCKET (only the name, without slashes or 's3://')");

        return $bucket;
    }

    /**
     * Ambiente do rclone: remote 'S3' só por variáveis (RCLONE_CONFIG_S3_*), sem
     * arquivo de configuração (RCLONE_CONFIG=/dev/null mantém tudo em memória) e
     * sem checar/criar o bucket (o R2 e tokens restritos a um bucket recusam).
     */
    static public function env ()
    {
        $region = self::value ('S3_REGION');

        $env = [
            'RCLONE_CONFIG' => '/dev/null',
            'RCLONE_CONFIG_S3_TYPE' => 's3',
            'RCLONE_CONFIG_S3_PROVIDER' => 'Other',
            'RCLONE_CONFIG_S3_ACCESS_KEY_ID' => self::value ('S3_ACCESS_KEY'),
            'RCLONE_CONFIG_S3_SECRET_ACCESS_KEY' => self::value ('S3_SECRET_KEY'),
            'RCLONE_CONFIG_S3_ENDPOINT' => self::value ('S3_ENDPOINT'),
            'RCLONE_CONFIG_S3_REGION' => $region !== '' ? $region : 'auto',
            'RCLONE_S3_NO_CHECK_BUCKET' => 'true'
        ];

        // proxy do host, se houver: o contêiner auxiliar só recebe o que for passado
        foreach ([ 'HTTPS_PROXY', 'https_proxy', 'NO_PROXY', 'no_proxy' ] as $name)
            if (self::value ($name) !== '') $env [$name] = self::value ($name);

        return $env;
    }

    /** Caminho no rclone: 'S3:<bucket>/<projeto>/<app>/<estágio>[/<arquivo>]'. */
    static public function path ($dir, $name = NULL)
    {
        return self::REMOTE .':'. self::bucket () .'/'. trim ($dir, '/') . ($name !== NULL ? '/'. $name : '');
    }

    /** Para a saída: 's3://<bucket>/<projeto>/<app>/<estágio>/'. */
    static public function url ($dir)
    {
        return 's3://'. self::bucket () .'/'. trim ($dir, '/') .'/';
    }

    /**
     * Arquivos na raiz do diretório da build no bucket (sem subdiretórios):
     * [['name' => string, 'mtime' => int, 'size' => int], ...]. Diretório ainda
     * inexistente (primeira cópia) = lista vazia.
     */
    static public function files ($dir)
    {
        $cmd = self::RCLONE .' lsjson --files-only --no-mimetype '. self::FLAGS .' '. escapeshellarg (self::path ($dir));

        echo 'COMMAND > '. $cmd ."\n";

        $result = BackupVolume::command ($cmd, self::env ());

        // 3 = diretório não encontrado: nada foi copiado ainda para esta build
        if ($result ['code'] === 3) return [];

        $list = json_decode (implode ("\n", $result ['out']), TRUE);

        if ($result ['code'] !== 0 || !is_array ($list))
            throw new Exception ("Unable to list the bucket (". self::reason ($result) .")");

        $files = [];

        foreach ($list as $item)
        {
            if (!is_array ($item) || !empty ($item ['IsDir']) || !isset ($item ['Name'])) continue;

            $files [] = [ 'name' => (string) $item ['Name'], 'mtime' => self::time ((string) ($item ['ModTime'] ?? '')), 'size' => intval ($item ['Size'] ?? 0) ];
        }

        return $files;
    }

    /**
     * Envia ao bucket os arquivos $names do volume, num contêiner auxiliar com o
     * volume em modo leitura. `copy --ignore-existing` com um filtro por nome
     * exato: nunca `sync`, nunca sobrescreve nem apaga nada no destino.
     */
    static public function upload ($volume, $dir, array $names)
    {
        if (!sizeof ($names)) return;

        $env = self::env ();

        $filters = [];

        foreach ($names as $name)
            $filters [] = '--include '. escapeshellarg ('/'. self::glob ($name));

        $cmd = BackupVolume::docker ($volume, FALSE, TRUE, array_keys ($env))
            .' rclone copy '. BackupVolume::MOUNT .' '. escapeshellarg (self::path ($dir))
            .' --ignore-existing --max-depth 1 '. implode (' ', $filters) .' --stats 0 -v';

        echo 'COMMAND > '. $cmd ."\n";

        $result = BackupVolume::command ($cmd, $env);

        // o que foi copiado; numa falha, só as últimas linhas (o rclone repete cada erro a cada tentativa)
        $lines = array_merge ($result ['out'], $result ['err']);

        if ($result ['code'] !== 0)
            $lines = array_slice (array_values (array_filter ($lines, function ($l) { return preg_match ('/ERROR|NOTICE|Failed/', $l); })), -2);

        foreach ($lines as $line)
            echo "  ". self::mask (mb_strimwidth (preg_replace ('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\s+/', '', $line), 0, 300, '...')) ."\n";

        if ($result ['code'] !== 0)
            throw new Exception ("Unable to upload to the bucket (". self::reason ($result) .")");
    }

    /** Apaga do bucket, um a um (`deletefile`: só o arquivo nomeado, nunca um diretório). */
    static public function delete ($dir, array $names)
    {
        foreach ($names as $name)
        {
            $cmd = self::RCLONE .' deletefile '. self::FLAGS .' '. escapeshellarg (self::path ($dir, $name));

            echo 'COMMAND > '. $cmd ."\n";

            $result = BackupVolume::command ($cmd, self::env ());

            if ($result ['code'] !== 0)
                throw new Exception ("Unable to delete '". $name ."' from the bucket (". self::reason ($result) .")");
        }
    }

    /**
     * Teste do acesso ao bucket para o `io check` (`rclone lsd`, 5 s). NULL quando
     * nenhuma variável S3_* foi definida (cópia em nuvem não usada). Nunca lança.
     */
    static public function check ()
    {
        if (!self::touched ()) return NULL;

        $missing = self::missing ();

        if (sizeof ($missing))
            return self::result ('ERROR', 'not configured, missing '. implode (', ', $missing), [ 'alert' => TRUE ]);

        try
        {
            $bucket = self::bucket ();

            $start = microtime (TRUE);

            $result = BackupVolume::command ('timeout '. self::CHECK_TIMEOUT .' '. self::RCLONE .' lsd '. escapeshellarg (self::REMOTE .':'. $bucket) .' --contimeout '. self::CHECK_TIMEOUT .'s --timeout '. self::CHECK_TIMEOUT .'s --retries 1 --low-level-retries 1', self::env ());

            $ms = (int) round ((microtime (TRUE) - $start) * 1000);
        }
        catch (\Throwable $e)
        {
            return self::result ('ERROR', $e->getMessage (), [ 'alert' => TRUE ]);
        }

        if ($result ['code'] === 0)
            return $ms >= self::SLOW
                ? self::result ('WARNING', 'slow: '. $ms .' ms (bucket '. $bucket .')', [ 'ms' => $ms ])
                : self::result ('OK', 'ok (bucket '. $bucket .', '. $ms .' ms)', [ 'ms' => $ms ]);

        // timeout: 124 (GNU), 143 (BusyBox, pelo shell) ou 15 (SIGTERM, quando o proc_close vê o sinal)
        if (in_array ($result ['code'], [ 124, 143, 15 ]))
            return self::result ('ERROR', 'unreachable: no answer in '. self::CHECK_TIMEOUT .' s (timeout; blocked?)', [ 'alert' => TRUE ]);

        // 3 = diretório não encontrado: na raiz do remote, o próprio bucket
        if ($result ['code'] === 3 && !preg_match ('/AccessDenied|InvalidAccessKeyId|SignatureDoesNotMatch/i', implode (' ', $result ['err'])))
            return self::result ('ERROR', "bucket '". $bucket ."' not found (create it in the provider)", [ 'alert' => TRUE ]);

        return self::result ('ERROR', self::reason ($result), [ 'alert' => TRUE ]);
    }

    /** Motivo legível de uma falha do rclone, sem credenciais. */
    static public function reason ($result)
    {
        $text = implode (' ', array_merge ($result ['err'], $result ['code'] !== 0 ? $result ['out'] : []));

        $bucket = self::value ('S3_BUCKET');

        if (preg_match ('/NoSuchBucket|bucket.*(not found|does not exist)/i', $text))
            return "bucket '". $bucket ."' not found (create it in the provider)";

        if (preg_match ('/InvalidAccessKeyId|SignatureDoesNotMatch|AccessDenied|InvalidToken|Unauthorized|status code: 40[13]|\b40[13]\b/i', $text))
            return 'access denied (check S3_ACCESS_KEY, S3_SECRET_KEY, S3_REGION and the permissions of the key on the bucket)';

        if (preg_match ('/no such host|server misbehaving|Temporary failure in name resolution/i', $text))
            return 'cannot resolve the host of S3_ENDPOINT (DNS)';

        if (preg_match ('/connection refused/i', $text))
            return 'connection refused by S3_ENDPOINT';

        if (preg_match ('/i\/o timeout|deadline exceeded|timed out/i', $text))
            return 'no answer from S3_ENDPOINT (timeout; blocked?)';

        if (preg_match ('/directory not found/i', $text))
            return "directory not found in bucket '". $bucket ."'";

        // a última linha de erro do rclone, sem o carimbo de data/hora
        $lines = array_values (array_filter (array_merge ($result ['err'], $result ['out']), function ($l) { return stripos ($l, 'ERROR') !== FALSE || stripos ($l, 'Failed') !== FALSE; }));

        $last = sizeof ($lines) ? $lines [sizeof ($lines) - 1] : (sizeof ($result ['err']) ? $result ['err'][sizeof ($result ['err']) - 1] : 'rclone exit code '. $result ['code']);

        $last = preg_replace ('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\s+/', '', trim ($last));

        return self::mask (mb_strimwidth ($last, 0, 300, '...'));
    }

    /** Esconde a chave e o segredo, caso o rclone ou o provedor os repitam numa mensagem. */
    static public function mask ($text)
    {
        foreach ([ 'S3_SECRET_KEY', 'S3_ACCESS_KEY' ] as $name)
            if (strlen (self::value ($name)) >= 4) $text = str_replace (self::value ($name), '***', $text);

        return $text;
    }

    /** Resultado da última cópia da build (/data/.cloud/<p>_<a>_<s>.json), para o `io info`. */
    static public function last ($project, $app, $stage)
    {
        $file = self::dir () . DIRECTORY_SEPARATOR . $project .'_'. $app .'_'. $stage .'.json';

        if (!file_exists ($file)) return [];

        $data = json_decode ((string) file_get_contents ($file), TRUE);

        return is_array ($data) ? $data : [];
    }

    /**
     * Registra o resultado da cópia da build: data e hora, enviados, bytes,
     * apagados e erro. Numa falha, a data do último sucesso é mantida.
     */
    static public function remember ($project, $app, $stage, array $stats = NULL, $error = NULL)
    {
        try
        {
            $previous = self::last ($project, $app, $stage);

            $state = [
                'at' => date (DATE_ATOM),
                'status' => $error === NULL ? 'ok' : 'error',
                'error' => $error,
                'success_at' => $error === NULL ? date (DATE_ATOM) : ($previous ['success_at'] ?? NULL)
            ];

            if ($stats !== NULL)
                $state += array_intersect_key ($stats, array_flip ([ 'uploaded', 'bytes_uploaded', 'skipped', 'kept', 'deleted', 'bytes_deleted', 'files_after', 'bytes_after' ]));
            elseif (isset ($previous ['status']) && $previous ['status'] === 'ok')
                $state ['previous'] = array_intersect_key ($previous, array_flip ([ 'at', 'uploaded', 'bytes_uploaded', 'deleted', 'files_after', 'bytes_after' ]));
            elseif (isset ($previous ['previous']))
                $state ['previous'] = $previous ['previous'];

            file_put_contents (self::dir () . DIRECTORY_SEPARATOR . $project .'_'. $app .'_'. $stage .'.json', json_encode ($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        catch (\Throwable $e)
        {
            echo "WARNING > Unable to record the result of the cloud copy: ". $e->getMessage () ." \n";
        }
    }

    static private function dir ()
    {
        global $_data;

        $dir = ($_data ? $_data : DIRECTORY_SEPARATOR .'data') . DIRECTORY_SEPARATOR .'.cloud';

        if (!file_exists ($dir)) mkdir ($dir, 0700, TRUE);

        return $dir;
    }

    /** Escapa os curingas dos filtros do rclone (* ? [ ] { } \), para casar o nome exato. */
    static private function glob ($name)
    {
        return preg_replace ('/([\\\\*?\[\]{}])/', '\\\\$1', $name);
    }

    // ModTime do lsjson (RFC 3339 com nanossegundos) -> Unix timestamp
    static private function time ($value)
    {
        $ts = strtotime (preg_replace ('/\.\d+/', '', $value));

        return $ts === FALSE ? 0 : $ts;
    }

    static private function result ($status, $message, $extra = [])
    {
        return [ 'status' => $status, 'message' => $message ] + $extra;
    }
}
