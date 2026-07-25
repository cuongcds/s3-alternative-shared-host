<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Event_model extends CI_Model
{
    protected $redisEnabled;

    public function __construct()
    {
        parent::__construct();
        $this->load->config('s3', TRUE);
        $this->redisEnabled = (bool) $this->config->item('redis_enabled', 's3');
        if ($this->redisEnabled) {
            $this->load->library('redis_queue');
        }
    }

    /**
     * Record the event in MySQL (source of truth / retry backstop for every
     * deploy option) and, if Redis is configured, push its id onto the queue
     * so a daemon/cron worker picks it up promptly. Deploy option 3 (shared
     * hosting) has no Redis at all — Cronjobs.php polls the `events` table
     * directly instead, so the DB insert alone is enough there.
     */
    public function push($bucketRow, $key, $eventType, array $payload = array())
    {
        $this->db->insert('events', array(
            'bucket_id' => $bucketRow['id'],
            'object_key' => $key,
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $eventId = $this->db->insert_id();

        if ($this->redisEnabled) {
            try {
                $this->redis_queue->push('events_queue', $eventId);
            } catch (Throwable $e) {
                log_message('error', 'Event_model: Redis push failed for event ' . $eventId . ': ' . $e->getMessage());
            }
        }

        return $eventId;
    }

    public function list($status = NULL, $limit = 100)
    {
        $builder = $this->db->order_by('id', 'DESC')->limit($limit);
        if ($status) {
            $builder->where('status', $status);
        }
        return $builder->get('events')->result_array();
    }

    /**
     * Counts per status for the admin dashboard/events page
     * (docs/plans_v2.md sections 7.1/7.5).
     */
    public function countByStatus()
    {
        $counts = array('pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0);
        $rows = $this->db->select('status, COUNT(*) AS c')->group_by('status')->get('events')->result_array();
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Manual "redispatch" for a failed event (docs/plans_v2.md section 7.5):
     * resets it to pending/attempts=0 and, if Redis is configured, pushes it
     * back onto the queue so a worker/cron picks it up promptly instead of
     * waiting on whatever polling interval is in place.
     */
    public function requeue($id)
    {
        $event = $this->db->where('id', $id)->get('events')->row_array();
        if (!$event) {
            return FALSE;
        }

        $this->db->where('id', $id)->update('events', array(
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => NULL,
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        if ($this->redisEnabled) {
            try {
                $this->redis_queue->push('events_queue', $id);
            } catch (Throwable $e) {
                log_message('error', 'Event_model: Redis push failed for redispatch of event ' . $id . ': ' . $e->getMessage());
            }
        }

        return TRUE;
    }
}
