<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * S3-compatible REST API entry point. Every non-internal path is routed here
 * (see application/config/routes.php) because bucket/object keys can contain
 * '/', which CodeIgniter 3 splits into separate positional segments — so we
 * accept them as variadic args and rejoin everything after the bucket name
 * into the object key ourselves.
 */
class S3 extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('s3');
        $this->load->library('filesystem_driver');
        $this->load->model('bucket_model');
        $this->load->model('object_model');
    }

    public function index()
    {
        $args = func_get_args();
        $method = $this->request_method();

        // CORS must be evaluated before auth: browser preflight (OPTIONS)
        // requests never carry a signature, and apply_cors() exits early for
        // them once the right Access-Control-* headers are set.
        $bucketRow = isset($args[0]) ? $this->bucket_model->findByName($args[0]) : NULL;
        $this->apply_cors($bucketRow ? $bucketRow['cors_config'] : NULL);

        // Public-read bucket (`is_public`, set via PUT .../policy): anonymous
        // GET/HEAD on an OBJECT skips auth entirely, matching a typical
        // "public bucket" — bucket-level listing and every write still
        // always require a valid signature.
        $isAnonymousObjectRead = $bucketRow
            && (bool) $bucketRow['is_public']
            && in_array($method, array('GET', 'HEAD'), TRUE)
            && count($args) > 1;

        if (!$isAnonymousObjectRead) {
            $this->require_auth();
        }

        if (empty($args)) {
            if ($method !== 'GET') {
                $this->send_error(405, 'MethodNotAllowed', 'Method not allowed for this resource');
            }
            return $this->listBuckets();
        }

        $bucketName = array_shift($args);

        if (empty($args)) {
            return $this->dispatchBucket($bucketName, $method);
        }

        $key = implode('/', $args);
        return $this->dispatchObject($bucketName, $key, $method);
    }

    protected function dispatchBucket($bucketName, $method)
    {
        if ($method === 'PUT') {
            if (!$this->filesystem_driver->validateBucketName($bucketName)) {
                $this->send_error(400, 'InvalidBucketName', 'The specified bucket is not valid', $bucketName);
            }
            return $this->createBucket($bucketName);
        }

        $bucketRow = $this->bucket_model->findByName($bucketName);
        if (!$bucketRow) {
            $this->send_error(404, 'NoSuchBucket', 'The specified bucket does not exist', $bucketName);
        }

        switch ($method) {
            case 'GET':
                return $this->listObjects($bucketRow);
            case 'HEAD':
                $this->audit('head_bucket', NULL, 200, $bucketName);
                http_response_code(200);
                exit;
            case 'DELETE':
                return $this->deleteBucket($bucketRow);
            default:
                $this->send_error(405, 'MethodNotAllowed', 'Method not allowed for this resource', $bucketName);
        }
    }

    protected function dispatchObject($bucketName, $key, $method)
    {
        if (isset($_GET['uploads']) || isset($_GET['uploadId'])) {
            $this->send_error(501, 'NotImplemented', 'Multipart upload is not implemented yet (see docs/plans.md Phase 7)', $key);
        }

        $bucketRow = $this->bucket_model->findByName($bucketName);
        if (!$bucketRow) {
            $this->send_error(404, 'NoSuchBucket', 'The specified bucket does not exist', $bucketName);
        }

        switch ($method) {
            case 'PUT':
                return $this->putObject($bucketRow, $key);
            case 'GET':
                return $this->serveObject($bucketRow, $key, TRUE);
            case 'HEAD':
                return $this->serveObject($bucketRow, $key, FALSE);
            case 'DELETE':
                return $this->deleteObject($bucketRow, $key);
            default:
                $this->send_error(405, 'MethodNotAllowed', 'Method not allowed for this resource', $key);
        }
    }

    protected function listBuckets()
    {
        $buckets = $this->bucket_model->listAll();
        $xml = s3_list_all_my_buckets_xml($buckets, $this->config->item('s3_access_key_id', 's3'));
        $this->audit('list_buckets', NULL, 200);
        $this->output->set_content_type('application/xml')->set_output($xml);
    }

    protected function createBucket($name)
    {
        $existing = $this->bucket_model->findByName($name);
        if ($existing) {
            // Single-account semantics: creating a bucket you already own is idempotent.
            http_response_code(200);
            exit;
        }
        if ($this->bucket_model->create($name) === FALSE) {
            $this->send_error(500, 'InternalError', 'Failed to create bucket', $name);
        }
        $this->filesystem_driver->ensureBucketDir($name);
        $this->audit('create_bucket', NULL, 200, $name);
        http_response_code(200);
        exit;
    }

    protected function deleteBucket($bucketRow)
    {
        $removed = $this->filesystem_driver->deleteBucketDir($bucketRow['name']);
        if (!$removed) {
            $this->send_error(409, 'BucketNotEmpty', 'The bucket you tried to delete is not empty', $bucketRow['name']);
        }
        $this->bucket_model->delete($bucketRow['id']);
        $this->audit('delete_bucket', NULL, 204, $bucketRow['name']);
        http_response_code(204);
        exit;
    }

    protected function listObjects($bucketRow)
    {
        $prefix = (string) $this->input->get('prefix');
        $marker = (string) $this->input->get('marker');
        $maxKeys = (int) $this->input->get('max-keys');
        $maxKeys = $maxKeys > 0 ? min(1000, $maxKeys) : 1000;

        $rows = $this->object_model->listByPrefix($bucketRow['id'], $prefix, $maxKeys, $marker);
        $truncated = count($rows) > $maxKeys;
        if ($truncated) {
            array_pop($rows);
        }

        $xml = s3_list_bucket_result_xml($bucketRow['name'], $rows, $prefix, $maxKeys, $truncated);
        $this->audit('list_objects', NULL, 200, $bucketRow['name']);
        $this->output->set_content_type('application/xml')->set_output($xml);
    }

    protected function putObject($bucketRow, $key)
    {
        $maxSize = min((int) $bucketRow['max_object_size'], (int) $this->config->item('s3_max_upload_size', 's3'));

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : NULL;
        if ($contentLength !== NULL && $contentLength > $maxSize) {
            $this->send_error(400, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', $key);
        }

        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'application/octet-stream';
        $allowed = $bucketRow['allowed_mime_types'] ? json_decode($bucketRow['allowed_mime_types'], TRUE) : NULL;
        if ($allowed && !in_array($contentType, $allowed, TRUE)) {
            $this->send_error(400, 'InvalidContentType', 'Content-Type not allowed for this bucket', $key);
        }

        $input = fopen('php://input', 'rb');
        try {
            $result = $this->filesystem_driver->putObjectFromStream($bucketRow['name'], $key, $input, $maxSize);
        } catch (RuntimeException $e) {
            fclose($input);
            if ($e->getMessage() === 'EntityTooLarge') {
                $this->send_error(400, 'EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', $key);
            }
            $this->send_error(500, 'InternalError', 'Failed to store object', $key);
            return;
        }
        fclose($input);

        $saved = $this->object_model->put($bucketRow, $key, $result, $contentType, $this->extractUserMetadataHeaders());
        $this->emitEvent($bucketRow, $key, 'object.created', array('size' => $saved['size'], 'etag' => $saved['etag'], 'source' => 'direct_put'));

        $this->audit('put_object', $key, 200, $bucketRow['name']);
        header('ETag: "' . $saved['etag'] . '"');
        if ($saved['version_id']) {
            header('x-os3-version-id: ' . $saved['version_id']);
        }
        http_response_code(200);
        exit;
    }

    protected function serveObject($bucketRow, $key, $sendBody)
    {
        $obj = $this->object_model->getCurrent($bucketRow['id'], $key);
        if (!$obj) {
            $this->send_error(404, 'NoSuchKey', 'The specified key does not exist', $key);
        }

        try {
            $real = $this->filesystem_driver->assertPathWithinRoot($obj['storage_path']);
        } catch (RuntimeException $e) {
            $this->send_error(500, 'InternalError', 'Object file is missing on disk', $key);
            return;
        }

        $size = (int) $obj['size'];
        header('Content-Type: ' . ($obj['content_type'] ?: 'application/octet-stream'));
        header('ETag: "' . $obj['etag'] . '"');
        header('Accept-Ranges: bytes');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($obj['created_at'])) . ' GMT');

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : NULL;
        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = (int) $m[2];
            }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
            $status = 206;
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        $length = $end - $start + 1;
        http_response_code($status);
        header('Content-Length: ' . $length);

        $this->audit($sendBody ? 'get_object' : 'head_object', $key, $status, $bucketRow['name']);

        if (!$sendBody) {
            exit;
        }

        $fh = fopen($real, 'rb');
        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $chunk = fread($fh, min(1048576, $remaining));
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }

    protected function deleteObject($bucketRow, $key)
    {
        $versionId = $this->input->get('versionId');
        $this->object_model->delete($bucketRow, $key, $versionId ?: NULL);
        $this->emitEvent($bucketRow, $key, 'object.removed');
        $this->audit('delete_object', $key, 204, $bucketRow['name']);
        http_response_code(204);
        exit;
    }

    protected function emitEvent($bucketRow, $key, $eventType, array $payload = array())
    {
        $this->load->model('event_model');
        $this->event_model->push($bucketRow, $key, $eventType, $payload);
    }

    protected function extractUserMetadataHeaders()
    {
        $meta = array();
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_X_OS3_META_') === 0) {
                $name = strtolower(str_replace('HTTP_X_OS3_META_', '', $k));
                $meta[$name] = $v;
            }
        }
        return $meta;
    }
}
