<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// See application/controllers/admin/Auth.php for why this require is needed.
require_once APPPATH . 'core/Admin_Controller.php';

/**
 * Event/dispatch status list + manual redispatch (docs/plans_v2.md
 * section 7.5).
 */
class Events extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->requireLogin();
        $this->load->model('event_model');
    }

    public function index()
    {
        $status = (string) $this->input->get('status');
        $events = $this->event_model->list($status !== '' ? $status : NULL, 100);

        $bucketNames = array();
        foreach ($events as &$event) {
            $bucketId = $event['bucket_id'];
            if (!array_key_exists($bucketId, $bucketNames)) {
                $row = $this->db->select('name')->where('id', $bucketId)->get('buckets')->row_array();
                $bucketNames[$bucketId] = $row ? $row['name'] : NULL;
            }
            $event['bucket_name'] = $bucketNames[$bucketId] ?: '(deleted bucket)';
        }
        unset($event);

        $this->render('admin/events/list', array(
            'events' => $events,
            'status' => $status,
            'counts' => $this->event_model->countByStatus(),
        ));
    }

    public function redispatch($id)
    {
        $this->verifyCsrf();

        $ok = $this->event_model->requeue((int) $id);
        $this->session->set_flashdata(
            $ok ? 'flash_success' : 'flash_error',
            $ok ? 'Event #' . (int) $id . ' requeued.' : 'Event #' . (int) $id . ' not found.'
        );
        redirect('admin/events');
    }
}
