<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * HTTP-triggered event-queue drain for deploy option 3 (shared hosting — no
 * Docker, no long-running processes, no Redis, no shell/SSH access assumed).
 * cPanel-style cron on shared hosts typically can only run `wget`/`curl`
 * against a URL on a schedule, not an arbitrary PHP CLI script or daemon —
 * so this exposes cli/worker_lib.php's event processing over plain HTTP,
 * polling the `events` table directly instead of reading a Redis queue
 * (Event_model skips the Redis push entirely when REDIS_HOST isn't set —
 * see config/s3.php's `redis_enabled`).
 *
 * Deliberately NOT an S3/OS3-signed endpoint (doesn't extend MY_Controller):
 * wget on a cron schedule can't compute a request signature. Protected by a
 * shared-secret query param instead — configure the actual cron job on your
 * host to hit:
 *
 *   wget -q -O /dev/null "https://your-domain/cronjobs/process?token=<CRON_SECRET>"
 *
 * every minute (or whatever interval your host's cron UI allows).
 */
class Cronjobs extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->config('s3', TRUE);
        $this->load->database();
    }

    public function process()
    {
        $expected = (string) $this->config->item('s3_cron_secret', 's3');
        $given = (string) $this->input->get('token');

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $this->output->set_status_header(403);
            $this->output->set_content_type('text/plain')->set_output('Forbidden');
            return;
        }

        require_once APPPATH . '../cli/worker_lib.php';

        $ctx = array(
            'db' => $this->db->conn_id,
            'fs' => new Filesystem_driver($this->config->item('s3_storage_root', 's3')),
            'scanner' => new Virus_scanner(),
            'imgProc' => new Image_processor(),
            'queue' => NULL, // no Redis in this deploy option
            'secret' => $this->config->item('s3_secret_access_key', 's3'),
        );

        $limit = max(1, (int) $this->config->item('s3_cron_batch_limit', 's3'));
        $rows = $this->db->select('id')
            ->where('status', 'pending')
            ->order_by('id', 'ASC')
            ->limit($limit)
            ->get('events')
            ->result_array();

        $processed = 0;
        foreach ($rows as $row) {
            handle_event_with_retry($ctx, $row['id']);
            $processed++;
        }

        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'processed' => $processed,
            'batchLimit' => $limit,
        )));
    }
}
