<?php

require_once __DIR__ . DIRECTORY_SEPARATOR .'S3Probe.php';

/**
 * Cópia dos backups em nuvem: um bucket S3 compatível (AWS S3, Cloudflare R2,
 * Backblaze B2, MinIO...). A configuração do servidor fica no /data/.env e vale
 * para todas as builds:
 *
 *   S3_ENDPOINT    URL do serviço (ex.: https://<conta>.r2.cloudflarestorage.com)
 *   S3_BUCKET      nome do bucket (precisa existir: o Releaser não o cria)
 *   S3_ACCESS_KEY  chave de acesso
 *   S3_SECRET_KEY  segredo
 *   S3_REGION      opcional; padrão 'auto' (R2). AWS exige a região real e o
 *                  MinIO, em geral, 'us-east-1'
 *   S3_RETENTION   opcional; 'D/W/M/Y' da rotação no bucket (padrão: RETENTION)
 *
 * Desde a 1.26.11-4, um projeto pode ter a configuração própria, em
 * /data/s3/<projeto>.json (modo 600, gravado pelo `io cloud --setup <projeto>`),
 * com os campos de FIELDS: 'endpoint', 'bucket', 'access_key', 'secret_key',
 * 'region' e 'retention'. Ela vale para todas as builds do projeto no lugar da
 * do servidor (a orientação é uma chave por projeto, restrita à pasta dele, e um
 * servidor pode hospedar builds de vários). O arquivo substitui o .env por
 * inteiro, sem mistura de campos; só a retenção, quando ausente, herda a do
 * servidor. Arquivo com JSON inválido conta como não configurado: nunca cai no
 * .env. select () escolhe o projeto da build em andamento; daí em diante,
 * value (), env (), bucket () etc. leem a configuração dele.
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

    // Campos do arquivo do projeto (/data/s3/<projeto>.json) e a variável equivalente do /data/.env.
    const FIELDS = [
        'S3_ENDPOINT' => 'endpoint',
        'S3_BUCKET' => 'bucket',
        'S3_ACCESS_KEY' => 'access_key',
        'S3_SECRET_KEY' => 'secret_key',
        'S3_REGION' => 'region',
        'S3_RETENTION' => 'retention'
    ];

    // Pasta, em /data, dos arquivos de configuração por projeto.
    const FOLDER = 's3';

    // Nome unix de projeto, como nos controllers.
    const PROJECT = '/^[a-z0-9][a-z0-9-]+[a-z0-9]$/';

    // Projeto da build em andamento (select ()).
    static private $project = NULL;

    // Arquivos de projeto já lidos nesta execução: projeto => array (own ()) ou NULL (sem arquivo).
    static private $loaded = [];

    /**
     * Escolhe o projeto da build em andamento: as leituras seguintes usam a
     * configuração própria dele, se houver arquivo em /data/s3/; senão, a do
     * servidor. NULL (ou nome inválido) = só a do servidor.
     */
    static public function select ($project = NULL)
    {
        self::$project = is_string ($project) && preg_match (self::PROJECT, $project) ? $project : NULL;
    }

    /** Projeto cuja configuração própria está em uso, ou NULL (a do servidor). */
    static public function scope ()
    {
        return self::$project !== NULL && self::own (self::$project) !== NULL ? self::$project : NULL;
    }

    /** Caminho do arquivo de configuração do projeto. */
    static public function file ($project)
    {
        return self::base () . DIRECTORY_SEPARATOR . self::FOLDER . DIRECTORY_SEPARATOR . $project .'.json';
    }

    /** Projetos com arquivo de configuração em /data/s3/, em ordem alfabética. */
    static public function projects ()
    {
        $dir = self::base () . DIRECTORY_SEPARATOR . self::FOLDER;

        if (!is_dir ($dir)) return [];

        $projects = [];

        foreach (glob ($dir . DIRECTORY_SEPARATOR .'*.json') ?: [] as $file)
            if (preg_match (self::PROJECT, basename ($file, '.json'))) $projects [] = basename ($file, '.json');

        sort ($projects);

        return $projects;
    }

    /**
     * Configuração própria do projeto: ['S3_ENDPOINT' => ..., ...] (valores aparados;
     * campo ausente = ''), ou NULL sem arquivo. Arquivo ilegível ou com JSON inválido
     * devolve tudo vazio, e a cópia acusa "not configured": cair na configuração do
     * servidor mandaria os backups do projeto para o bucket e com a chave errados.
     */
    static public function own ($project)
    {
        if (!is_string ($project) || !preg_match (self::PROJECT, $project)) return NULL;

        if (array_key_exists ($project, self::$loaded)) return self::$loaded [$project];

        $file = self::file ($project);

        if (!file_exists ($file)) return self::$loaded [$project] = NULL;

        // o helper/error.php transforma warning em exceção mesmo com '@': ler só o que dá para ler
        $json = is_readable ($file) ? json_decode ((string) file_get_contents ($file), TRUE) : NULL;

        $own = [];

        foreach (self::FIELDS as $name => $field)
            $own [$name] = is_array ($json) && isset ($json [$field]) && is_scalar ($json [$field]) ? trim ((string) $json [$field]) : '';

        return self::$loaded [$project] = $own;
    }

    /**
     * Grava a configuração própria do projeto ($values: 'S3_ENDPOINT' => ... etc.),
     * em /data/s3/<projeto>.json, modo 600, por arquivo temporário e rename.
     */
    static public function save ($project, array $values)
    {
        if (!is_string ($project) || !preg_match (self::PROJECT, $project))
            throw new Exception ("Invalid project name '". (is_string ($project) ? $project : '?') ."'");

        $json = [];

        foreach (self::FIELDS as $name => $field)
        {
            $value = trim ((string) ($values [$name] ?? ''));

            if ($value === '' && in_array ($name, self::REQUIRED))
                throw new Exception ("Missing '". $field ."'");

            if ($value !== '') $json [$field] = $value;
        }

        $file = self::file ($project);

        if (!is_dir (dirname ($file))) mkdir (dirname ($file), 0700, TRUE);

        // nasce com modo 600 (o run.php roda com umask 0022) e só então troca o arquivo em uso
        $mask = umask (0077);

        try
        {
            file_put_contents ($file .'.tmp', json_encode ($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ."\n", LOCK_EX);
        }
        finally
        {
            umask ($mask);
        }

        chmod ($file .'.tmp', 0600);

        rename ($file .'.tmp', $file);

        unset (self::$loaded [$project]);
    }

    /** Apaga a configuração própria do projeto, que volta a usar a do servidor. TRUE se havia arquivo. */
    static public function remove ($project)
    {
        if (!is_string ($project) || !preg_match (self::PROJECT, $project))
            throw new Exception ("Invalid project name '". (is_string ($project) ? $project : '?') ."'");

        $file = self::file ($project);

        $existed = file_exists ($file);

        if ($existed) unlink ($file);

        unset (self::$loaded [$project]);

        return $existed;
    }

    /** Esquece os arquivos de projeto já lidos (testes e leituras depois de uma gravação por fora). */
    static public function forget ()
    {
        self::$loaded = [];
    }

    // trim: o /data/.env editado no Windows traz '\r' no fim de cada valor
    static public function value ($name)
    {
        if (isset (self::FIELDS [$name]) && self::$project !== NULL)
        {
            $own = self::own (self::$project);

            // só a retenção do bucket herda a do servidor quando o projeto não define a sua
            if ($own !== NULL && ($name !== 'S3_RETENTION' || $own [$name] !== '')) return $own [$name];
        }

        return trim ((string) getenv ($name));
    }

    /** Nome do ajuste nas mensagens: o campo do arquivo do projeto ('bucket') ou a variável do .env (S3_BUCKET). */
    static public function label ($name)
    {
        return self::scope () !== NULL && isset (self::FIELDS [$name]) ? "'". self::FIELDS [$name] ."'" : $name;
    }

    /** Onde está a configuração em uso, para as mensagens. */
    static public function source ()
    {
        $project = self::scope ();

        return $project !== NULL ? "the file '". self::file ($project) ."' (settings of project '". $project ."')" : "the '.env' file";
    }

    /**
     * Retenção no bucket ('D/W/M/Y') e de onde ela veio, para as mensagens: a do
     * projeto, a S3_RETENTION do servidor ou, na falta das duas, a RETENTION.
     */
    static public function retention ()
    {
        $project = self::scope ();

        if ($project !== NULL && self::own ($project) ['S3_RETENTION'] !== '')
            return [ 'spec' => self::own ($project) ['S3_RETENTION'], 'where' => "'retention' in the file '". self::file ($project) ."'" ];

        foreach ([ 'S3_RETENTION', 'RETENTION' ] as $name)
            if (trim ((string) getenv ($name)) !== '') return [ 'spec' => trim ((string) getenv ($name)), 'where' => $name ." in the '.env' file" ];

        return [ 'spec' => '', 'where' => "RETENTION in the '.env' file" ];
    }

    /** Ajustes obrigatórios que faltam na configuração em uso (nomes como em label ()). */
    static public function missing ()
    {
        return array_values (array_map ([ self::class, 'label' ], array_filter (self::REQUIRED, function ($name) { return self::value ($name) === ''; })));
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
            throw new Exception ("Invalid bucket name '". $bucket ."' in ". self::label ('S3_BUCKET') ." (only the name, without slashes or 's3://')");

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

        // Um diretório ainda sem arquivos (primeira cópia da build) devolve [] com
        // código 0: no S3 não há diretórios, só prefixos. O código 3 ("directory not
        // found") vem do 404 do provedor, ou seja, do BUCKET inexistente (S3_BUCKET
        // errado): é erro, nunca "bucket vazio" — senão `--list` diria "nenhum
        // arquivo" e a cópia acusaria o erro só no envio (ou em nada, sem envio).
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
     * Arquivos sob o diretório de uma app no bucket, em todos os níveis
     * (lsjson -R): [['path' => 'alpha/x.tar.gz', 'name' => 'x.tar.gz', 'mtime' => int, 'size' => int], ...].
     * Usado pelo `io restore`, que lê <projeto>/<app>/<alpha|beta|release|upload>/.
     */
    static public function tree ($dir)
    {
        $cmd = self::RCLONE .' lsjson -R --files-only --no-mimetype '. self::FLAGS .' '. escapeshellarg (self::path ($dir));

        echo 'COMMAND > '. $cmd ."\n";

        $result = BackupVolume::command ($cmd, self::env ());

        $list = json_decode (implode ("\n", $result ['out']), TRUE);

        if ($result ['code'] !== 0 || !is_array ($list))
            throw new Exception ("Unable to list the bucket (". self::reason ($result) .")");

        $files = [];

        foreach ($list as $item)
        {
            if (!is_array ($item) || !empty ($item ['IsDir']) || !isset ($item ['Path'])) continue;

            $files [] = [ 'path' => (string) $item ['Path'], 'name' => (string) ($item ['Name'] ?? basename ((string) $item ['Path'])), 'mtime' => self::time ((string) ($item ['ModTime'] ?? '')), 'size' => intval ($item ['Size'] ?? 0) ];
        }

        return $files;
    }

    /**
     * Baixa um arquivo do bucket para a raiz do volume, com outro nome se
     * preciso ($local), num contêiner auxiliar com o volume em modo escrita.
     * `copyto` de um objeto só; o tamanho é conferido pelo chamador.
     */
    static public function download ($volume, $dir, $name, $local)
    {
        $env = self::env ();

        $cmd = BackupVolume::docker ($volume, TRUE, TRUE, array_keys ($env))
            .' rclone copyto '. escapeshellarg (self::path ($dir, $name)) .' '. escapeshellarg (BackupVolume::MOUNT .'/'. $local)
            .' '. self::FLAGS .' --stats 0';

        echo 'COMMAND > '. $cmd ."\n";

        $result = BackupVolume::command ($cmd, $env);

        if ($result ['code'] !== 0)
            throw new Exception ("Unable to download '". $name ."' from the bucket (". self::reason ($result) .")");
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

    /**
     * Confere, por hash (MD5), os arquivos $names do volume contra os do bucket
     * (`rclone check --one-way`, no contêiner auxiliar com o volume em modo
     * leitura). Só os nomes pedidos (filtro exato): o chamador passa os que
     * existem nos dois lados. Nada é copiado nem apagado.
     *
     * @return array ['status' => [name => '=' (igual) | '*' (difere) | '-' (não está no bucket) | '!' (erro)], 'notes' => [linhas do rclone]]
     */
    static public function verify ($volume, $dir, array $names)
    {
        if (!sizeof ($names)) return [ 'status' => [], 'notes' => [] ];

        $env = self::env ();

        $filters = [];

        foreach ($names as $name)
            $filters [] = '--include '. escapeshellarg ('/'. self::glob ($name));

        // --combined -: uma linha por arquivo no stdout ('= nome', '* nome', '- nome', '! nome')
        $cmd = BackupVolume::docker ($volume, FALSE, TRUE, array_keys ($env))
            .' rclone check '. BackupVolume::MOUNT .' '. escapeshellarg (self::path ($dir))
            .' --one-way --max-depth 1 '. implode (' ', $filters) .' --combined - --stats 0';

        echo 'COMMAND > '. $cmd ."\n";

        $result = BackupVolume::command ($cmd, $env);

        // 0 = tudo igual; 1 = há diferenças (o veredito está no stdout); outro = não conseguiu conferir
        if (!in_array ($result ['code'], [ 0, 1 ]))
            throw new Exception ("Unable to verify the files in the bucket (". self::reason ($result) .")");

        $status = [];

        foreach ($result ['out'] as $line)
            if (preg_match ('/^([=*!+-]) (.+)$/', $line, $m)) $status [$m [2]] = $m [1];

        // avisos do rclone que importam: hashes que não puderam ser conferidos, erros
        $notes = [];

        foreach ($result ['err'] as $line)
            if (preg_match ('/hashes could not be checked|ERROR/', $line) && !preg_match ('/: (sizes|md5|sha1|hash) differ$|Failed to check/i', $line))
                $notes [] = self::mask (mb_strimwidth (preg_replace ('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\s+/', '', $line), 0, 300, '...'));

        return [ 'status' => $status, 'notes' => $notes ];
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
     * Teste do acesso aos buckets para o `io check`, uma vez por configuração em
     * uso: a do servidor (quando alguma S3_* está no .env) e a de cada projeto com
     * arquivo em /data/s3/. NULL sem nenhuma (cópia em nuvem não usada). Nunca lança.
     *
     * Devolve o pior resultado ('status', 'message', 'alert') e, em 'scopes', o de
     * cada configuração: 'project' (NULL = servidor), 'file', 'bucket', 'endpoint',
     * 'status' e 'message'. Com uma só, a mensagem é a dela.
     */
    static public function check ($data = '/data')
    {
        $selected = self::$project;

        $scopes = [];

        try
        {
            self::select (NULL);

            if (self::touched ())
                $scopes [] = self::test ($data, NULL) + [ 'project' => NULL, 'file' => NULL, 'bucket' => self::value ('S3_BUCKET'), 'endpoint' => self::value ('S3_ENDPOINT') ];

            foreach (self::projects () as $project)
            {
                self::select ($project);

                $scopes [] = self::test ($data, $project) + [ 'project' => $project, 'file' => self::file ($project), 'bucket' => self::value ('S3_BUCKET'), 'endpoint' => self::value ('S3_ENDPOINT') ];
            }
        }
        catch (\Throwable $e)
        {
            $scopes [] = self::result ('ERROR', self::mask ($e->getMessage ()), [ 'alert' => TRUE, 'project' => self::$project, 'file' => NULL, 'bucket' => '', 'endpoint' => '' ]);
        }
        finally
        {
            self::$project = $selected;
        }

        if (!sizeof ($scopes)) return NULL;

        if (sizeof ($scopes) === 1) return $scopes [0] + [ 'scopes' => $scopes ];

        $name = function ($scope) { return $scope ['project'] === NULL ? 'server' : "project '". $scope ['project'] ."'"; };

        $bad = array_values (array_filter ($scopes, function ($scope) { return $scope ['status'] !== 'OK'; }));

        if (!sizeof ($bad))
            return self::result ('OK', 'ok ('. sizeof ($scopes) .' settings: '. implode (', ', array_map ($name, $scopes)) .')', [ 'scopes' => $scopes ]);

        $errors = array_values (array_filter ($bad, function ($scope) { return $scope ['status'] === 'ERROR'; }));

        $extra = [ 'scopes' => $scopes ];

        if (sizeof (array_filter ($bad, function ($scope) { return !empty ($scope ['alert']); }))) $extra ['alert'] = TRUE;

        return self::result (sizeof ($errors) ? 'ERROR' : 'WARNING', implode ('; ', array_map (function ($scope) use ($name) { return $name ($scope) .': '. $scope ['message']; }, $bad)), $extra);
    }

    /**
     * Teste de UMA configuração (a selecionada): listar (`rclone lsd`, 5 s) e
     * gravar (S3Probe) no diretório das builds de $data que a usam.
     */
    static private function test ($data, $project)
    {
        $missing = self::missing ();

        if (sizeof ($missing))
            return self::result ('ERROR', 'not configured, missing '. implode (', ', $missing) . ($project !== NULL ? " (run 'io cloud --setup ". $project ."')" : ''), [ 'alert' => TRUE ]);

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
        {
            // listar não prova que a chave grava: abre e aborta um upload multipart (S3Probe), sem deixar nada no bucket
            // (a do servidor é testada na pasta de cada projeto com a cópia ligada: a chave pode ser de um só deles)
            $probe = S3Probe::runAll (self::value ('S3_ENDPOINT'), $bucket, self::value ('S3_ACCESS_KEY'), self::value ('S3_SECRET_KEY'), self::value ('S3_REGION'), S3Probe::prefixes ($data, $project));

            if (!$probe ['ok'])
                return self::result ('ERROR', 'listing ok, but '. $probe ['message'] . ($project === NULL && sizeof (S3Probe::builds ($data)) ? " (if the key is of another project, run 'io cloud --setup <project>' for this one)" : ''), [ 'alert' => TRUE ]);

            $what = $probe ['tested'] === 0 ? 'listing ok; writing not tested: no build uses these settings' : ($probe ['tested'] === 1 ? 'listing and writing' : 'listing, and writing in '. $probe ['tested'] .' projects');

            return $ms >= self::SLOW
                ? self::result ('WARNING', 'slow: '. $ms .' ms (bucket '. $bucket .', '. $what . ($probe ['tested'] ? ' ok' : '') .')', [ 'ms' => $ms ])
                : self::result ('OK', 'ok (bucket '. $bucket .', '. $what .', '. $ms .' ms)', [ 'ms' => $ms ]);
        }

        // timeout: 124 (GNU), 143 (BusyBox, pelo shell) ou 15 (SIGTERM, quando o proc_close vê o sinal)
        if (in_array ($result ['code'], [ 124, 143, 15 ]))
            return self::result ('ERROR', 'unreachable: no answer in '. self::CHECK_TIMEOUT .' s (timeout; blocked?)', [ 'alert' => TRUE ]);

        // 3 = "directory not found" = 404 do provedor: o bucket não existe (chave
        // recusada dá código 1, com AccessDenied/SignatureDoesNotMatch no stderr)
        if ($result ['code'] === 3)
            return self::result ('ERROR', "bucket '". $bucket ."' not found (create it in the provider, or fix ". self::label ('S3_BUCKET') .")", [ 'alert' => TRUE ]);

        return self::result ('ERROR', self::reason ($result), [ 'alert' => TRUE ]);
    }

    /** Motivo legível de uma falha do rclone, sem credenciais. */
    static public function reason ($result)
    {
        $text = implode (' ', array_merge ($result ['err'], $result ['code'] !== 0 ? $result ['out'] : []));

        $bucket = self::value ('S3_BUCKET');

        // 'directory not found' (código 3) é o 404 do provedor: no S3 só o bucket
        // inexistente responde assim (um prefixo vazio lista [] com código 0)
        if (preg_match ('/NoSuchBucket|bucket.*(not found|does not exist)|directory not found/i', $text))
            return "bucket '". $bucket ."' not found (create it in the provider, or fix ". self::label ('S3_BUCKET') .")";

        if (preg_match ('/AuthorizationHeaderMalformed|IllegalLocationConstraintException|PermanentRedirect|region .{0,60}(wrong|invalid|does not match)/i', $text))
            return 'wrong '. self::label ('S3_REGION') .' (AWS and Backblaze B2 expect the real region of the bucket, e.g., sa-east-1 or us-east-005)';

        // Com a configuração do servidor, a causa comum num servidor de vários projetos é a chave ser de outro
        // projeto. Nesse caso o envio nem chega ao AccessDenied: o rclone confere antes se o destino é um
        // arquivo (HEAD), leva 403 e conclui "is a file not a directory" (visto com o Silo e a política da
        // documentação, que deixa listar o bucket inteiro e ler só a pasta do projeto).
        if (preg_match ('/InvalidAccessKeyId|SignatureDoesNotMatch|AccessDenied|InvalidToken|Unauthorized|status code: 40[13]|\b40[13]\b|is a file not a directory/i', $text))
            return 'access denied (check '. self::label ('S3_ACCESS_KEY') .', '. self::label ('S3_SECRET_KEY') .', '. self::label ('S3_REGION') .' and the permissions of the key on the bucket'
                . (self::$project !== NULL && self::scope () === NULL ? "; if the key in the '.env' file is of another project, run 'io cloud --setup ". self::$project ."'" : '') .')';

        if (preg_match ('/no such host|server misbehaving|Temporary failure in name resolution/i', $text))
            return 'cannot resolve the host of '. self::label ('S3_ENDPOINT') .' (DNS)';

        if (preg_match ('/connection refused/i', $text))
            return 'connection refused by '. self::label ('S3_ENDPOINT');

        if (preg_match ('/i\/o timeout|deadline exceeded|timed out/i', $text))
            return 'no answer from '. self::label ('S3_ENDPOINT') .' (timeout; blocked?)';

        // a última linha de erro do rclone, sem o carimbo de data/hora
        $lines = array_values (array_filter (array_merge ($result ['err'], $result ['out']), function ($l) { return stripos ($l, 'ERROR') !== FALSE || stripos ($l, 'Failed') !== FALSE; }));

        $last = sizeof ($lines) ? $lines [sizeof ($lines) - 1] : (sizeof ($result ['err']) ? $result ['err'][sizeof ($result ['err']) - 1] : 'rclone exit code '. $result ['code']);

        $last = preg_replace ('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\s+/', '', trim ($last));

        return self::mask (mb_strimwidth ($last, 0, 300, '...'));
    }

    /**
     * Esconde as chaves e os segredos, caso o rclone ou o provedor os repitam numa
     * mensagem: os do servidor e os de todos os projetos com configuração própria.
     * Chave de acesso igual ao nome de um projeto (a orientação para o S3 próprio:
     * o usuário do projeto) não é escondida: está no caminho e no nome de todo
     * arquivo, e escondê-la só estragaria as mensagens.
     */
    static public function mask ($text)
    {
        $names = self::projects ();

        if (self::$project !== NULL) $names [] = self::$project;

        $hide = [];

        foreach (array_merge ([ NULL ], self::projects ()) as $project)
        {
            $own = $project !== NULL ? self::own ($project) : NULL;

            $secret = $own !== NULL ? $own ['S3_SECRET_KEY'] : trim ((string) getenv ('S3_SECRET_KEY'));

            $access = $own !== NULL ? $own ['S3_ACCESS_KEY'] : trim ((string) getenv ('S3_ACCESS_KEY'));

            if (strlen ($secret) >= 4) $hide [] = $secret;

            if (strlen ($access) >= 4 && !in_array ($access, $names, TRUE)) $hide [] = $access;
        }

        // os mais longos primeiro: um valor contido em outro não deixa sobra à mostra
        usort ($hide, function ($a, $b) { return strlen ($b) <=> strlen ($a); });

        return str_replace (array_values (array_unique ($hide)), '***', $text);
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

    /** Diretório de dados do Releaser (/data). */
    static private function base ()
    {
        global $_data;

        return $_data ? $_data : DIRECTORY_SEPARATOR .'data';
    }

    static private function dir ()
    {
        $dir = self::base () . DIRECTORY_SEPARATOR .'.cloud';

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

// Linha de comando (assistente do bin/io, `io cloud --setup [projeto]`): lista os projetos com build neste
// servidor e lê, grava ou apaga a configuração própria de um projeto. Os valores a gravar vêm das variáveis
// S3_* do ambiente, nunca da linha de comando. Uso: php class/Cloud.php projects|get|save|remove [projeto] [/data]
if (PHP_SAPI === 'cli' && isset ($argv [0]) && realpath ($argv [0]) === __FILE__)
{
    $_data = isset ($argv [3]) && $argv [3] !== '' ? $argv [3] : DIRECTORY_SEPARATOR .'data';

    $command = isset ($argv [1]) ? $argv [1] : '';

    $project = isset ($argv [2]) ? $argv [2] : '';

    try
    {
        switch ($command)
        {
            // projetos com build no servidor, um por linha: "<projeto>\t<own|server>" (configuração própria ou a do servidor)
            case 'projects':
                $projects = array_values (array_unique (array_map (function ($b) { return $b->project; }, S3Probe::builds ($_data))));

                sort ($projects);

                foreach ($projects as $name)
                    if (preg_match (Cloud::PROJECT, $name)) echo $name ."\t". (Cloud::own ($name) !== NULL ? 'own' : 'server') ."\n";

                break;

            // configuração própria do projeto: uma linha S3_NOME=valor por campo preenchido (nada, sem arquivo)
            case 'get':
                foreach ((array) Cloud::own ($project) as $name => $value)
                    if ($value !== '') echo $name .'='. $value ."\n";

                break;

            case 'save':
                $values = [];

                foreach (array_keys (Cloud::FIELDS) as $name)
                    $values [$name] = (string) getenv ($name);

                Cloud::save ($project, $values);

                echo Cloud::file ($project) ."\n";

                break;

            case 'remove':
                Cloud::remove ($project);

                break;

            default:
                throw new Exception ('Usage: php class/Cloud.php projects|get|save|remove [project] [/data]');
        }
    }
    catch (\Throwable $e)
    {
        fwrite (STDERR, $e->getMessage () ."\n");

        exit (1);
    }

    exit (0);
}
