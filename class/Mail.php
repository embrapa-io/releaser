<?php

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

class Mail
{
    static private $single = FALSE;

    private $mailer = null;

    private $log = null;

    private $from = null;

    private final function __construct ()
	{
        // https://symfony.com/doc/current/mailer.html
        // https://code.tutsplus.com/tutorials/send-emails-in-php-using-the-swift-mailer--cms-31218

        if (trim (getenv ('SMTP_USER')) != '' && trim (getenv ('SMTP_PASS')) != '')
            $dsn = 'smtp://'. getenv ('SMTP_USER') .':'. getenv ('SMTP_PASS') .'@'. getenv ('SMTP_HOST') .':'. getenv ('SMTP_PORT');
        else
            $dsn = 'smtp://'. getenv ('SMTP_HOST') .':'. getenv ('SMTP_PORT');

        if (!in_array (strtolower (trim (getenv ('SMTP_SECURE'))), [ 'yes', '1', 'true' ]))
            $dsn .= '?verify_peer=0';

        $transport = Transport::fromDsn ($dsn);

        $this->mailer = new Mailer ($transport);

        $this->from = getenv ('SMTP_FROM');

        $this->log = getenv ('LOG_MAIL');
    }

    static public function singleton ()
	{
		if (self::$single !== FALSE)
			return self::$single;

		$class = __CLASS__;

		self::$single = new $class ();

		return self::$single;
	}

    public function send ($subject, $message, $cc = [])
    {
        $email = (new Email ())
            ->from ($this->from)
            ->to ($this->log)
            ->subject ($subject)
            ->text (is_string ($message) ? $message : '');

        if (is_array ($cc) && sizeof ($cc)) $email->cc (...$cc);

        // Falha de SMTP não pode abortar a operação (nem a fila de builds do
        // daemon): registra no Sentry como erro do Releaser e segue.
        try
        {
            $this->mailer->send ($email);
        }
        catch (\Throwable $e)
        {
            echo "WARNING > Impossible to send e-mail '". $subject ."': ". $e->getMessage () ." \n";

            try { \Sentry\captureException ($e); } catch (\Throwable $ignored) {}
        }
    }

    static public function isValid ($addr)
    {
        return filter_var ($addr, FILTER_VALIDATE_EMAIL);
    }
}
