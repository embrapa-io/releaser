<?php

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Envio dos e-mails do Releaser.
 *
 * O Releaser roda em servidores das UDs, cada um com o SMTP que houver por lá
 * (relay do Google Workspace, SMTP interno da unidade, Gmail com senha de app,
 * Mailtrap...). Falhas vistas em campo (Sentry, set/2026) e o que se faz aqui:
 *
 * - EHLO "[127.0.0.1]" recusado pelo smtp-relay.gmail.com ("421 4.7.0 Try again
 *   later, closing connection. (EHLO)", RELEASER-40): o Symfony usa esse nome
 *   quando `local_domain` não é informado. O nome agora é SMTP_HELO, ou SERVER,
 *   ou o hostname (se forem FQDN), ou o domínio do SMTP_FROM;
 * - porta bloqueada no firewall da unidade (timeout de 60 s a cada e-mail,
 *   RELEASER-G): timeout curto (SMTP_TIMEOUT, 15 s) e, se a conexão não sai,
 *   as outras portas de submissão (587, 465 e, sem autenticação, 25). SMTP_HOST
 *   aceita uma lista separada por vírgula (failover);
 * - SMTP fora do ar ou mal configurado: depois da primeira falha a execução não
 *   tenta de novo (disjuntor) e o e-mail vai para a fila em /data/.mail, reenviada
 *   nas próximas execuções (até QUEUE_DAYS dias, no máximo QUEUE_MAX mensagens);
 * - senha com '@', ':', '/', '#' quebrava o DSN montado por concatenação: o
 *   transporte é montado direto, sem DSN;
 * - endereço inválido no `team` do builds.json ou no SMTP_FROM lançava exceção
 *   fora do try e abortava a operação: endereços inválidos são descartados.
 *
 * Nenhuma falha de e-mail aborta a operação (nem a fila de builds do daemon).
 */
class Mail
{
    const TIMEOUT = 15;

    const QUEUE_MAX = 100;

    const QUEUE_DAYS = 7;

    static private $single = FALSE;

    private $transports = [];

    private $current = 0;

    private $down = FALSE;

    private $reported = FALSE;

    private $flushed = FALSE;

    private $verbose = FALSE;

    private $from = null;

    private $log = [];

    private $helo = null;

    private final function __construct ()
	{
        $hosts = self::split (self::env ('SMTP_HOST'));
        $port = (int) self::env ('SMTP_PORT');
        $user = self::env ('SMTP_USER');
        $pass = self::env ('SMTP_PASS');
        $auth = $user !== '' && $pass !== '';
        $secure = in_array (strtolower (self::env ('SMTP_SECURE')), [ 'yes', '1', 'true' ]);
        $timeout = (float) self::env ('SMTP_TIMEOUT');

        $this->helo = self::helo ();

        // Porta configurada primeiro, depois as outras portas de submissão. A 25
        // só sem autenticação: é a porta de relay por IP e não leva senha.
        $ports = array_unique (array_merge ([ $port ? $port : 587 ], $auth ? [ 587, 465 ] : [ 587, 465, 25 ]));

        foreach ($ports as $p)
            foreach ($hosts as $host)
            {
                $transport = new EsmtpTransport ($host, $p, $p === 465);

                $transport->setLocalDomain ($this->helo);

                if ($auth)
                {
                    $transport->setUsername ($user);
                    $transport->setPassword ($pass);
                }

                $stream = $transport->getStream ();

                $stream->setTimeout ($timeout > 0 ? $timeout : self::TIMEOUT);

                // SMTP_SECURE diferente de 'yes' aceita certificado inválido e TLS
                // legado (servidores internos antigos que o OpenSSL 3 recusa).
                if (!$secure)
                    $stream->setStreamOptions ([ 'ssl' => [
                        'verify_peer' => FALSE,
                        'verify_peer_name' => FALSE,
                        'allow_self_signed' => TRUE,
                        'security_level' => 0
                    ] ]);

                $this->transports [$host .':'. $p] = $transport;
            }

        foreach ([ self::env ('SMTP_FROM'), $user, 'no-reply@embrapa.br' ] as $from)
            if ($this->from = self::address ($from))
                break;

        $this->log = self::addresses (self::split (self::env ('LOG_MAIL')));
    }

    public function __destruct ()
    {
        foreach ($this->transports as $transport)
            try { $transport->stop (); } catch (\Throwable $e) {}
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    /**
     * Envia (ou enfileira) um e-mail para LOG_MAIL, com `$cc` em cópia.
     * Nunca lança exceção. Retorna TRUE se o e-mail saiu agora.
     */
    public function send ($subject, $message, $cc = [])
    {
        $mail = [
            'created' => time (),
            'subject' => (string) $subject,
            'text' => is_string ($message) ? $message : '',
            'cc' => is_array ($cc) ? array_values ($cc) : []
        ];

        try
        {
            if ($this->deliver ($mail))
            {
                $this->flush ();

                return TRUE;
            }

            $this->enqueue ($mail);
        }
        catch (\Throwable $e)
        {
            echo "WARNING > Impossible to send e-mail '". $subject ."': ". $e->getMessage () ." \n";

            try { \Sentry\captureException ($e); } catch (\Throwable $ignored) {}
        }

        return FALSE;
    }

    /**
     * Comando `io mail`: mostra a configuração efetiva, testa o DNS e envia o
     * e-mail de teste sem enfileirar em caso de falha.
     */
    public function test ($subject, $message, $addresses)
    {
        $this->verbose = TRUE;

        echo "INFO > From: ". ($this->from ? $this->from->toString () : '-') ." \n";
        echo "INFO > To: ". implode (', ', array_merge ($this->log, $addresses)) ." \n";
        echo "INFO > HELO/EHLO name: ". $this->helo ." \n";
        echo "INFO > Authentication: ". (self::env ('SMTP_USER') !== '' && self::env ('SMTP_PASS') !== '' ? "as '". self::env ('SMTP_USER') ."'" : 'none') ." \n";
        echo "INFO > Certificate verification: ". (in_array (strtolower (self::env ('SMTP_SECURE')), [ 'yes', '1', 'true' ]) ? 'yes' : 'no') ." \n";

        foreach (self::split (self::env ('SMTP_HOST')) as $host)
        {
            $ips = gethostbynamel ($host);

            echo ($ips ? "INFO" : "WARNING") ." > DNS of '". $host ."': ". ($ips ? implode (', ', $ips) : 'not resolved') ." \n";
        }

        $ok = $this->deliver ([ 'created' => time (), 'subject' => (string) $subject, 'text' => (string) $message, 'cc' => $addresses ]);

        if ($ok) $this->flush ();

        return $ok;
    }

    /**
     * Reenvia a fila. Chamado depois de um envio bem-sucedido e ao final das
     * execuções do daemon (que muitas vezes não enviam nada).
     */
    public function flush ()
    {
        if ($this->down || $this->flushed) return;

        $this->flushed = TRUE;

        try
        {
            $files = $this->prune ();

            $sent = 0;

            foreach ($files as $file)
            {
                // outra execução (deploy e backup rodam em paralelo) pode estar reenviando
                $claim = $file .'.'. getmypid ();

                try { rename ($file, $claim); } catch (\Throwable $e) { continue; }

                $mail = json_decode ((string) file_get_contents ($claim), TRUE);

                if (!is_array ($mail) || !isset ($mail ['created'], $mail ['subject'], $mail ['text'], $mail ['cc']))
                {
                    unlink ($claim);

                    continue;
                }

                $mail ['text'] = "NOTE > This message was generated at ". date ('Y-m-d H:i:s T', $mail ['created']) ." and could only be sent now, because the SMTP server was unavailable. \n\n". $mail ['text'];

                if (!$this->deliver ($mail))
                {
                    rename ($claim, $file);

                    break;
                }

                unlink ($claim);

                $sent++;
            }

            if ($sent) echo "INFO > ". $sent ." queued e-mail(s) sent! \n";
        }
        catch (\Throwable $e)
        {
            echo "WARNING > Impossible to send queued e-mails: ". $e->getMessage () ." \n";
        }
    }

    static public function isValid ($addr)
    {
        return filter_var ($addr, FILTER_VALIDATE_EMAIL);
    }

    private function deliver ($mail)
    {
        if ($this->down) return FALSE;

        $to = $this->log;

        $cc = array_values (array_udiff (self::addresses ($mail ['cc']), $to, function ($a, $b) {
            return strcasecmp ($a, $b);
        }));

        if (!sizeof ($to)) $to = array_splice ($cc, 0, 1);

        if (!sizeof ($to))
        {
            echo "WARNING > E-mail '". $mail ['subject'] ."' not sent: no valid recipients (check 'LOG_MAIL' at '.env' file)! \n";

            return TRUE; // não há o que reenviar
        }

        if (!$this->from)
        {
            echo "WARNING > E-mail '". $mail ['subject'] ."' not sent: no valid sender (check 'SMTP_FROM' at '.env' file)! \n";

            return TRUE;
        }

        $email = (new Email ())
            ->from ($this->from)
            ->to (...$to)
            ->subject ($mail ['subject'])
            ->text ($mail ['text'])
            ->date (new \DateTimeImmutable ('@'. $mail ['created']));

        if (sizeof ($cc)) $email->cc (...$cc);

        // o último transporte que funcionou vai primeiro
        $keys = array_keys ($this->transports);

        $order = array_merge (array_slice ($keys, $this->current), array_slice ($keys, 0, $this->current));

        $errors = [];

        $first = null;

        $unresolved = [];

        foreach ($order as $key)
        {
            $transport = $this->transports [$key];

            // o SMTP_TIMEOUT não cobre a resolução de nomes (~5 s por tentativa)
            $host = substr ($key, 0, strrpos ($key, ':'));

            if (isset ($unresolved [$host])) continue;

            if ($this->verbose) echo "INFO > Trying SMTP '". $key ."'... \n";

            try
            {
                $transport->send ($email);

                $this->current = array_search ($key, $keys);

                if ($this->verbose || sizeof ($errors)) echo "INFO > E-mail '". $mail ['subject'] ."' sent through '". $key ."'. \n";

                return TRUE;
            }
            catch (\Throwable $e)
            {
                $first = $first ? $first : $e;

                $errors [] = $key .': '. trim (preg_replace ('/\s+/', ' ', $e->getMessage ()));

                if (preg_match ('/getaddrinfo|does not resolve|not known/i', $e->getMessage ())) $unresolved [$host] = TRUE;

                try { $transport->stop (); } catch (\Throwable $ignored) {}
            }
        }

        $this->down = TRUE;

        if (!sizeof ($errors)) $errors [] = "no SMTP host configured (check 'SMTP_HOST' at '.env' file)";

        echo "WARNING > Impossible to send e-mail '". $mail ['subject'] ."'! SMTP attempts: \n";

        foreach ($errors as $error)
            echo "  - ". $error ." \n";

        if (!$this->reported && $first)
        {
            $this->reported = TRUE;

            try { \Sentry\captureException ($first); } catch (\Throwable $ignored) {}
        }

        return FALSE;
    }

    private function enqueue ($mail)
    {
        try
        {
            $file = self::queue () . DIRECTORY_SEPARATOR . date ('Ymd-His', $mail ['created']) .'-'. bin2hex (random_bytes (4)) .'.json';

            file_put_contents ($file, json_encode ($mail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            echo "WARNING > E-mail '". $mail ['subject'] ."' queued to be sent in the next executions! \n";

            $this->prune ();
        }
        catch (\Throwable $e)
        {
            echo "WARNING > Impossible to queue e-mail '". $mail ['subject'] ."': ". $e->getMessage () ." \n";
        }
    }

    /**
     * Descarta da fila o que passou de QUEUE_DAYS dias e o excedente de
     * QUEUE_MAX (os mais antigos). Retorna a fila restante, do mais antigo
     * para o mais novo.
     */
    private function prune ()
    {
        $files = glob (self::queue () . DIRECTORY_SEPARATOR .'*.json');

        if (!is_array ($files)) return [];

        sort ($files);

        $keep = [];

        $dropped = 0;

        foreach ($files as $i => $file)
            try
            {
                if (time () - filemtime ($file) > self::QUEUE_DAYS * 86400 || sizeof ($files) - $i > self::QUEUE_MAX)
                {
                    unlink ($file);

                    $dropped++;
                }
                else
                    $keep [] = $file;
            }
            catch (\Throwable $e) {} // reivindicado por outra execução no meio do caminho

        if ($dropped) echo "WARNING > ". $dropped ." queued e-mail(s) discarded (older than ". self::QUEUE_DAYS ." days or more than ". self::QUEUE_MAX ." in queue)! \n";

        return $keep;
    }

    static private function queue ()
    {
        global $_data;

        $dir = ($_data ? $_data : DIRECTORY_SEPARATOR .'data') . DIRECTORY_SEPARATOR .'.mail';

        if (!is_dir ($dir)) mkdir ($dir, 0700, TRUE);

        return $dir;
    }

    /**
     * Nome usado no HELO/EHLO. O padrão do Symfony, "[127.0.0.1]", é recusado
     * pelo smtp-relay.gmail.com e por servidores com reject_*_helo_hostname.
     */
    static private function helo ()
    {
        if (self::env ('SMTP_HELO') !== '') return self::env ('SMTP_HELO');

        $from = rtrim (self::env ('SMTP_FROM'), '> ');

        $domain = strpos ($from, '@') !== FALSE ? substr (strrchr ($from, '@'), 1) : '';

        foreach ([ self::env ('SERVER'), gethostname (), $domain ] as $name)
        {
            $name = strtolower (trim ((string) $name, " .\t\n\r\0\x0B"));

            if (!filter_var ($name, FILTER_VALIDATE_IP) && preg_match ('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $name))
                return $name;
        }

        return '[127.0.0.1]';
    }

    static private function address ($addr)
    {
        try
        {
            return $addr === '' ? null : Address::create ($addr);
        }
        catch (\Throwable $e)
        {
            return null;
        }
    }

    /**
     * Endereços válidos, sem repetição (o `team` do builds.json é editado à
     * mão e um endereço inválido derrubava o e-mail inteiro).
     */
    static private function addresses ($list)
    {
        $valid = [];

        foreach ($list as $addr)
            if (is_string ($addr) && self::isValid (trim ($addr)) && self::address (trim ($addr)))
                $valid [strtolower (trim ($addr))] = isset ($valid [strtolower (trim ($addr))]) ? $valid [strtolower (trim ($addr))] : trim ($addr);

        return array_values ($valid);
    }

    static private function split ($value)
    {
        return array_values (array_filter (array_map ('trim', explode (',', $value)), 'strlen'));
    }

    // trim: o /data/.env editado no Windows traz '\r' no fim de cada valor
    static private function env ($name)
    {
        return trim ((string) getenv ($name));
    }
}
