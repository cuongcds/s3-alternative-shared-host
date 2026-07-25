<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Thin wrapper around Predis for the events queue (usecase 3). Usable both
 * as a CI library (app) and standalone (cli/worker.php).
 */
class Redis_queue
{
    /** @var Predis\Client */
    protected $client;

    public function __construct($host = NULL, $port = NULL, $password = NULL)
    {
        if ($host === NULL && function_exists('get_instance')) {
            $ci = &get_instance();
            $ci->config->load('s3', TRUE);
            $host = $ci->config->item('redis_host', 's3');
            $port = $ci->config->item('redis_port', 's3');
            $password = $ci->config->item('redis_password', 's3');
        }
        $host = $host ?: (getenv('REDIS_HOST') ?: 'localhost');
        $port = $port ?: (int) (getenv('REDIS_PORT') ?: 6379);
        $password = $password !== NULL ? $password : (getenv('REDIS_PASSWORD') ?: NULL);

        $params = array('host' => $host, 'port' => $port);
        if ($password) {
            $params['password'] = $password;
        }
        $this->client = new Predis\Client($params);
    }

    public function push($queue, $value)
    {
        $this->client->rpush($queue, array((string) $value));
    }

    /**
     * Blocking pop with timeout (seconds). Returns the popped value, or NULL
     * on timeout.
     */
    public function blockingPop($queue, $timeoutSeconds = 5)
    {
        $result = $this->client->blpop(array($queue), $timeoutSeconds);
        return $result ? $result[1] : NULL;
    }
}
