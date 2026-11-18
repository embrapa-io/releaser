<?php

/**
 * Onde está o diretório de dados do Releaser (/data): sistema de arquivos e origem, lidos do /proc/self/mountinfo.
 *
 * Usado pelo `io check` para avisar quando o /data está num sistema de arquivos de rede (NFS, CIFS…). Ali, um diretório
 * apagado ou trocado enquanto outro processo o usa devolve "Stale file handle" em vez de "No such file or directory",
 * e uma oscilação do servidor de arquivos interrompe o deploy no meio (nov/2026). O Releaser funciona assim, mas o
 * recomendado é disco local.
 */
class DataVolume
{
    const NETWORK = '/^(nfs[0-9]*|cifs|smb[0-9]*|smbfs|ceph|9p|lustre|afs|gfs2?|ocfs2|glusterfs|fuse\.(sshfs|glusterfs|ceph|cephfs|s3fs|rclone|gcsfuse|juicefs))$/';

    /**
     * Montagem que contém `$path`: a de ponto de montagem mais longo entre as que são prefixo dele.
     *
     * @return array|NULL [mount, type, source, network]
     */
    static public function filesystem ($path = '/data', $mountinfo = NULL)
    {
        if ($mountinfo === NULL)
        {
            try
            {
                $mountinfo = is_readable ('/proc/self/mountinfo') ? (string) @file_get_contents ('/proc/self/mountinfo') : '';
            }
            catch (\Throwable $e)
            {
                $mountinfo = '';
            }
        }

        $path = rtrim ($path, '/') === '' ? '/' : rtrim ($path, '/');

        $found = NULL;

        foreach (explode ("\n", $mountinfo) as $line)
        {
            // <id> <pai> <maior:menor> <raiz> <ponto de montagem> <opções> [campos opcionais] - <tipo> <origem> <opções>
            $parts = explode (' - ', trim ($line), 2);

            if (sizeof ($parts) != 2) continue;

            $left = preg_split ('/\s+/', $parts [0]);
            $right = preg_split ('/\s+/', $parts [1]);

            if (sizeof ($left) < 5 || sizeof ($right) < 2) continue;

            // Espaço, tabulação, quebra de linha e barra invertida vêm em octal (\040…) no ponto de montagem.
            $mount = preg_replace_callback ('/\\\\([0-7]{3})/', function ($m) { return chr (octdec ($m [1])); }, $left [4]);

            if ($mount !== '/' && $path !== $mount && strpos ($path .'/', $mount .'/') !== 0) continue;

            if ($found !== NULL && strlen ($mount) < strlen ($found ['mount'])) continue;

            $found = [
                'mount' => $mount,
                'type' => $right [0],
                'source' => $right [1],
                'network' => preg_match (self::NETWORK, $right [0]) === 1
            ];
        }

        return $found;
    }
}
