<?php

/**
 * Corpo dos e-mails do daemon a partir do output buffering.
 *
 * Histórico: run.php e controller/deploy.php usavam ob_get_flush()/ob_get_clean()
 * diretamente. Quando o envio do e-mail de SUCESSO falhava (SMTP), o buffer já
 * tinha sido consumido e o envio do e-mail de ERRO CRÍTICO recebia FALSE como
 * corpo — TypeError "The body must be a string" (issue RELEASER-A, ~28 mil
 * eventos desde abr/2025). Além disso, cada ob_get_flush() encerra um nível de
 * buffer, o que derrubava também o buffer do hook do Sentry.
 *
 * mailBody() nunca encerra o nível 1 (hook do Sentry), devolve sempre string e
 * lembra o último corpo obtido para o e-mail de erro crítico.
 */
function mailBody ()
{
    static $last = '';

    if (ob_get_level () > 1)
    {
        $content = ob_get_flush ();

        if (is_string ($content)) { $last = $content; return $content; }
    }

    return $last;
}
