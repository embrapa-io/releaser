<?php

/**
 * Menu e `--help` do `io`. Descrições curtas no menu (até ~50 caracteres),
 * agrupadas e quebradas pela largura real do terminal, com recuo; o detalhe
 * vai para o `--help` de cada comando. Cabeçalho com o nome,
 * a versão e o servidor. Cores ANSI só quando a saída é um terminal (e sem
 * NO_COLOR): em modo daemon a saída vira e-mail e não leva cabeçalho, cor nem
 * código de escape.
 */
class Menu
{
    const GROUPS = [
        'deploy' => 'Deploy',
        'maintenance' => 'Maintenance',
        'platform' => 'Platform',
        'setup' => 'Setup'
    ];

    const MIN_WIDTH = 40;
    const MAX_WIDTH = 120;

    private static $plain = NULL;

    /** Saída sem cor nem logo: daemon (vira e-mail), saída redirecionada ou NO_COLOR. */
    public static function plain ($daemon = FALSE)
    {
        if ($daemon) return TRUE;

        if (self::$plain !== NULL) return self::$plain;

        $tty = function_exists ('posix_isatty') ? @posix_isatty (STDOUT) : (function_exists ('stream_isatty') && @stream_isatty (STDOUT));

        $noColor = getenv ('NO_COLOR') !== FALSE && getenv ('NO_COLOR') !== '';

        return self::$plain = !$tty || $noColor || getenv ('TERM') === 'dumb';
    }

    /** Largura do terminal: COLUMNS (exportado pelo `bin/io`) > `tput cols` > `stty size` > 80. */
    public static function width ()
    {
        $columns = (int) getenv ('COLUMNS');

        if ($columns <= 0)
            $columns = (int) trim ((string) @shell_exec ('tput cols 2>/dev/null'));

        if ($columns <= 0 && preg_match ('/^\d+\s+(\d+)$/', trim ((string) @shell_exec ('stty size 2>/dev/null')), $m))
            $columns = (int) $m [1];

        if ($columns <= 0) $columns = 80;

        return max (self::MIN_WIDTH, min (self::MAX_WIDTH, $columns));
    }

    /** Aplica um estilo ANSI discreto (bold, dim, green, yellow, red, cyan), só em terminal. */
    public static function paint ($text, $style, $plain)
    {
        if ($plain) return $text;

        $codes = [ 'bold' => '1', 'dim' => '2', 'red' => '31', 'green' => '32', 'yellow' => '33', 'cyan' => '36' ];

        return "\033[". $codes [$style] .'m'. $text ."\033[0m";
    }

    /**
     * Quebra $text em linhas de até $width colunas; a primeira começa na coluna
     * $indent (o chamador já imprimiu o que vem antes) e as seguintes, recuadas
     * até ela. Palavra maior que a linha (URL, comando) não é cortada.
     */
    public static function wrap ($text, $width, $indent)
    {
        $room = max (20, $width - $indent);

        return wordwrap ($text, $room, "\n". str_repeat (' ', $indent), FALSE);
    }

    public static function header ($plain)
    {
        $version = trim ((string) getenv ('IO_RELEASER_VERSION'));

        $lines = [
            self::paint ('Embrapa I/O Releaser', 'bold', $plain),
            'Version '. ($version !== '' ? $version : 'dev') .' · '. getenv ('ORCHESTRATOR'),
            'Server '. getenv ('SERVER'),
            self::paint ('https://embrapa.io/docs/releaser/', 'dim', $plain)
        ];

        $out = "\n";

        foreach ($lines as $line)
            $out .= $line ."\n";

        return $out;
    }

    /** O menu completo (sem comando ou com um inexistente). */
    public static function render (array $operations, $container, $daemon = FALSE)
    {
        $plain = self::plain ($daemon);

        $width = self::width ();

        // sem cabeçalho no daemon e quando o `bin/io` já o mostrou (configuração inicial)
        $out = $daemon || getenv ('IO_BANNER') === 'shown' ? '' : self::header ($plain) ."\n";

        $out .= self::paint ('Usage:', 'bold', $plain) .' docker exec -it '. $container ." io COMMAND [OPTIONS]\n";

        $name = 0;

        foreach ($operations as $op => $obj) $name = max ($name, strlen ($op));

        $column = 2 + $name + 2;

        $groups = [];

        foreach ($operations as $op => $obj)
            $groups [$obj->group !== '' ? $obj->group : 'setup'][$op] = $obj;

        foreach (self::GROUPS as $key => $label)
        {
            if (!isset ($groups [$key])) continue;

            $out .= "\n". self::paint ($label, 'bold', $plain) ."\n";

            foreach ($groups [$key] as $op => $obj)
                $out .= '  '. self::paint (str_pad ($op, $name + 2), 'cyan', $plain) . self::wrap ($obj->info, $width, $column) ."\n";
        }

        $out .= "\n". self::wrap ("Run 'docker exec -it ". $container ." io COMMAND --help' for the details, options and examples of a command.", $width, 0) ."\n";

        return $out;
    }

    /** `io COMMAND --help` (ou chamada sem os parâmetros obrigatórios). */
    public static function help ($op, $obj, $container, $daemon = FALSE)
    {
        $plain = self::plain ($daemon);

        $width = self::width ();

        $out = self::paint ('Usage:', 'bold', $plain) .' '. self::wrap ('docker exec -it '. $container .' io '. $op .' '. $obj->usage, $width, 7) ."\n\n";

        $out .= self::wrap ($obj->detail !== '' ? $obj->detail : $obj->info, $width, 0) ."\n";

        if (sizeof ($obj->examples))
        {
            $out .= "\n". self::paint ('Examples:', 'bold', $plain) ."\n";

            foreach ($obj->examples as $ex)
                $out .= '  docker exec -it '. $container .' io '. $op . ($ex !== '' ? ' '. $ex : '') ."\n";
        }

        return $out;
    }
}
