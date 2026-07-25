<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ClamAV clamd client using the INSTREAM protocol. Disabled unless
 * ENABLE_VIRUS_SCAN=true (see .env.example) and a clamd is reachable —
 * see the "with-antivirus" docker compose profile.
 */
class Virus_scanner
{
    protected $host;
    protected $port;
    protected $enabled;

    /**
     * @param string|null $host Pass explicitly for standalone (non-CI) use,
     *  e.g. from cli/worker.php. Left NULL, everything is read from CI config.
     */
    public function __construct($host = NULL, $port = NULL, $enabled = NULL)
    {
        if ($host === NULL && function_exists('get_instance')) {
            $ci = &get_instance();
            $ci->config->load('s3', TRUE);
            $host = $ci->config->item('clamav_host', 's3');
            $port = $ci->config->item('clamav_port', 's3');
            $enabled = $ci->config->item('enable_virus_scan', 's3');
        }
        $this->host = $host ?: (getenv('CLAMAV_HOST') ?: 'clamav');
        $this->port = $port ?: (int) (getenv('CLAMAV_PORT') ?: 3310);
        $this->enabled = $enabled !== NULL ? $enabled : filter_var(getenv('ENABLE_VIRUS_SCAN') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public function isEnabled()
    {
        return (bool) $this->enabled;
    }

    /**
     * @return array{clean: bool, signature: ?string, skipped: bool, error: ?string}
     */
    public function scanFile($path)
    {
        if (!$this->enabled) {
            return array('clean' => TRUE, 'signature' => NULL, 'skipped' => TRUE, 'error' => NULL);
        }

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$socket) {
            return array('clean' => TRUE, 'signature' => NULL, 'skipped' => TRUE, 'error' => "clamd unreachable: {$errstr}");
        }

        stream_set_timeout($socket, 30);
        fwrite($socket, "zINSTREAM\0");

        $fh = fopen($path, 'rb');
        if (!$fh) {
            fclose($socket);
            return array('clean' => TRUE, 'signature' => NULL, 'skipped' => TRUE, 'error' => 'could not open file for scanning');
        }

        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === FALSE || $chunk === '') {
                break;
            }
            fwrite($socket, pack('N', strlen($chunk)) . $chunk);
        }
        fclose($fh);
        fwrite($socket, pack('N', 0));

        $response = '';
        while (!feof($socket)) {
            $response .= fgets($socket, 4096);
        }
        fclose($socket);

        $response = trim($response);
        if (preg_match('/FOUND$/', $response)) {
            preg_match('/stream:\s*(.+)\s+FOUND$/', $response, $m);
            return array('clean' => FALSE, 'signature' => isset($m[1]) ? $m[1] : 'unknown', 'skipped' => FALSE, 'error' => NULL);
        }
        if (preg_match('/OK$/', $response)) {
            return array('clean' => TRUE, 'signature' => NULL, 'skipped' => FALSE, 'error' => NULL);
        }

        return array('clean' => TRUE, 'signature' => NULL, 'skipped' => TRUE, 'error' => "unexpected clamd response: {$response}");
    }
}
