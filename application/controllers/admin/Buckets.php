<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// See application/controllers/admin/Auth.php for why this require is needed.
require_once APPPATH . 'core/Admin_Controller.php';

/**
 * Bucket management (docs/plans_v2.md section 7.2) — thin wrapper over the
 * same Bucket_model/Object_model/Filesystem_driver the S3 API (S3.php) uses,
 * no storage logic duplicated here.
 */
class Buckets extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->requireLogin();
        $this->load->model('bucket_model');
        $this->load->model('object_model');
        $this->load->library('filesystem_driver');
    }

    public function index()
    {
        $buckets = $this->bucket_model->withStats();
        $this->render('admin/buckets/list', array('buckets' => $buckets));
    }

    public function create()
    {
        if ($this->request_method() === 'POST') {
            $this->verifyCsrf();

            $name = trim((string) $this->input->post('name'));
            if (!$this->filesystem_driver->validateBucketName($name)) {
                $this->session->set_flashdata('flash_error', 'Invalid bucket name (lowercase letters, digits, "-", ".", 3-63 chars).');
                redirect('admin/buckets/new');
            }
            if ($this->bucket_model->exists($name)) {
                $this->session->set_flashdata('flash_error', 'A bucket named "' . $name . '" already exists.');
                redirect('admin/buckets/new');
            }

            $id = $this->bucket_model->create($name);
            if ($id === FALSE) {
                $this->session->set_flashdata('flash_error', 'Failed to create bucket.');
                redirect('admin/buckets/new');
            }
            $this->filesystem_driver->ensureBucketDir($name);

            $this->session->set_flashdata('flash_success', 'Bucket "' . $name . '" created.');
            redirect('admin/buckets');
        }

        $this->render('admin/buckets/form', array('mode' => 'create', 'bucket' => NULL));
    }

    public function edit($name)
    {
        $bucket = $this->bucket_model->findByName($name);
        if (!$bucket) {
            show_404();
        }

        if ($this->request_method() === 'POST') {
            $this->verifyCsrf();

            $corsRaw = trim((string) $this->input->post('cors_config'));
            $mimesRaw = trim((string) $this->input->post('allowed_mime_types'));
            $cors = NULL;
            $mimes = NULL;

            if ($corsRaw !== '') {
                $cors = json_decode($corsRaw, TRUE);
                if ($cors === NULL) {
                    $this->session->set_flashdata('flash_error', 'CORS config must be valid JSON.');
                    redirect('admin/buckets/' . $name . '/edit');
                }
            }
            if ($mimesRaw !== '') {
                $mimes = json_decode($mimesRaw, TRUE);
                if ($mimes === NULL || !is_array($mimes)) {
                    $this->session->set_flashdata('flash_error', 'Allowed MIME types must be a valid JSON array.');
                    redirect('admin/buckets/' . $name . '/edit');
                }
            }

            $maxSizeInput = trim((string) $this->input->post('max_object_size'));
            $maxSize = $maxSizeInput !== '' ? (int) $maxSizeInput : (int) $bucket['max_object_size'];

            $this->bucket_model->updatePolicy($bucket['id'], array(
                'versioning_enabled' => $this->input->post('versioning_enabled') ? 1 : 0,
                'is_public' => $this->input->post('is_public') ? 1 : 0,
                'cors_config' => $cors,
                'allowed_mime_types' => $mimes,
                'notification_url' => (trim((string) $this->input->post('notification_url')) ?: NULL),
                'max_object_size' => $maxSize,
            ));

            $this->session->set_flashdata('flash_success', 'Bucket policy updated.');
            redirect('admin/buckets/' . $name . '/edit');
        }

        $this->render('admin/buckets/form', array('mode' => 'edit', 'bucket' => $bucket));
    }

    public function delete($name)
    {
        $this->verifyCsrf();

        $bucket = $this->bucket_model->findByName($name);
        if (!$bucket) {
            show_404();
        }

        $existing = $this->object_model->listByPrefix($bucket['id'], '', 1);
        if (!empty($existing)) {
            $this->session->set_flashdata('flash_error', 'Bucket "' . $name . '" is not empty.');
            redirect('admin/buckets');
        }

        $this->filesystem_driver->deleteBucketDir($bucket['name']);
        $this->bucket_model->delete($bucket['id']);

        $this->session->set_flashdata('flash_success', 'Bucket "' . $name . '" deleted.');
        redirect('admin/buckets');
    }
}
