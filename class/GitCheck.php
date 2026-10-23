<?php

/**
 * Teste rápido do acesso do Releaser ao GitLab, pelos dois caminhos de que o
 * deploy depende:
 *
 * - API (GITLAB_TOKEN, porta 443): GET /api/v4/personal_access_tokens/self, que
 *   devolve validade e escopos do próprio token. Quando o GitLab recusa (401), o
 *   motivo vem no corpo ou no WWW-Authenticate: "Token is expired…" ou "Token
 *   was revoked…".
 * - Clone (GITLAB_SSH): por SSH, `ssh -T`, que responde "Welcome to GitLab,
 *   @usuário!"; por HTTPS (GITLAB_SSH=https://oauth2:<token>@…), `git
 *   ls-remote` no repositório da primeira build configurada.
 *
 * Usado pelo menu do `io` (uma linha de resumo), pelo `io check` (detalhe) e
 * pelo daemon diário `check:daemon`, que só manda e-mail quando há o que fazer
 * (ver alert ()). Nunca lança exceção: o menu não pode quebrar por causa dele.
 */
class GitCheck
{
    const CONNECT_TIMEOUT = 3; // segundos
    const TIMEOUT = 5; // segundos, por teste
    const SLOW = 2000; // milissegundos
    const EXPIRING = 14; // dias

    static public function run ($data = '/data')
    {
        $api = self::api ();

        return [
            'api' => $api,
            'token' => $api ['token'],
            'clone' => self::cloneAccess ($data)
        ];
    }

    static public function url ()
    {
        $url = rtrim (trim ((string) getenv ('GITLAB_URL')), '/');

        return $url == '' ? 'https://git.embrapa.io' : $url;
    }

    /**
     * Alcance e tempo da API, e o estado do token. 'token' vem NULL quando a API
     * não respondeu (não há como saber do token).
     */
    static public function api ()
    {
        $url = self::url ();

        $host = (string) parse_url ($url, PHP_URL_HOST);

        if (!self::resolves ($host))
            return self::result ('ERROR', 'unreachable: cannot resolve '. $host .' (DNS)', [ 'token' => NULL ]);

        $client = new GuzzleHttp\Client ([
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
            'http_errors' => FALSE,
            'headers' => [
                'PRIVATE-TOKEN' => trim ((string) getenv ('GITLAB_TOKEN')),
                'Accept' => 'application/json'
            ]
        ]);

        $start = microtime (TRUE);

        try
        {
            $response = $client->get ($url .'/api/v4/personal_access_tokens/self');
        }
        catch (GuzzleHttp\Exception\ConnectException $e)
        {
            return self::result ('ERROR', 'unreachable: '. self::curlReason ($e), [ 'token' => NULL ]);
        }
        catch (\Throwable $e)
        {
            return self::result ('ERROR', 'request failed: '. $e->getMessage (), [ 'token' => NULL ]);
        }

        $ms = (int) round ((microtime (TRUE) - $start) * 1000);

        $status = $response->getStatusCode ();

        if ($status >= 500)
            return self::result ('ERROR', 'GitLab answered HTTP '. $status .' ('. $ms .' ms)', [ 'ms' => $ms, 'token' => NULL ]);

        $api = $ms >= self::SLOW
            ? self::result ('WARNING', 'slow: '. $ms .' ms', [ 'ms' => $ms ])
            : self::result ('OK', 'ok ('. $ms .' ms)', [ 'ms' => $ms ]);

        $api ['token'] = self::token ($status, (string) $response->getBody (), $response->getHeaderLine ('WWW-Authenticate'));

        return $api;
    }

    static private function token ($status, $body, $authenticate)
    {
        $json = json_decode ($body, TRUE);

        if ($status === 401)
        {
            $reason = $body .' '. $authenticate;

            if (stripos ($reason, 'expired') !== FALSE)
                return self::result ('ERROR', 'EXPIRED', [ 'alert' => TRUE ]);

            if (stripos ($reason, 'revoked') !== FALSE)
                return self::result ('ERROR', 'REVOKED', [ 'alert' => TRUE ]);

            return self::result ('ERROR', 'REFUSED (invalid token)', [ 'alert' => TRUE ]);
        }

        if ($status === 403)
        {
            $message = is_array ($json) ? (string) ($json ['message'] ?? $json ['error_description'] ?? $json ['error'] ?? '') : '';

            return self::result ('ERROR', 'REFUSED (HTTP 403'. ($message != '' ? ': '. $message : '') .')', [ 'alert' => TRUE ]);
        }

        if ($status !== 200 || !is_array ($json))
            return self::result ('WARNING', 'cannot read token details (HTTP '. $status .')');

        $details = [
            'name' => (string) ($json ['name'] ?? ''),
            'scopes' => is_array ($json ['scopes'] ?? NULL) ? $json ['scopes'] : [],
            'expires' => $json ['expires_at'] ?? NULL,
            'used' => $json ['last_used_at'] ?? NULL,
            'days' => NULL
        ];

        if (!array_intersect ([ 'read_api', 'api' ], $details ['scopes']))
            return self::result ('ERROR', 'missing the read_api scope (has: '. implode (', ', $details ['scopes']) .')', $details + [ 'alert' => TRUE ]);

        if (!$details ['expires'])
            return self::result ('OK', 'ok, never expires', $details);

        // O GitLab invalida o token às 00:00 UTC da data de expiração.
        $today = new DateTime ('today', new DateTimeZone ('UTC'));

        $expires = new DateTime ($details ['expires'], new DateTimeZone ('UTC'));

        $details ['days'] = (int) $today->diff ($expires)->format ('%r%a');

        $when = 'expires on '. $details ['expires'] .', in '. $details ['days'] .' day'. ($details ['days'] == 1 ? '' : 's');

        if ($details ['days'] > self::EXPIRING)
            return self::result ('OK', 'ok, '. $when, $details);

        // No daemon (diário), o aviso sai aos 14 dias e todo dia na última semana.
        return self::result ('WARNING', 'EXPIRES'. substr ($when, 7), $details + [ 'alert' => $details ['days'] == self::EXPIRING || $details ['days'] <= 7 ]);
    }

    /**
     * O caminho do clone. Por SSH, a chave do container; por HTTPS, o token da
     * URL, testado no repositório da primeira build configurada.
     */
    static public function cloneAccess ($data = '/data')
    {
        $remote = trim ((string) getenv ('GITLAB_SSH'));

        if ($remote == '') $remote = 'ssh://git@git.embrapa.io';

        $scheme = strtolower ((string) parse_url ($remote, PHP_URL_SCHEME));

        if ($scheme == 'https' || $scheme == 'http') return self::https ($remote, $data);

        $user = (string) (parse_url ($remote, PHP_URL_USER) ?: 'git');
        $host = (string) parse_url ($remote, PHP_URL_HOST);
        $port = (int) (parse_url ($remote, PHP_URL_PORT) ?: 22);

        // O bin/io restaura a chave de /data/ssh; os jobs do cron, não. Container
        // recriado sem ninguém rodar o `io` fica sem chave e todo clone falha.
        if (!file_exists ('/root/.ssh/id_rsa'))
            return self::result ('ERROR', 'no SSH key in the container (run "io" once to restore it from /data/ssh)', [ 'alert' => TRUE, 'via' => 'SSH' ]);

        if (!self::resolves ($host))
            return self::result ('ERROR', 'unreachable: cannot resolve '. $host .' (DNS)', [ 'via' => 'SSH' ]);

        $cmd = 'timeout '. self::TIMEOUT .' /usr/bin/ssh -T -p '. $port
            .' -o BatchMode=yes -o ConnectTimeout='. self::CONNECT_TIMEOUT
            .' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR '
            . escapeshellarg ($user .'@'. $host) .' 2>&1 < /dev/null';

        $start = microtime (TRUE);

        exec ($cmd, $output, $return);

        $ms = (int) round ((microtime (TRUE) - $start) * 1000);

        $text = trim (implode (' ', $output));

        if (preg_match ('/Welcome to GitLab, (@[^!\s]+)/', $text, $match))
            return $ms >= self::SLOW
                ? self::result ('WARNING', 'slow: '. $ms .' ms, as '. $match [1], [ 'ms' => $ms, 'via' => 'SSH' ])
                : self::result ('OK', 'ok as '. $match [1] .' ('. $ms .' ms)', [ 'ms' => $ms, 'via' => 'SSH' ]);

        if (stripos ($text, 'Permission denied') !== FALSE)
            return self::result ('ERROR', 'key REFUSED by GitLab (add /data/ssh.pub to a user with access to the builds)', [ 'alert' => TRUE, 'via' => 'SSH' ]);

        if ($return == 124 || $return == 143 || stripos ($text, 'timed out') !== FALSE)
            return self::result ('ERROR', 'unreachable: no answer on port '. $port .' (timeout; blocked?)', [ 'via' => 'SSH' ]);

        return self::result ('ERROR', 'unreachable: '. ($text != '' ? $text : 'ssh exit code '. $return), [ 'via' => 'SSH' ]);
    }

    static private function https ($remote, $data)
    {
        $repo = NULL;

        try
        {
            foreach (Controller::loadBuilds ($data) as $trash => $b)
            {
                $repo = $b->project .'/'. $b->app;

                break;
            }
        }
        catch (\Throwable $e) {}

        if (!$repo)
            return self::result ('WARNING', 'not tested (no builds configured)', [ 'via' => 'HTTPS' ]);

        $start = microtime (TRUE);

        exec ('timeout '. self::TIMEOUT .' /usr/bin/git -c http.lowSpeedTime='. self::TIMEOUT .' ls-remote --heads '. escapeshellarg (rtrim ($remote, '/') .'/'. $repo .'.git') .' 2>&1 < /dev/null', $output, $return);

        $ms = (int) round ((microtime (TRUE) - $start) * 1000);

        // O Git costuma omitir as credenciais da URL nas mensagens; garante.
        $text = preg_replace ('/\/\/[^\/@\s]+@/', '//***@', trim (implode (' ', array_slice ($output, -2))));

        if ($return === 0)
            return $ms >= self::SLOW
                ? self::result ('WARNING', 'slow: '. $ms .' ms ('. $repo .')', [ 'ms' => $ms, 'via' => 'HTTPS' ])
                : self::result ('OK', 'ok ('. $repo .', '. $ms .' ms)', [ 'ms' => $ms, 'via' => 'HTTPS' ]);

        if ($return == 124 || $return == 143)
            return self::result ('ERROR', 'unreachable: no answer (timeout)', [ 'via' => 'HTTPS' ]);

        if (preg_match ('/Authentication failed|HTTP Basic: Access denied|not found|403/i', $text))
            return self::result ('ERROR', 'REFUSED for '. $repo .' ('. $text .')', [ 'alert' => TRUE, 'via' => 'HTTPS' ]);

        return self::result ('ERROR', 'failed: '. $text, [ 'via' => 'HTTPS' ]);
    }

    /** Resumo para o menu e para o `io check`: "Error!" em vermelho, "Warning!" em amarelo e "Success!" em verde. */
    static public function summary ($check, $plain = TRUE)
    {
        $worst = 'OK';

        foreach ([ 'api', 'token', 'clone' ] as $key)
        {
            if (!$check [$key]) continue;

            if ($check [$key]['status'] == 'ERROR') $worst = 'ERROR';
            elseif ($check [$key]['status'] == 'WARNING' && $worst == 'OK') $worst = 'WARNING';
        }

        $parts = [ 'API '. $check ['api']['message'] ];

        if ($check ['token']) $parts [] = 'Token '. $check ['token']['message'];

        $parts [] = ($check ['clone']['via'] ?? 'Clone') .': '. $check ['clone']['message'];

        $label = [ 'OK' => [ 'Success!', 'green' ], 'WARNING' => [ 'Warning!', 'yellow' ], 'ERROR' => [ 'Error!', 'red' ] ][$worst];

        return Menu::paint ($label [0], $label [1], $plain) .' '. implode (' | ', $parts) . ($worst == 'OK' ? '' : "\nRun 'io check' for details.");
    }

    /** Há o que avisar por e-mail? Indisponibilidade passageira não conta. */
    static public function alert ($check)
    {
        foreach ([ 'token', 'clone' ] as $key)
            if ($check [$key] && !empty ($check [$key]['alert'])) return TRUE;

        return FALSE;
    }

    static private function resolves ($host)
    {
        if ($host == '') return FALSE;

        if (filter_var ($host, FILTER_VALIDATE_IP)) return TRUE;

        return gethostbyname ($host) !== $host;
    }

    static private function curlReason (GuzzleHttp\Exception\ConnectException $e)
    {
        $errno = (int) ($e->getHandlerContext () ['errno'] ?? 0);

        switch ($errno)
        {
            case 6: return 'cannot resolve host (DNS)';
            case 7: return 'connection refused';
            case 28: return 'no answer (timeout; blocked or overloaded?)';
            case 35:
            case 60: return 'TLS failure ('. $e->getMessage () .')';
        }

        return $e->getMessage ();
    }

    static private function result ($status, $message, $extra = [])
    {
        return [ 'status' => $status, 'message' => $message ] + $extra;
    }
}
