<?php

/**
 * Integração do Releaser com o Sentry (sentry.io, org 'embrapa-io', projeto 'releaser').
 *
 * O DSN é distribuído com a imagem: todo Releaser instalado reporta para o mesmo
 * projeto, diferenciado por `environment` (= SERVER do /data/.env) e `release`
 * (= versão da imagem). Pode ser sobrescrito por SENTRY_DSN no /data/.env, ou
 * desligado com SENTRY_DSN=off.
 *
 * O que é enviado:
 *  - Exceções não tratadas (captureException em run.php);
 *  - Toda linha de saída com prefixo convencional (INFO/COMMAND/WARNING/ERROR/
 *    SUCCESS/CRITICAL/FINISH >) vira um log do Sentry (produto Logs), com os
 *    atributos operation/mode/build/project/app/stage;
 *  - Toda linha "ERROR >" vira também um evento (issue) com as tags da build
 *    corrente — hoje esses erros só geram `continue` no controller e, no daemon,
 *    não chegam nem ao e-mail.
 *
 * O contexto de build é lido da própria saída: os controllers imprimem
 * "=== projeto/app@stage ===" ao iniciar cada build.
 */

const SENTRY_DEFAULT_DSN = 'https://dca31eca2c6644c687f46ecb99602841@o1289077.ingest.sentry.io/4505550695759872';

function sentryInit ($operation, $daemon)
{
    $dsn = trim ((string) getenv ('SENTRY_DSN'));

    if ($dsn === '') $dsn = SENTRY_DEFAULT_DSN;

    if (in_array (strtolower ($dsn), [ 'off', 'false', 'no', '0' ])) return FALSE;

    \Sentry\init ([
        'dsn' => $dsn,
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

            // "ERROR >" é o erro por build que hoje só gera `continue`; vira issue.
            // "CRITICAL >" já é coberto pelo captureException em run.php.
            if ($level === 'ERROR')
            {
                \Sentry\withScope (function (\Sentry\State\Scope $scope) use ($message, $attributes) {
                    foreach ([ 'build', 'project', 'app', 'stage' ] as $tag)
                        if (array_key_exists ($tag, $attributes)) $scope->setTag ($tag, $attributes [$tag]);

                    $scope->setFingerprint ([ '{{ default }}', $attributes ['operation'], $attributes ['build'] ?? '-' ]);

                    \Sentry\captureMessage ($message, \Sentry\Severity::error ());
                });
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
