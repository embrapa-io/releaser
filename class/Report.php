<?php

/**
 * Relato ao core da plataforma (core.embrapa.io): o que este Releaser sabe
 * sobre as builds que implanta — versão (a cada deploy), saúde dos serviços
 * (a cada minuto) e CVEs das imagens em execução (Trivy, a cada hora).
 *
 * Rotas do backend (business/releaser.js): POST /releaser/report, com o lote
 * de builds, e GET /releaser/build/:p/:a/:s, com o que só o core sabe ou cria
 * para uma build (DSN do Sentry, site e token do Matomo, equipe, variáveis).
 *
 * Autenticação: o mesmo GITLAB_TOKEN do /data/.env, em Authorization: Bearer.
 * O core valida o token no GitLab e exige Developer no repositório da app.
 *
 * send() NUNCA lança exceção: falha de rede vira WARNING e a próxima rodada
 * tenta de novo. 401/403/409 também viram WARNING, sem retentativa — repetir
 * não adianta (token inválido, sem acesso ou estágio implantado pela
 * plataforma num cluster). O resultado de cada build fica em
 * /data/.report/<p>_<a>_<s>.json para o `io info`.
 */
class Report
{
    const TIMEOUT = 30;

    static private function client ()
    {
        return new GuzzleHttp\Client ([
            'base_uri' => Controller::apiUrl () .'/',
            'connect_timeout' => 10,
            'timeout' => self::TIMEOUT,
            'http_errors' => FALSE,
            'headers' => [
                'Authorization' => 'Bearer '. trim ((string) getenv ('GITLAB_TOKEN')),
                'Accept' => 'application/json'
            ]
        ]);
    }

    static private function dir ()
    {
        global $_data;

        $dir = $_data . DIRECTORY_SEPARATOR .'.report';

        if (!file_exists ($dir)) @mkdir ($dir, 0755, TRUE);

        return $dir;
    }

    /**
     * Envia um lote de builds. Cada item: ['project', 'app', 'stage'] e, opcionais,
     * 'version' (string), 'services' (serviço => campos) e 'cve' (['services' => ...]).
     * Devolve TRUE se o core aceitou todas as builds do lote.
     */
    static public function send (array $items, $quiet = FALSE)
    {
        if (!sizeof ($items)) return TRUE;

        $body = [
            'server' => trim ((string) getenv ('SERVER')),
            'releaser' => (string) (getenv ('IO_RELEASER_VERSION') ?: 'dev'),
            'orchestrator' => getenv ('ORCHESTRATOR') == 'DockerSwarm' ? 'swarm' : 'compose',
            'builds' => array_values ($items)
        ];

        $sent = [];

        // sinais enviados por build, com o nome que o `io info` mostra (services -> health)
        $names = [ 'version' => 'version', 'services' => 'health', 'cve' => 'cve' ];

        foreach ($items as $item)
            $sent [$item ['project'] .'/'. $item ['app'] .'@'. $item ['stage']] = array_values (array_map (function ($k) use ($names) { return $names [$k]; }, array_intersect (array_keys ($item), array_keys ($names))));

        // Corpo e Content-Length explícitos: com a opção 'json' o Guzzle calcula o
        // Content-Length como inteiro e o psr7 2.11 emite uma deprecação, que o
        // helper/error.php transforma em exceção.
        $json = json_encode ($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // No daemon de cada minuto ($quiet), um problema persistente (core fora, token
        // inválido, 409) só é avisado quando muda: senão seriam 1.440 WARNINGs por dia
        // no Sentry. O estado anterior fica em /data/.report/<build>.json.
        $warn = function ($build, array $keys, $status, $reason, $message) use ($quiet)
        {
            $changed = !$quiet || self::changed ($build, $keys, $status, $reason);

            self::remember ($build, $keys, $status, $reason);

            if ($changed) echo "WARNING > ". $message ." \n";
        };

        try
        {
            $response = self::client ()->request ('POST', 'releaser/report', [
                'body' => $json,
                'headers' => [ 'Content-Type' => 'application/json', 'Content-Length' => (string) strlen ($json) ]
            ]);
        }
        catch (Exception $e)
        {
            $reason = 'no connection to the core: '. $e->getMessage ();

            $first = TRUE;

            foreach ($sent as $build => $keys)
            {
                $warn ($build, $keys, 0, $reason, $first ? "Unable to report to '". Controller::apiUrl () ."': ". $e->getMessage () : '');

                $first = FALSE;
            }

            return FALSE;
        }

        $status = $response->getStatusCode ();

        $data = json_decode ((string) $response->getBody ());

        if ($status !== 200 || !is_object ($data))
        {
            $reason = self::reason ($status, $data);

            $first = TRUE;

            foreach ($sent as $build => $keys)
            {
                $warn ($build, $keys, $status, $reason, $first ? "The core refused the report (HTTP ". $status ."): ". $reason : '');

                $first = FALSE;
            }

            return FALSE;
        }

        $ok = TRUE;

        foreach ((array) ($data->accepted ?? []) as $a)
        {
            $build = is_object ($a) ? (string) ($a->build ?? '') : (string) $a;

            if (!$quiet) echo "SUCCESS > Reported ". $build ." to the core (". implode (', ', is_object ($a) ? (array) ($a->done ?? []) : []) ."). \n";

            self::remember ($build, $sent [$build] ?? [], 200, 'accepted');
        }

        foreach ((array) ($data->rejected ?? []) as $r)
        {
            $ok = FALSE;

            $build = (string) ($r->build ?? '?');

            // o core devolve 'p/a/s' nas recusas de campo inválido; normaliza para 'p/a@s'
            if (preg_match ('#^([^/]+)/([^/]+)/([^/]+)$#', $build, $m)) $build = $m [1] .'/'. $m [2] .'@'. $m [3];

            $warn ($build, $sent [$build] ?? [], (int) ($r->code ?? 0), (string) ($r->reason ?? ''),
                "The core rejected ". $build ." (". (int) ($r->code ?? 0) ."): ". (string) ($r->reason ?? '') . (isset ($r->info) ? ' ['. $r->info .']' : ''));
        }

        return $ok;
    }

    /** O resultado (status + motivo) de algum dos sinais da build mudou em relação ao último registrado? */
    static private function changed ($build, array $keys, $status, $reason)
    {
        if (!preg_match ('#^([a-z0-9-]+)/([a-z0-9-]+)@(alpha|beta|release)$#', $build, $m)) return TRUE;

        $last = self::last ($m [1], $m [2], $m [3]);

        foreach ($keys as $key)
            if (!isset ($last [$key]) || (int) $last [$key]['status'] !== (int) $status || (string) $last [$key]['reason'] !== (string) $reason) return TRUE;

        return FALSE;
    }

    /**
     * GET /releaser/build/:p/:a/:s — o que a plataforma sabe (ou cria) para a build.
     * Lança exceção com mensagem legível em qualquer falha.
     */
    static public function build ($project, $app, $stage)
    {
        $path = 'releaser/build/'. rawurlencode ($project) .'/'. rawurlencode ($app) .'/'. rawurlencode ($stage) .'?server='. rawurlencode (trim ((string) getenv ('SERVER')));

        try
        {
            $response = self::client ()->request ('GET', $path);
        }
        catch (Exception $e)
        {
            throw new Exception ("Unable to reach '". Controller::apiUrl () ."': ". $e->getMessage ());
        }

        $status = $response->getStatusCode ();

        $data = json_decode ((string) $response->getBody ());

        if ($status !== 200 || !is_object ($data))
            throw new Exception ("The core answered HTTP ". $status .": ". self::reason ($status, $data));

        return $data;
    }

    static private function reason ($status, $data)
    {
        $code = is_object ($data) && isset ($data->code) ? (string) $data->code : '';

        $map = [
            'INVALID_TOKEN' => "the GITLAB_TOKEN of this Releaser was refused by GitLab (expired or revoked?)",
            'TOO_MANY_REQUESTS' => "too many requests with this token; try again in a minute",
            'GITLAB_UNAVAILABLE' => "the core could not validate the token in GitLab",
            'IS_NOT_DEVELOPER' => "the owner of the GITLAB_TOKEN is not a Developer of this app's repository",
            'INEXISTENT_PROJECT' => "project not found in the platform",
            'INEXISTENT_APP' => "app not found in the platform",
            'FIELD_EMPTY_OR_INVALID' => "invalid field". (is_object ($data) && isset ($data->info) ? ' ('. $data->info .')' : '')
        ];

        if (array_key_exists ($code, $map)) return $map [$code];

        if ($code !== '') return $code . (isset ($data->info) ? ' ('. (is_string ($data->info) ? $data->info : json_encode ($data->info)) .')' : '');

        return $status >= 500 ? 'core unavailable' : 'unexpected answer';
    }

    static private function remember ($build, array $keys, $status, $reason)
    {
        if (!preg_match ('#^([a-z0-9-]+)/([a-z0-9-]+)@(alpha|beta|release)$#', $build, $m)) return;

        $file = self::dir () . DIRECTORY_SEPARATOR . $m [1] .'_'. $m [2] .'_'. $m [3] .'.json';

        $previous = file_exists ($file) ? json_decode ((string) @file_get_contents ($file), TRUE) : NULL;

        $state = is_array ($previous) ? $previous : [];

        foreach ($keys as $key)
            $state [$key] = [ 'at' => date (DATE_ATOM), 'status' => (int) $status, 'reason' => (string) $reason ];

        @file_put_contents ($file, json_encode ($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** Último resultado de cada sinal da build, para o `io info`. */
    static public function last ($project, $app, $stage)
    {
        $file = self::dir () . DIRECTORY_SEPARATOR . $project .'_'. $app .'_'. $stage .'.json';

        if (!file_exists ($file)) return [];

        $data = json_decode ((string) @file_get_contents ($file), TRUE);

        return is_array ($data) ? $data : [];
    }
}
