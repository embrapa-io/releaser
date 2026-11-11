<?php

/**
 * Teste de GRAVAÇÃO no bucket S3 da cópia em nuvem, sem deixar nada nele.
 *
 * O `rclone lsd` do Cloud::check só prova que a chave lista o bucket. Gravar um
 * arquivo de teste e apagá-lo não serve: com Object Lock (o padrão do boilerplate
 * silo) a versão gravada fica retida e a chave da aplicação não a apaga. Aqui o
 * teste abre um upload multipart (CreateMultipartUpload, autorizado pela mesma
 * permissão s3:PutObject do envio real) e o aborta em seguida (AbortMultipartUpload):
 * upload em andamento não é objeto, não vira versão e não é retido.
 *
 * A chave do teste fica no diretório de uma build deste servidor
 * (<projeto>/<app>/<estágio>/.releaser-write-test-<aleatório>), para funcionar com
 * chaves restritas ao prefixo dos projetos do servidor. O nome não tem o prefixo
 * '{projeto}_{app}_{estágio}_', então nem a rotação nem o --list o consideram.
 * Uma configuração pode servir a vários projetos (a do servidor, no .env) ou a um
 * só (a própria do projeto, /data/s3/<projeto>.json): prefixes () diz onde testar.
 *
 * Assinatura AWS Signature Version 4 feita aqui (sign ()), com URL no estilo de
 * caminho (<endpoint>/<bucket>/<chave>), como o rclone com provider 'Other'.
 * Segredos só em memória; nunca na saída.
 *
 * Também roda pela linha de comando, para o assistente do `bin/io` (`s3_test`):
 *   S3_ENDPOINT=… S3_BUCKET=… S3_ACCESS_KEY=… S3_SECRET_KEY=… [S3_REGION=…] php class/S3Probe.php [/data] [projeto]
 * Imprime uma linha e sai com 0 (gravação ok) ou 1.
 */
class S3Probe
{
    const TIMEOUT = 5; // segundos, por requisição

    const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Cabeçalhos assinados (SigV4) para a requisição. $headers: nome => valor (o
     * host, o x-amz-date e o x-amz-content-sha256 são acrescentados); $time: Unix.
     * Devolve os cabeçalhos a enviar, com o Authorization. Pura (testes em tests/s3probe.php).
     */
    static public function sign ($method, $url, array $headers, $payloadHash, $access, $secret, $region, $time)
    {
        $parts = parse_url ($url);

        $host = $parts ['host'] . (isset ($parts ['port']) ? ':'. $parts ['port'] : '');

        $date = gmdate ('Ymd\THis\Z', $time);

        $day = substr ($date, 0, 8);

        $all = [];

        foreach ($headers as $name => $value)
            $all [strtolower (trim ($name))] = trim (preg_replace ('/\s+/', ' ', (string) $value));

        $all ['host'] = $host;
        $all ['x-amz-date'] = $date;
        $all ['x-amz-content-sha256'] = $payloadHash;

        ksort ($all, SORT_STRING);

        $canonicalHeaders = '';

        foreach ($all as $name => $value)
            $canonicalHeaders .= $name .':'. $value ."\n";

        $signedHeaders = implode (';', array_keys ($all));

        // caminho: cada segmento codificado (RFC 3986), as barras mantidas
        $path = isset ($parts ['path']) && $parts ['path'] !== '' ? $parts ['path'] : '/';

        $path = implode ('/', array_map (function ($s) { return rawurlencode (rawurldecode ($s)); }, explode ('/', $path)));

        // query: pares codificados e ordenados; chave sem valor ('?uploads') vira 'uploads='
        $query = [];

        if (isset ($parts ['query']) && $parts ['query'] !== '')
            foreach (explode ('&', $parts ['query']) as $pair)
            {
                $kv = explode ('=', $pair, 2);

                $query [] = rawurlencode (rawurldecode ($kv [0])) .'='. rawurlencode (rawurldecode (isset ($kv [1]) ? $kv [1] : ''));
            }

        sort ($query, SORT_STRING);

        $canonical = implode ("\n", [ strtoupper ($method), $path, implode ('&', $query), $canonicalHeaders, $signedHeaders, $payloadHash ]);

        $scope = $day .'/'. $region .'/s3/aws4_request';

        $toSign = implode ("\n", [ 'AWS4-HMAC-SHA256', $date, $scope, hash ('sha256', $canonical) ]);

        $key = hash_hmac ('sha256', 'aws4_request', hash_hmac ('sha256', 's3', hash_hmac ('sha256', $region, hash_hmac ('sha256', $day, 'AWS4'. $secret, TRUE), TRUE), TRUE), TRUE);

        $all ['authorization'] = 'AWS4-HMAC-SHA256 Credential='. $access .'/'. $scope .', SignedHeaders='. $signedHeaders .', Signature='. hash_hmac ('sha256', $toSign, $key);

        unset ($all ['host']);

        return $all;
    }

    /** Builds válidas deste servidor (builds/*.json e o legado builds.json). Nunca lança. */
    static public function builds ($data = '/data')
    {
        $builds = [];

        try
        {
            $files = glob ($data .'/builds/*.json') ?: [];

            sort ($files);

            // o helper/error.php transforma warning em exceção mesmo com '@': ler só o que existe
            foreach ($files as $file)
                if (is_readable ($file)) $builds [] = json_decode ((string) file_get_contents ($file));

            $legacy = $data .'/builds.json';

            $legacy = is_readable ($legacy) ? json_decode ((string) file_get_contents ($legacy)) : NULL;

            if (is_array ($legacy)) $builds = array_merge ($legacy, $builds);
        }
        catch (\Throwable $e) {}

        return array_values (array_filter ($builds, function ($b) {
            return is_object ($b) && isset ($b->project, $b->app, $b->stage) && is_string ($b->project) && is_string ($b->app) && is_string ($b->stage)
                && preg_match ('/^[a-z0-9-]+$/', $b->project .$b->app .$b->stage);
        }));
    }

    /**
     * Diretórios do bucket onde testar a gravação ('<projeto>/<app>/<estágio>'), para
     * as builds que usam a mesma configuração. Nunca lança.
     *
     * Com $project (configuração própria do projeto, <data>/s3/<projeto>.json): o de
     * uma build dele, de preferência com auto.cloud ligado; sem build dele, '<projeto>'.
     *
     * Sem $project (configuração do servidor, no .env): um por projeto que NÃO tem
     * configuração própria e tem build com auto.cloud ligado, porque a chave pode
     * estar restrita a parte deles; nenhum com a cópia ligada = só o da primeira
     * build. [''] sem builds no servidor e [] quando todas usam configuração própria.
     */
    static public function prefixes ($data = '/data', $project = NULL)
    {
        $builds = self::builds ($data);

        $dir = function ($b) { return $b->project .'/'. $b->app .'/'. $b->stage; };

        $cloud = function ($b) { return isset ($b->auto) && is_object ($b->auto) && !empty ($b->auto->cloud); };

        if ($project !== NULL)
        {
            $own = array_values (array_filter ($builds, function ($b) use ($project) { return $b->project === $project; }));

            if (!sizeof ($own)) return [ $project ];

            foreach ($own as $b)
                if ($cloud ($b)) return [ $dir ($b) ];

            return [ $dir ($own [0]) ];
        }

        if (!sizeof ($builds)) return [ '' ];

        $builds = array_values (array_filter ($builds, function ($b) use ($data) { return !file_exists ($data .'/s3/'. $b->project .'.json'); }));

        if (!sizeof ($builds)) return [];

        $prefixes = [];

        foreach ($builds as $b)
            if ($cloud ($b) && !isset ($prefixes [$b->project])) $prefixes [$b->project] = $dir ($b);

        return sizeof ($prefixes) ? array_values ($prefixes) : [ $dir ($builds [0]) ];
    }

    /**
     * run () em cada diretório de $prefixes; para na primeira falha. Devolve ['ok' =>
     * bool, 'message' => string, 'tested' => quantos passaram]. Nunca lança.
     */
    static public function runAll ($endpoint, $bucket, $access, $secret, $region, array $prefixes)
    {
        if (!sizeof ($prefixes))
            return [ 'ok' => TRUE, 'tested' => 0, 'message' => 'writing not tested (no build of this server uses these settings)' ];

        $tested = 0;

        foreach ($prefixes as $prefix)
        {
            $result = self::run ($endpoint, $bucket, $access, $secret, $region, $prefix);

            if (!$result ['ok']) return $result + [ 'tested' => $tested ];

            $tested++;
        }

        if ($tested === 1) return $result + [ 'tested' => 1 ];

        return [ 'ok' => TRUE, 'tested' => $tested, 'message' => 'writing ok (test uploads opened and aborted in the folders of '. $tested .' projects)' ];
    }

    /**
     * Abre e aborta um upload multipart. Devolve ['ok' => bool, 'message' => string,
     * 'key' => chave testada]. Nunca lança.
     */
    static public function run ($endpoint, $bucket, $access, $secret, $region, $prefix = '')
    {
        $key = ($prefix !== '' ? trim ($prefix, '/') .'/' : '') .'.releaser-write-test-'. bin2hex (random_bytes (6));

        $base = rtrim ($endpoint, '/') .'/'. rawurlencode ($bucket) .'/'. implode ('/', array_map ('rawurlencode', explode ('/', $key)));

        $region = $region !== '' ? $region : 'auto';

        // onde o teste grava, para as mensagens: 'backup/proj/app/estágio/' ou 'backup/'
        $where = $bucket .'/'. ($prefix !== '' ? trim ($prefix, '/') .'/' : '');

        try
        {
            $client = new \GuzzleHttp\Client ([ 'http_errors' => FALSE, 'connect_timeout' => self::TIMEOUT, 'timeout' => self::TIMEOUT, 'allow_redirects' => FALSE ]);

            $url = $base .'?uploads';

            $res = $client->request ('POST', $url, [ 'headers' => self::sign ('POST', $url, [], self::EMPTY_HASH, $access, $secret, $region, time ()) ]);

            $body = (string) $res->getBody ();

            if ($res->getStatusCode () !== 200 || !preg_match ('/<UploadId>([^<]+)<\/UploadId>/', $body, $match))
                return [ 'ok' => FALSE, 'key' => $key, 'message' => 'writing denied or failed: '. self::reason ($res->getStatusCode (), $body, $where) ];

            $url = $base .'?uploadId='. rawurlencode (html_entity_decode ($match [1], ENT_QUOTES | ENT_XML1));

            $res = $client->request ('DELETE', $url, [ 'headers' => self::sign ('DELETE', $url, [], self::EMPTY_HASH, $access, $secret, $region, time ()) ]);

            if ($res->getStatusCode () !== 204 && $res->getStatusCode () !== 200)
                return [ 'ok' => FALSE, 'key' => $key, 'message' => 'writing ok, but the test upload was not aborted ('. self::reason ($res->getStatusCode (), (string) $res->getBody (), $where) .'; the key needs s3:AbortMultipartUpload, and the provider discards stale uploads by itself)' ];

            return [ 'ok' => TRUE, 'key' => $key, 'message' => 'writing ok (test upload opened and aborted in '. $where .')' ];
        }
        catch (\Throwable $e)
        {
            return [ 'ok' => FALSE, 'key' => $key, 'message' => 'writing test failed: '. str_replace ([ $secret, $access ], '***', $e->getMessage ()) ];
        }
    }

    /** Motivo legível de uma resposta de erro do S3 (código e mensagem do XML). */
    static private function reason ($status, $body, $where)
    {
        $code = preg_match ('/<Code>([^<]+)<\/Code>/', $body, $m) ? $m [1] : '';

        if (in_array ($code, [ 'InvalidAccessKeyId', 'SignatureDoesNotMatch' ]))
            return 'access denied, HTTP '. $status .' '. $code .' (check the access key, the secret key and the region)';

        if ($status === 403 || $code === 'AccessDenied')
            return 'access denied, HTTP '. $status . ($code !== '' ? ' '. $code : '') .' (the key needs s3:PutObject and s3:AbortMultipartUpload in '. $where .')';

        if ($code === 'NoSuchBucket' || $status === 404)
            return 'bucket not found, HTTP '. $status;

        return 'HTTP '. $status . ($code !== '' ? ' '. $code : '');
    }
}

// Linha de comando (assistente do bin/io): variáveis S3_* do ambiente, saída de uma linha
if (PHP_SAPI === 'cli' && isset ($argv [0]) && realpath ($argv [0]) === __FILE__)
{
    require_once __DIR__ .'/../vendor/autoload.php';

    $value = function ($name) { return trim ((string) getenv ($name)); };

    // php class/S3Probe.php [/data] [projeto]: com o projeto, testa a pasta dele; sem, as dos projetos que usam o .env
    $project = isset ($argv [2]) && preg_match ('/^[a-z0-9][a-z0-9-]+[a-z0-9]$/', $argv [2]) ? $argv [2] : NULL;

    $result = S3Probe::runAll ($value ('S3_ENDPOINT'), $value ('S3_BUCKET'), $value ('S3_ACCESS_KEY'), $value ('S3_SECRET_KEY'), $value ('S3_REGION'), S3Probe::prefixes (isset ($argv [1]) ? $argv [1] : '/data', $project));

    echo $result ['message'] ."\n";

    exit ($result ['ok'] ? 0 : 1);
}
