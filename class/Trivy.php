<?php

/**
 * Varredura de CVEs das imagens em execução no host, com o Trivy rodando como
 * container irmão (o docker.sock do host já está montado no Releaser). Porte
 * do automaton-trivy da plataforma, sem o SSH: aqui o nó é o próprio host.
 *
 * O banco de vulnerabilidades fica no volume TRIVY_CACHE (persistente entre
 * rodadas) e é atualizado uma vez por execução. Só HIGH e CRITICAL interessam.
 */
class Trivy
{
    const DOCKER = '/usr/bin/docker';

    const CACHE = 'releaser_trivy';

    const TIMEOUT = 600; // segundos por imagem (TRIVY_TIMEOUT=10m)

    static public function image ()
    {
        $image = trim ((string) getenv ('TRIVY_IMAGE'));

        return $image !== '' ? $image : 'aquasec/trivy:latest';
    }

    /** Baixa/atualiza o banco de CVEs e o banco Java. Lança exceção se falhar. */
    static public function updateDB ()
    {
        foreach ([ '--download-db-only', '--download-java-db-only' ] as $flag)
        {
            $cmd = self::DOCKER .' run --rm -v '. self::CACHE .':/root/.cache '. self::image () .' image '. $flag;

            echo "COMMAND > ". $cmd ." \n";

            exec ($cmd .' 2>&1', $output, $return);

            if ($return !== 0)
            {
                if (sizeof ($output)) echo implode ("\n", array_slice ($output, -5)) ."\n";

                throw new Exception ("Impossible to update the Trivy database (". $flag .")");
            }

            unset ($output);
        }
    }

    /**
     * Varre uma imagem. Devolve a lista [{id, package, installed, fixed, severity, title}]
     * (só HIGH/CRITICAL) ou lança exceção em timeout/saída inválida — nesse caso o
     * chamador preserva o resultado anterior em vez de relatar "limpo".
     */
    static public function scan ($image)
    {
        $cmd = 'timeout '. self::TIMEOUT .' '. self::DOCKER .' run --rm -e TRIVY_TIMEOUT=10m -v '. self::CACHE .':/root/.cache -v /var/run/docker.sock:/var/run/docker.sock '. self::image ()
            .' image --quiet --skip-db-update --skip-java-db-update --severity HIGH,CRITICAL --format json '. escapeshellarg ($image);

        echo "COMMAND > ". $cmd ." \n";

        exec ($cmd .' 2>/dev/null', $output, $return);

        if ($return === 124) throw new Exception ("scan timeout (". self::TIMEOUT ." s)");

        if ($return !== 0) throw new Exception ("Trivy exited with code ". $return);

        $json = json_decode (implode ("\n", $output));

        if (!is_object ($json) || !isset ($json->Results) || !is_array ($json->Results))
            throw new Exception ("invalid output from Trivy");

        $list = [];

        foreach ($json->Results as $result)
        {
            if (!isset ($result->Vulnerabilities) || !is_array ($result->Vulnerabilities)) continue;

            foreach ($result->Vulnerabilities as $v)
            {
                if (!isset ($v->VulnerabilityID)) continue;

                $severity = strtoupper ((string) ($v->Severity ?? ''));

                if (!in_array ($severity, [ 'HIGH', 'CRITICAL' ])) continue;

                $list [] = [
                    'id' => (string) $v->VulnerabilityID,
                    'package' => (string) ($v->PkgName ?? ''),
                    'installed' => (string) ($v->InstalledVersion ?? ''),
                    'fixed' => (string) ($v->FixedVersion ?? ''),
                    'severity' => $severity,
                    'title' => (string) ($v->Title ?? '')
                ];
            }
        }

        return $list;
    }
}
