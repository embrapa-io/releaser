<?php

/**
 * Integração do Releaser com o Sentry (sentry.io, org 'embrapa-io', projeto 'releaser').
 *
 * O DSN é HARDCODED de propósito: todo Releaser instalado reporta para o mesmo
 * projeto, diferenciado por `environment` (= SERVER do /data/.env) e `release`
 * (= versão da imagem). Não é configurável pelo /data/.env — esse arquivo é
 * editado por equipes distribuídas que só querem fazer deploy das suas apps,
 * e não tratar bugs do Releaser.
 *
 * O que é enviado:
 *  - Exceções não tratadas (captureException em run.php) — issues do RELEASER;
 *  - Toda linha de saída com prefixo convencional (INFO/COMMAND/WARNING/ERROR/
 *    SUCCESS/CRITICAL/FINISH >) vira um log do Sentry (produto Logs), com os
 *    atributos operation/mode/build/project/app/stage.
 *
 * O que NÃO é enviado como issue: erros de deploy/backup de uma build
 * ("ERROR >"). Cada app tem o próprio DSN e esses erros vão por e-mail à equipe.
 *
 * O contexto de build é lido da própria saída: os controllers imprimem
 * "=== projeto/app@stage ===" ao iniciar cada build.
 */

const SENTRY_DSN = 'https://dca31eca2c6644c687f46ecb99602841@o1289077.ingest.sentry.io/4505550695759872';

function sentryInit ($operation, $daemon)
{
    \Sentry\init ([
        'dsn' => SENTRY_DSN,
        'release' => 'releaser@'. (getenv ('IO_RELEASER_VERSION') ?: 'dev'),
        'environment' => getenv ('SERVER') ?: 'unknown',
        'enable_logs' => TRUE,
        'attach_stacktrace' => TRUE,
        'send_default_pii' => FALSE,
        'error_types' => E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED
    ]);

    \Sentry\configureScope (function (\Sentry\State\Scope $scope) use ($operation, $daemon) {
        $scope->setTag ('operation', (string) $operation);
        $scope->setTag ('mode', $daemon ? 'daemon' : 'cli');
        $scope->setTag ('orchestrator', getenv ('ORCHESTRATOR') ?: 'unknown');
        $scope->setTag ('server', getenv ('SERVER') ?: 'unknown');
    });

    $GLOBALS ['_sentryEnabled'] = TRUE;

    // garante o envio dos logs em qualquer saída (exit, exceção, fim do script)
    register_shutdown_function ('sentryFlush');

    return TRUE;
}

/**
 * Callback de output buffering (ob_start). Repassa a saída intacta e, em
 * paralelo, converte as linhas com prefixo em logs/eventos do Sentry.
 */
function sentryOutput ($chunk, $phase)
{
    static $partial = '';
    static $build = NULL;

    if (empty ($GLOBALS ['_sentryEnabled'])) return $chunk;

    $text = $partial . $chunk;
    $lines = explode ("\n", $text);
    $partial = array_pop ($lines);

    if ($phase & PHP_OUTPUT_HANDLER_FINAL) { $lines [] = $partial; $partial = ''; }

    foreach ($lines as $line)
    {
        $line = trim ($line);

        if ($line === '') continue;

        if (preg_match ('/^=== (\S+) ===$/', $line, $m)) { $build = $m [1]; continue; }

        if (!preg_match ('/^(INFO|COMMAND|WARNING|ERROR|SUCCESS|CRITICAL|FINISH) > (.*)$/', $line, $m)) continue;

        $level = $m [1];
        $message = trim ($m [2]);

        $attributes = [ 'operation' => (string) ($GLOBALS ['_operation'] ?? ''), 'mode' => !empty ($GLOBALS ['_daemon']) ? 'daemon' : 'cli', 'prefix' => $level ];

        if ($build !== NULL && preg_match ('#^([^/]+)/([^@]+)@(\w+)$#', $build, $b))
            $attributes += [ 'build' => $build, 'project' => $b [1], 'app' => $b [2], 'stage' => $b [3] ];

        try
        {
            $logger = \Sentry\logger ();

            switch ($level)
            {
                case 'WARNING':  $logger->warn ('%s', [ $message ], $attributes); break;
                case 'ERROR':    $logger->error ('%s', [ $message ], $attributes); break;
                case 'CRITICAL': $logger->fatal ('%s', [ $message ], $attributes); break;
                default:         $logger->info ('%s', [ $message ], $attributes);
            }
        }
        catch (\Throwable $e) {} // monitoramento nunca pode derrubar a operação
    }

    return $chunk;
}

function sentryFlush ()
{
    if (empty ($GLOBALS ['_sentryEnabled'])) return;

    try { \Sentry\logger ()->flush (); } catch (\Throwable $e) {}
}
