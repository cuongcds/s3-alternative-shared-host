<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// See application/controllers/admin/Auth.php for why this require is needed.
require_once APPPATH . 'core/Admin_Controller.php';

/**
 * Object tree browser + preview (docs/plans_v2.md sections 7.3/7.4). Reads
 * only — writes/deletes still go through the S3 API itself.
 */
class Objects extends Admin_Controller
{
    // text/*, application/json etc. previewed inline up to this many bytes.
    const TEXT_PREVIEW_LIMIT = 65536;

    public function __construct()
    {
        parent::__construct();
        $this->requireLogin();
        $this->load->model('bucket_model');
        $this->load->model('object_model');
        $this->load->library('filesystem_driver');
    }

    public function index($bucketName)
    {
        $bucket = $this->bucket_model->findByName($bucketName);
        if (!$bucket) {
            show_404();
        }
        $this->render('admin/objects/tree', array('bucket' => $bucket));
    }

    /**
     * JSON endpoint the tree view's JS fetches per folder level opened
     * (lazy-load — see assets/admin_tree.js).
     */
    public function tree($bucketName)
    {
        $bucket = $this->bucket_model->findByName($bucketName);
        if (!$bucket) {
            $this->output->set_status_header(404);
            $this->output->set_content_type('application/json')->set_output(json_encode(array('error' => 'NoSuchBucket')));
            return;
        }

        $prefix = (string) $this->input->get('prefix');
        $result = $this->object_model->listFolder($bucket['id'], $prefix);
        $this->output->set_content_type('application/json')->set_output(json_encode($result));
    }

    public function preview($bucketName)
    {
        $bucket = $this->bucket_model->findByName($bucketName);
        if (!$bucket) {
            show_404();
        }

        $key = (string) $this->input->get('key');
        $obj = $this->object_model->getCurrent($bucket['id'], $key);
        if (!$obj) {
            show_404();
        }

        $contentType = (string) $obj['content_type'];
        $isImage = (strpos($contentType, 'image/') === 0);
        $isPdf = ($contentType === 'application/pdf');
        $isText = (strpos($contentType, 'text/') === 0 || $contentType === 'application/json');

        $textSnippet = NULL;
        $textTruncated = FALSE;
        if ($isText) {
            try {
                $real = $this->filesystem_driver->assertPathWithinRoot($obj['storage_path']);
                $fh = fopen($real, 'rb');
                $textSnippet = fread($fh, self::TEXT_PREVIEW_LIMIT);
                $textTruncated = !feof($fh);
                fclose($fh);
            } catch (RuntimeException $e) {
                $isText = FALSE;
            }
        }

        $this->render('admin/objects/preview', array(
            'bucket' => $bucket,
            'object' => $obj,
            'isImage' => $isImage,
            'isPdf' => $isPdf,
            'isText' => $isText,
            'textSnippet' => $textSnippet,
            'textTruncated' => $textTruncated,
        ));
    }

    /**
     * Redirects to a freshly-issued presigned GET URL instead of proxying
     * bytes through this (session-authenticated) route — keeps large-object
     * downloads off the admin panel's request path, see docs/plans_v2.md
     * section 7.4.
     */
    public function download($bucketName)
    {
        $bucket = $this->bucket_model->findByName($bucketName);
        if (!$bucket) {
            show_404();
        }

        $key = (string) $this->input->get('key');
        $obj = $this->object_model->getCurrent($bucket['id'], $key);
        if (!$obj) {
            show_404();
        }

        $this->load->library('sigv4_signer');
        $baseUrl = $this->config->item('s3_public_base_url', 's3');
        $path = '/' . $bucketName . '/' . $key;
        redirect($this->sigv4_signer->presignUrl($baseUrl, 'GET', $path));
    }
}
