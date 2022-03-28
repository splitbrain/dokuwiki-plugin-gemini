<?php

use splitbrain\phpcli\Options;

/**
 * DokuWiki Plugin gemini (CLI Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class cli_plugin_gemini extends \dokuwiki\Extension\CLIPlugin
{

    /** @inheritDoc */
    protected function setup(Options $options)
    {
        $options->setHelp('FIXME: What does this CLI do?');

        // main arguments
        //$options->registerArgument('FIXME:argumentName', 'FIXME:argument description', 'FIXME:required? true|false');

        // options
        // $options->registerOption('FIXME:longOptionName', 'FIXME: helptext for option', 'FIXME: optional shortkey', 'FIXME:needs argument? true|false', 'FIXME:if applies only to subcommand: subcommandName');

        // sub-commands and their arguments
        // $options->registerCommand('FIXME:subcommandName', 'FIXME:subcommand description');
        // $options->registerArgument('FIXME:subcommandArgumentName', 'FIXME:subcommand-argument description', 'FIXME:required? true|false', 'FIXME:subcommandName');
    }

    /** @inheritDoc */
    protected function main(Options $options)
    {
        // $command = $options->getCmd()
        // $arguments = $options->getArgs()

        $pemfile = $this->getSelfSignedCertificate('localhost');
        $this->info('Using certificate in {pemfile}', compact('pemfile'));

        $this->serve('0.0.0.0', 1965, $pemfile);

    }

    /**
     * The actual socket server implementation
     *
     * @param string $interface IP to listen on
     * @param int $port Port to use
     * @param string $certfile Certificate PEM file to use
     * @return mixed
     */
    protected function serve($interface, $port, $certfile)
    {
        $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'local_cert' => $certfile,
                ],
            ]
        );

        if (function_exists('pcntl_fork')) {
            $this->info('Multithreading enabled.');
        } else {
            $this->info('Multithreading disabled (PCNTL extension not present)');
        }

        $errno = 0;
        $errstr = '';
        $socket = stream_socket_server(
            'tcp://' . $interface . ':' . $port,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($socket === false) throw new \splitbrain\phpcli\Exception($errstr, $errno);
        $this->success('Listening on {interface}:{port}', compact('interface', 'port'));

        // basic environment
        global $_SERVER;
        $_SERVER['SERVER_ADDR'] = $interface;
        $_SERVER['SERVER_PORT'] = $port;
        $_SERVER['SERVER_PROTOCOL'] = 'gemini';
        $_SERVER['REQUEST_SCHEME'] = 'gemini';
        $_SERVER['HTTPS'] = 'on';

        while (true) {
            $peername = '';
            $conn = stream_socket_accept($socket, -1, $peername);
            if ($conn === false) throw new \splitbrain\phpcli\Exception('socket failed');

            if (!function_exists('pcntl_fork') || ($pid = pcntl_fork()) == -1) {
                $pid = -1;
            }

            // fork father, wait next socket
            if ($pid > 0) {
                // kill previous zombie
                /** @noinspection PhpStatementHasEmptyBodyInspection */
                while (pcntl_wait($status, WNOHANG) > 0) {
                }
                continue;
            }

            $this->handleGeminiConnection($pid, $conn, $peername);
        }
    }

    /**
     * Handles a single Gemini Request
     *
     * @param int $pid process ID, forked children are 0 and will exit after handling
     * @param resource $conn The connected socket
     * @param string $peername The connected peer
     * @return void
     */
    protected function handleGeminiConnection($pid, $conn, $peername)
    {
        $tlsSuccess = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($tlsSuccess !== true) {
            fclose($conn);
            $this->error('TLS failed for connection from {peername}', compact('peername'));

            // forked child or single thread?
            if ($pid === 0) exit;
            return;
        }

        $req = stream_get_line($conn, 1024, "\n");
        $this->info(date('Y-m-d H:i:s') . "\t" . $peername . "\t" . trim($req));

        $url_elems = parse_url(trim($req));
        if (empty($url_elems['path'])) {
            $url_elems['path'] = '/';
        }
        $url_elems['path'] = str_replace("\\", '/', rawurldecode($url_elems['path']));

        $response = false;
        $body = false;
        // check scheme
        if ($response === false && $url_elems['scheme'] != 'gemini') {
            $response = "59 BAD PROTOCOL\r\n";
        }

        // check path
        if ($response === false && strpos($url_elems['path'], '/..') !== false) {
            $response = "59 BAD URL\r\n";
        }

        // environment
        global $_SERVER;
        $_SERVER['HTTP_HOST'] = $url_elems['host'];
        $_SERVER['SERVER_NAME'] = $url_elems['host'];
        $_SERVER['REMOTE_ADDR'] = explode(':', $peername)[0];
        $_SERVER['REMOTE_PORT'] = explode(':', $peername)[1];
        $_SERVER['REQUEST_URI'] = $url_elems['path'];

        $answer = $this->generateResponse($url_elems['path']);
        if ($answer) {
            $response = "20 " . $answer['mime'] . "\r\n";
            $body = $answer['body'];
        }

        if ($response === false) {
            $response = "51 NOT FOUND\r\n";
        }

        fputs($conn, $response);
        if ($body !== false) {
            fputs($conn, $body);
        }
        fflush($conn);
        fclose($conn);

        // forked child
        if ($pid == 0) exit;
    }

    /**
     * Generates the response to send
     */
    protected function generateResponse($path)
    {
        global $ID;
        global $INFO;

        // FIXME we probably need to provide more standard environment here
        $ID = str_replace('/', ':', $path);
        $INFO = pageinfo();
        $file = wikiFN($ID);

        return [
            'mime' => 'text/gemini; lang=en',
            'body' => p_cached_output($file, 'gemini', $ID),
        ];
    }

    /**
     * Create and cache a certificate for the given domain
     *
     * @param string $domain
     * @return string
     */
    protected function getSelfSignedCertificate($domain)
    {

        $pemfile = getCacheName($domain, '.pem');
        if (time() - filemtime($pemfile) > 3620 * 60 * 60 * 24) {
            $this->info('Generating new certificate for {domain}', compact('domain'));
            $pem = $this->createCert($domain);
            file_put_contents($pemfile, $pem);
        }

        return $pemfile;
    }

    /**
     * Create a simple, self-signed SSL certificate
     *
     * @param string $cn Common name
     * @return string PEM
     */
    protected function createCert($cn)
    {
        $days = 3650;
        $out = [
            'public' => '',
            'private' => '',
        ];

        $config = [
            'digest_alg' => 'AES-128-CBC',
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'encrypt_key' => false,
        ];

        $dn = [
            'commonName' => $cn,
            'organizationName' => 'DokuWiki',
            'emailAddress' => 'admin@example.com',
        ];

        $privkey = openssl_pkey_new($config);
        $csr = openssl_csr_new($dn, $privkey, $config);
        $cert = openssl_csr_sign($csr, null, $privkey, $days, $config, 0);
        openssl_x509_export($cert, $out['public']);
        openssl_pkey_export($privkey, $out['private']);
        openssl_pkey_free($privkey);

        return $out['public'] . $out['private'];
    }

}

