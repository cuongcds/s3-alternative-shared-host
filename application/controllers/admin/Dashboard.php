<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// See application/controllers/admin/Auth.php for why this require is needed.
require_once APPPATH . 'core/Admin_Controller.php';

class Dashboard extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->requireLogin();
        $this->load->model('bucket_model');
        $this->load->model('event_model');
    }

    public function index()
    {
        $buckets = $this->bucket_model->withStats();
        $totalObjects = 0;
        $totalSize = 0;
        foreach ($buckets as $b) {
            $totalObjects += (int) $b['object_count'];
            $totalSize += (int) $b['total_size'];
        }

        $recentAudit = $this->db->order_by('id', 'DESC')->limit(10)->get('audit_logs')->result_array();

        $this->render('admin/dashboard', array(
            'bucketCount' => count($buckets),
            'totalObjects' => $totalObjects,
            'totalSize' => $totalSize,
            'eventCounts' => $this->event_model->countByStatus(),
            'recentAudit' => $recentAudit,
        ));
    }
}
