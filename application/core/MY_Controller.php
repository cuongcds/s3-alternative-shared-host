<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controller for every S3/internal API endpoint: enforces request
 * signature auth (header or presigned query string) before the concrete
 * controller runs, and provides shared XML-error + audit-log helpers.
 *
 * Two independent signing schemes are accepted — whichever one the incoming
 * request is actually using:
 * - `OS3-HMAC-SHA256` (Sigv4_signer) — this project's own scheme, used by
 *   `/internal/presign` and the Postman collection.
 * - `AWS4-HMAC-SHA256` (Aws_sigv4_signer) — the real AWS SigV4 spec, so
 *   unmodified AWS SDKs (aws-sdk-js, boto3, aws-cli...) work by just pointing
 *   `endpoint` at this server with the same Access Key/Secret Key.
 */
class MY_Controller extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->config('s3', TRUE);
        $this->load->database();
        $this->load->library('sigv4_signer');
        $this->load->library('aws_sigv4_signer');
    }

    protected function request_method()
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    protected function current_path()
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($path === NULL) {
            return '/';
        }
        // REQUEST_URI is percent-encoded as it arrived on the wire, but both
        // signers' canonicalUri() percent-encode each segment themselves (S3
        // signs the URI-encoded-exactly-once form) — decode here first, or
        // any key needing escaping (spaces, commas, etc.) gets double-encoded
        // and fails signature verification.
        return rawurldecode($path);
    }

    protected function all_query_params()
    {
        return $_GET;
    }

    protected $rawBodyCache = NULL;

    /**
     * Reads php://input once and caches it — some SAPIs only allow reading
     * the request body stream a single time, so every caller (signature
     * verification, JSON parsing) must share this cached copy.
     */
    protected function raw_body()
    {
        if ($this->rawBodyCache === NULL) {
            $this->rawBodyCache = file_get_contents('php://input');
        }
        return $this->rawBodyCache;
    }

    /**
     * Verify request signature, auto-detecting which of the two schemes
     * (see class docblock) the request is using: a presigned URL is
     * identified by which "*-Signature" query param is present; a header-
     * signed request by the Authorization header's algorithm prefix.
     * Halts the request on failure.
     */
    protected function require_auth($rawBody = NULL)
    {
        $method = $this->request_method();
        $path = $this->current_path();
        $query = $this->all_query_params();

        if (isset($query['X-Amz-Signature'])) {
            $result = $this->aws_sigv4_signer->verifyQueryAuth($method, $path, $query);
        } elseif (isset($query['X-Os3-Signature'])) {
            $result = $this->sigv4_signer->verifyQueryAuth($method, $path, $query);
        } elseif (strpos((string) $this->get_header('Authorization'), 'AWS4-HMAC-SHA256 ') === 0) {
            $result = $this->aws_sigv4_signer->verifyHeaderAuth($method, $path, $query, $rawBody);
        } else {
            $result = $this->sigv4_signer->verifyHeaderAuth($method, $path, $query, $rawBody);
        }

        if (!$result['ok']) {
            $this->send_error(403, 'AccessDenied', $result['error']);
        }
    }

    protected function get_header($name)
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        return isset($_SERVER[$key]) ? $_SERVER[$key] : NULL;
    }

    /**
     * Applies a bucket's `cors_config` (see Bucket_model/Internal::policy) to
     * the response: sets Access-Control-Allow-Origin (+ related headers) when
     * the request's Origin matches, and short-circuits OPTIONS preflight
     * requests with a 204 — those never carry a signature, so this must run
     * BEFORE require_auth(). Matches AWS S3's default: no cors_config means
     * no CORS headers at all (cross-origin browser calls are blocked), same
     * as a bucket with no CORS rules configured on real S3.
     *
     * cors_config shape: {"allowed_origins":["*"], "allowed_methods":[...],
     * "allowed_headers":["*"], "expose_headers":[...], "max_age":3600}
     */
    protected function apply_cors($corsConfigJson)
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : NULL;
        if (!$origin) {
            return;
        }

        $config = $corsConfigJson ? json_decode($corsConfigJson, TRUE) : NULL;
        $allowedOrigins = !empty($config['allowed_origins']) ? $config['allowed_origins'] : array();
        if (empty($allowedOrigins)) {
            return;
        }

        $matchedOrigin = in_array('*', $allowedOrigins, TRUE) ? '*' : NULL;
        if ($matchedOrigin === NULL && in_array($origin, $allowedOrigins, TRUE)) {
            $matchedOrigin = $origin;
        }
        if ($matchedOrigin === NULL) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $matchedOrigin);
        header('Vary: Origin');
        if (!empty($config['expose_headers'])) {
            header('Access-Control-Expose-Headers: ' . implode(',', $config['expose_headers']));
        }

        if ($this->request_method() === 'OPTIONS') {
            $methods = !empty($config['allowed_methods']) ? $config['allowed_methods'] : array('GET', 'PUT', 'POST', 'DELETE', 'HEAD');
            $headers = !empty($config['allowed_headers']) ? $config['allowed_headers'] : array('*');
            $maxAge = isset($config['max_age']) ? (int) $config['max_age'] : 3600;
            header('Access-Control-Allow-Methods: ' . implode(',', $methods));
            header('Access-Control-Allow-Headers: ' . implode(',', $headers));
            header('Access-Control-Max-Age: ' . $maxAge);
            http_response_code(204);
            exit;
        }
    }

    protected function send_error($httpStatus, $code, $message, $resource = NULL)
    {
        $this->audit('error', $resource, $httpStatus);

        $this->output->set_status_header($httpStatus);
        $this->output->set_content_type('application/xml');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Error>'
            . '<Code>' . htmlspecialchars($code, ENT_XML1) . '</Code>'
            . '<Message>' . htmlspecialchars($message, ENT_XML1) . '</Message>'
            . ($resource ? '<Resource>' . htmlspecialchars($resource, ENT_XML1) . '</Resource>' : '')
            . '<RequestId>' . uniqid('req-', TRUE) . '</RequestId>'
            . '</Error>';
        $this->output->set_output($xml);
        $this->output->_display();
        exit;
    }

    protected function audit($action, $objectKey = NULL, $statusCode = 200, $bucket = NULL)
    {
        $this->db->insert('audit_logs', array(
            'action' => $action,
            'bucket' => $bucket,
            'object_key' => $objectKey,
            'ip' => $this->input->ip_address(),
            'status_code' => $statusCode,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}
