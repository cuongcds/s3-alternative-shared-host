<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * OS3-HMAC-SHA256 request signer/verifier.
 *
 * This is a SigV4-shaped (but not AWS-SigV4-compatible) HMAC scheme, sized for
 * a single fixed Access Key/Secret Key account (see docs/plans.md, section 5).
 * Supports both header auth (SDK-style clients) and query-string auth
 * (presigned URLs for direct browser upload/download).
 */
class Sigv4_signer
{
    const ALGO = 'OS3-HMAC-SHA256';
    const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /** @var CI_Controller */
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->config->load('s3', TRUE);
    }

    protected function cfg($key)
    {
        return $this->ci->config->item($key, 's3');
    }

    protected function accessKeyId()
    {
        return $this->cfg('s3_access_key_id');
    }

    protected function secretKey()
    {
        return $this->cfg('s3_secret_access_key');
    }

    /**
     * Canonicalize a request path: encode each segment, keep '/' separators.
     */
    protected function canonicalUri($path)
    {
        $path = '/' . ltrim($path, '/');
        $segments = explode('/', $path);
        $encoded = array_map(function ($seg) {
            return rawurlencode($seg);
        }, $segments);
        return implode('/', $encoded);
    }

    /**
     * Canonicalize a query-string array: sorted, rawurlencoded key=value pairs.
     * @param array $params assoc array of query params
     * @param array $exclude keys to drop (e.g. the signature itself)
     */
    protected function canonicalQueryString(array $params, array $exclude = array())
    {
        $pairs = array();
        foreach ($params as $k => $v) {
            if (in_array($k, $exclude, TRUE)) {
                continue;
            }
            $pairs[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
        }
        sort($pairs);
        return implode('&', $pairs);
    }

    protected function hmac($secret, $data)
    {
        return hash_hmac('sha256', $data, $secret);
    }

    protected function currentHost()
    {
        return isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    }

    /**
     * Build & verify the header-based Authorization scheme used by SDK-style clients.
     *
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public function verifyHeaderAuth($method, $path, array $queryParams, $rawBody = NULL)
    {
        $authHeader = $this->getHeader('Authorization');
        if (!$authHeader) {
            return array('ok' => FALSE, 'error' => 'MissingAuthorizationHeader');
        }

        if (!preg_match('/^' . preg_quote(self::ALGO, '/') . ' Credential=([^,]+), SignedHeaders=([^,]+), Signature=([0-9a-f]{64})$/', trim($authHeader), $m)) {
            return array('ok' => FALSE, 'error' => 'MalformedAuthorizationHeader');
        }
        list(, $credential, $signedHeadersStr, $signature) = $m;

        if (!hash_equals($this->accessKeyId(), $credential)) {
            return array('ok' => FALSE, 'error' => 'InvalidAccessKeyId');
        }

        $date = $this->getHeader('X-Os3-Date');
        if (!$date) {
            return array('ok' => FALSE, 'error' => 'MissingDateHeader');
        }
        $skew = (int) $this->cfg('s3_clock_skew_tolerance');
        $ts = strtotime($date);
        if ($ts === FALSE || abs(time() - $ts) > $skew) {
            return array('ok' => FALSE, 'error' => 'RequestTimeTooSkewed');
        }

        $payloadHashHeader = $this->getHeader('X-Os3-Content-Sha256');
        if ($payloadHashHeader === self::UNSIGNED_PAYLOAD || !$payloadHashHeader) {
            $payloadHash = self::UNSIGNED_PAYLOAD;
        } else {
            $payloadHash = hash('sha256', (string) $rawBody);
            if (!hash_equals($payloadHashHeader, $payloadHash)) {
                return array('ok' => FALSE, 'error' => 'PayloadHashMismatch');
            }
        }

        $canonicalRequest = implode("\n", array(
            strtoupper($method),
            $this->canonicalUri($path),
            $this->canonicalQueryString($queryParams),
            'host:' . $this->currentHost(),
            'x-os3-date:' . $date,
            '',
            $signedHeadersStr,
            $payloadHash,
        ));

        $stringToSign = implode("\n", array(self::ALGO, $date, hash('sha256', $canonicalRequest)));
        $expected = $this->hmac($this->secretKey(), $stringToSign);

        if (!hash_equals($expected, $signature)) {
            return array('ok' => FALSE, 'error' => 'SignatureDoesNotMatch');
        }

        return array('ok' => TRUE, 'error' => NULL);
    }

    /**
     * Verify a presigned-URL request (query-string auth).
     */
    public function verifyQueryAuth($method, $path, array $queryParams)
    {
        foreach (array('X-Os3-Credential', 'X-Os3-Date', 'X-Os3-Expires', 'X-Os3-SignedHeaders', 'X-Os3-Signature') as $required) {
            if (!isset($queryParams[$required]) || $queryParams[$required] === '') {
                return array('ok' => FALSE, 'error' => 'MissingPresignParam:' . $required);
            }
        }

        if (!hash_equals($this->accessKeyId(), $queryParams['X-Os3-Credential'])) {
            return array('ok' => FALSE, 'error' => 'InvalidAccessKeyId');
        }

        $date = $queryParams['X-Os3-Date'];
        $expires = (int) $queryParams['X-Os3-Expires'];
        $ts = strtotime($date);
        if ($ts === FALSE) {
            return array('ok' => FALSE, 'error' => 'InvalidDate');
        }
        $maxTtl = (int) $this->cfg('s3_presign_max_ttl');
        if ($expires < 1 || $expires > $maxTtl) {
            return array('ok' => FALSE, 'error' => 'InvalidExpires');
        }
        $skew = (int) $this->cfg('s3_clock_skew_tolerance');
        if (time() > $ts + $expires + $skew) {
            return array('ok' => FALSE, 'error' => 'RequestExpired');
        }
        if (time() < $ts - $skew) {
            return array('ok' => FALSE, 'error' => 'RequestNotYetValid');
        }

        $signature = $queryParams['X-Os3-Signature'];

        $canonicalRequest = implode("\n", array(
            strtoupper($method),
            $this->canonicalUri($path),
            $this->canonicalQueryString($queryParams, array('X-Os3-Signature')),
            'host:' . $this->currentHost(),
            '',
            'host',
            self::UNSIGNED_PAYLOAD,
        ));

        $stringToSign = implode("\n", array(self::ALGO, $date, hash('sha256', $canonicalRequest)));
        $expected = $this->hmac($this->secretKey(), $stringToSign);

        if (!hash_equals($expected, $signature)) {
            return array('ok' => FALSE, 'error' => 'SignatureDoesNotMatch');
        }

        return array('ok' => TRUE, 'error' => NULL);
    }

    /**
     * Generate a presigned URL for the given method/path, valid for $ttl seconds.
     *
     * @param string $baseUrl e.g. https://storage.example.com
     */
    public function presignUrl($baseUrl, $method, $path, $ttl = NULL)
    {
        $ttl = $ttl ?: (int) $this->cfg('s3_presign_default_ttl');
        $minTtl = (int) $this->cfg('s3_presign_min_ttl');
        $maxTtl = (int) $this->cfg('s3_presign_max_ttl');
        $ttl = max($minTtl, min($maxTtl, (int) $ttl));

        $date = gmdate('Ymd\THis\Z');

        $params = array(
            'X-Os3-Credential' => $this->accessKeyId(),
            'X-Os3-Date' => $date,
            'X-Os3-Expires' => (string) $ttl,
            'X-Os3-SignedHeaders' => 'host',
        );

        $parsedHost = parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $host = $parsedHost . ($port ? ':' . $port : '');

        $canonicalRequest = implode("\n", array(
            strtoupper($method),
            $this->canonicalUri($path),
            $this->canonicalQueryString($params),
            'host:' . $host,
            '',
            'host',
            self::UNSIGNED_PAYLOAD,
        ));

        $stringToSign = implode("\n", array(self::ALGO, $date, hash('sha256', $canonicalRequest)));
        $params['X-Os3-Signature'] = $this->hmac($this->secretKey(), $stringToSign);

        return rtrim($baseUrl, '/') . $this->canonicalUri($path) . '?' . $this->canonicalQueryString($params);
    }

    protected function getHeader($name)
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        // Content-Type and Content-Length are the two headers the CGI spec
        // exposes WITHOUT the HTTP_ prefix (true for both php-fpm/nginx and
        // mod_php/Apache) — not currently signed by this scheme, but kept
        // consistent with Aws_sigv4_signer's getHeader().
        $bare = str_replace('-', '_', strtoupper($name));
        if (($bare === 'CONTENT_TYPE' || $bare === 'CONTENT_LENGTH') && isset($_SERVER[$bare])) {
            return $_SERVER[$bare];
        }
        return NULL;
    }
}
