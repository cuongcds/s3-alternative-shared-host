<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Convenience endpoints that are NOT part of the S3 REST surface: presigned-URL
 * issuance (usecase 1), bucket policy management, and event/queue introspection.
 * All require the same fixed Access Key/Secret Key as the S3 API (header auth),
 * EXCEPT presignWithToken() — that one accepts a short-term bucket token
 * instead (see its docblock).
 */
class Internal extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bucket_model');
        $this->load->model('access_token_model');
    }

    public function presign()
    {
        $raw = $this->raw_body();
        $this->require_auth($raw);

        $body = json_decode($raw, TRUE);
        if (!is_array($body)) {
            $this->send_error(400, 'InvalidRequest', 'Request body must be JSON');
        }

        $bucket = isset($body['bucket']) ? $body['bucket'] : NULL;
        $key = isset($body['key']) ? $body['key'] : NULL;
        $method = isset($body['method']) ? strtoupper($body['method']) : 'PUT';
        $expiresIn = isset($body['expiresIn']) ? (int) $body['expiresIn'] : NULL;

        if (!$bucket || !$key || !in_array($method, array('PUT', 'GET'), TRUE)) {
            $this->send_error(400, 'InvalidRequest', 'bucket, key and method (PUT|GET) are required');
        }

        $bucketRow = $this->bucket_model->findByName($bucket);
        if (!$bucketRow) {
            $this->send_error(404, 'NoSuchBucket', 'The specified bucket does not exist', $bucket);
        }

        $path = '/' . $bucket . '/' . $key;
        $baseUrl = $this->config->item('s3_public_base_url', 's3');
        $url = $this->sigv4_signer->presignUrl($baseUrl, $method, $path, $expiresIn);

        $ttl = $expiresIn ?: (int) $this->config->item('s3_presign_default_ttl', 's3');
        $ttl = max((int) $this->config->item('s3_presign_min_ttl', 's3'), min((int) $this->config->item('s3_presign_max_ttl', 's3'), $ttl));

        $this->audit('presign', $key, 200, $bucket);
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'url' => $url,
            'method' => $method,
            'expiresIn' => $ttl,
            'expiresAt' => date('c', time() + $ttl),
        )));
    }

    /**
     * Delegated presign issuance: authenticates with a short-term bucket
     * token (`X-Os3-Token` header, see Buckets::generateToken() in the admin
     * panel) instead of the fixed Access/Secret Key — lets a less-trusted
     * client request presigned URLs without ever holding the real
     * credentials. The bucket is taken from the token itself, never from the
     * request body, so a token can't be used to presign a different bucket.
     */
    public function presignWithToken()
    {
        $token = $this->get_header('X-Os3-Token');
        if (!$token) {
            $this->send_error(403, 'AccessDenied', 'Missing X-Os3-Token header');
        }

        $tokenRow = $this->access_token_model->findValidByToken($token);
        if (!$tokenRow) {
            $this->send_error(403, 'AccessDenied', 'Invalid, expired or revoked token');
        }
        if (!(int) $tokenRow['allow_short_term_tokens']) {
            $this->send_error(403, 'AccessDenied', 'Short-term tokens are disabled for this bucket');
        }

        $raw = $this->raw_body();
        $body = json_decode($raw, TRUE);
        if (!is_array($body)) {
            $this->send_error(400, 'InvalidRequest', 'Request body must be JSON');
        }

        $key = isset($body['key']) ? $body['key'] : NULL;
        $method = isset($body['method']) ? strtoupper($body['method']) : 'PUT';
        $expiresIn = isset($body['expiresIn']) ? (int) $body['expiresIn'] : NULL;

        if (!$key || !in_array($method, array('PUT', 'GET'), TRUE)) {
            $this->send_error(400, 'InvalidRequest', 'key and method (PUT|GET) are required');
        }

        $bucket = $tokenRow['bucket_name'];
        $path = '/' . $bucket . '/' . $key;
        $baseUrl = $this->config->item('s3_public_base_url', 's3');
        $url = $this->sigv4_signer->presignUrl($baseUrl, $method, $path, $expiresIn);

        $ttl = $expiresIn ?: (int) $this->config->item('s3_presign_default_ttl', 's3');
        $ttl = max((int) $this->config->item('s3_presign_min_ttl', 's3'), min((int) $this->config->item('s3_presign_max_ttl', 's3'), $ttl));

        $this->audit('presign_with_token', $key, 200, $bucket);
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'url' => $url,
            'bucket' => $bucket,
            'method' => $method,
            'expiresIn' => $ttl,
            'expiresAt' => date('c', time() + $ttl),
        )));
    }

    public function policy()
    {
        $raw = $this->raw_body();
        $this->require_auth($raw);

        $args = func_get_args();
        $bucket = implode('/', $args);
        $bucketRow = $this->bucket_model->findByName($bucket);
        if (!$bucketRow) {
            $this->send_error(404, 'NoSuchBucket', 'The specified bucket does not exist', $bucket);
        }

        if ($this->request_method() !== 'PUT') {
            $this->send_error(405, 'MethodNotAllowed', 'Only PUT is supported for bucket policy');
        }

        $body = json_decode($raw, TRUE);
        if (!is_array($body)) {
            $this->send_error(400, 'InvalidRequest', 'Request body must be JSON');
        }

        $this->bucket_model->updatePolicy($bucketRow['id'], $body);
        $this->audit('update_policy', NULL, 200, $bucket);
        $this->output->set_content_type('application/json')->set_output(json_encode(array('ok' => TRUE)));
    }

    public function events()
    {
        $this->require_auth();
        $this->load->model('event_model');

        $status = $this->input->get('status');
        $rows = $this->event_model->list($status ?: NULL, 100);
        $this->output->set_content_type('application/json')->set_output(json_encode($rows));
    }

    /**
     * Backend upload pipeline (usecase 2): validate -> virus scan -> store
     * original -> best-effort thumbnail for images -> emit object.created.
     * multipart/form-data field name must be "file".
     */
    public function upload()
    {
        $this->require_auth();

        if ($this->request_method() !== 'POST') {
            $this->send_error(405, 'MethodNotAllowed', 'Only POST is supported for backend upload');
        }

        $args = func_get_args();
        $bucketName = array_shift($args);
        $key = implode('/', $args);
        if (!$bucketName || !$key) {
            $this->send_error(400, 'InvalidRequest', 'bucket and key are required in the path');
        }

        $bucketRow = $this->bucket_model->findByName($bucketName);
        if (!$bucketRow) {
            $this->send_error(404, 'NoSuchBucket', 'The specified bucket does not exist', $bucketName);
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->send_error(400, 'InvalidRequest', 'Expected a multipart/form-data field named "file"', $key);
        }
        $file = $_FILES['file'];

        $this->load->library('upload_validator');
        $this->load->library('virus_scanner');
        $this->load->library('image_processor');
        $this->load->library('filesystem_driver');
        $this->load->model('object_model');

        $maxSize = min((int) $bucketRow['max_object_size'], (int) $this->config->item('s3_max_upload_size', 's3'));
        if (!$this->upload_validator->validateSize($file['size'], $maxSize)) {
            $this->send_error(400, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', $key);
        }

        $contentType = !empty($file['type']) ? $file['type'] : 'application/octet-stream';
        $allowed = $bucketRow['allowed_mime_types'] ? json_decode($bucketRow['allowed_mime_types'], TRUE) : NULL;
        if (!$this->upload_validator->validateMime($contentType, $allowed)) {
            $this->send_error(400, 'InvalidContentType', 'Content-Type not allowed for this bucket', $key);
        }

        $scan = $this->virus_scanner->scanFile($file['tmp_name']);
        if (!$scan['clean']) {
            @unlink($file['tmp_name']);
            $this->audit('virus_detected', $key, 400, $bucketName);
            $this->send_error(400, 'VirusDetected', 'Upload rejected: ' . $scan['signature'], $key);
        }

        $input = fopen($file['tmp_name'], 'rb');
        try {
            $result = $this->filesystem_driver->putObjectFromStream($bucketRow['name'], $key, $input, $maxSize);
        } catch (RuntimeException $e) {
            fclose($input);
            $this->send_error(500, 'InternalError', 'Failed to store object', $key);
            return;
        }
        fclose($input);

        $saved = $this->object_model->put($bucketRow, $key, $result, $contentType, array());

        if ($this->image_processor->isImage($contentType)) {
            $thumb = $this->image_processor->thumbnail($file['tmp_name']);
            if ($thumb !== NULL) {
                $thumbResult = $this->filesystem_driver->putObjectFromString($bucketRow['name'], $key . '.thumb.jpg', $thumb);
                $this->object_model->put($bucketRow, $key . '.thumb.jpg', $thumbResult, 'image/jpeg', array());
            }
        }

        $this->emitEvent($bucketRow, $key, 'object.created', array('size' => $saved['size'], 'etag' => $saved['etag'], 'source' => 'backend_upload'));

        $this->audit('backend_upload', $key, 200, $bucketName);
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'bucket' => $bucketName,
            'key' => $key,
            'etag' => $saved['etag'],
            'size' => $saved['size'],
            'versionId' => $saved['version_id'],
        )));
    }

    protected function emitEvent($bucketRow, $key, $eventType, array $payload = array())
    {
        $this->load->model('event_model');
        $this->event_model->push($bucketRow, $key, $eventType, $payload);
    }
}
