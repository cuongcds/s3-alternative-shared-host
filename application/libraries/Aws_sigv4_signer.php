<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Real AWS Signature Version 4 verifier (header auth + presigned query
 * auth), so unmodified AWS SDKs (aws-sdk-js, boto3, aws-cli, minio client...)
 * can talk to this server directly — just point `endpoint`/`region` at it
 * and use the single fixed Access Key/Secret Key as AWS credentials, no
 * custom presign call needed. Runs alongside (not instead of) Sigv4_signer's
 * OS3-HMAC-SHA256 scheme — MY_Controller::require_auth() picks whichever one
 * the incoming request is actually using.
 *
 * Region/service in the credential scope (e.g. "us-east-1"/"s3") are NOT
 * validated against anything — they're fed back into our own signing-key
 * derivation, so the comparison is self-consistent regardless of what the
 * client configured; only knowing the real secret key produces a match.
 *
 * Canonical-request construction cross-checked byte-for-byte against real
 * @aws-sdk/client-s3 / @aws-sdk/s3-request-presigner / @aws-sdk/signature-v4
 * output (presigned PUT, presigned GET, header-signed PUT with multiple
 * signed headers) — see docs/plans.md section 5.
 */
class Aws_sigv4_signer
{
    const ALGO = 'AWS4-HMAC-SHA256';
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
     * AWS's flavor of RFC 3986 percent-encoding: like rawurlencode(), except
     * '~' is left unescaped (rawurlencode() encodes it as PHP < 5.6 used to
     * require; AWS's spec explicitly wants '~' literal).
     */
    protected function rfc3986Encode($value)
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    /**
     * S3 canonical URI: each path segment percent-encoded once (S3 is the
     * one AWS service that does NOT double-encode, unlike the general SigV4
     * spec used by other services).
     */
    protected function canonicalUri($path)
    {
        $path = '/' . ltrim($path, '/');
        $segments = explode('/', $path);
        $encoded = array_map(array($this, 'rfc3986Encode'), $segments);
        return implode('/', $encoded);
    }

    protected function canonicalQueryString(array $params, array $exclude = array())
    {
        $pairs = array();
        foreach ($params as $k => $v) {
            if (in_array($k, $exclude, TRUE)) {
                continue;
            }
            $pairs[] = $this->rfc3986Encode($k) . '=' . $this->rfc3986Encode((string) $v);
        }
        sort($pairs);
        return implode('&', $pairs);
    }

    protected function currentHost()
    {
        return isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    }

    protected function getHeader($name)
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        // Content-Type and Content-Length are the two headers the CGI spec
        // exposes WITHOUT the HTTP_ prefix (true for both php-fpm/nginx and
        // mod_php/Apache) — AWS SDKs commonly include content-type in
        // SignedHeaders, so without this fallback its value reads back
        // empty here and every such signature fails to verify.
        $bare = str_replace('-', '_', strtoupper($name));
        if (($bare === 'CONTENT_TYPE' || $bare === 'CONTENT_LENGTH') && isset($_SERVER[$bare])) {
            return $_SERVER[$bare];
        }
        return NULL;
    }

    protected function headerValue($name)
    {
        if (strtolower($name) === 'host') {
            return $this->currentHost();
        }
        return $this->getHeader($name);
    }

    /**
     * Builds AWS's CanonicalHeaders block: "name:value\n" per signed header
     * (already lowercased/trimmed), ending in a trailing "\n" — combined
     * with the "\n" already used to join canonical-request pieces, this is
     * exactly what produces the blank line AWS's own examples show between
     * the headers block and the SignedHeaders line.
     */
    protected function canonicalHeaders(array $signedHeaders)
    {
        $lines = array();
        foreach ($signedHeaders as $name) {
            $lines[] = strtolower($name) . ':' . trim((string) $this->headerValue($name));
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{access_key_id:string,date:string,region:string,service:string,terminator:string}|null
     */
    protected function parseCredential($credential)
    {
        $parts = explode('/', $credential);
        if (count($parts) !== 5) {
            return NULL;
        }
        return array(
            'access_key_id' => $parts[0],
            'date' => $parts[1],
            'region' => $parts[2],
            'service' => $parts[3],
            'terminator' => $parts[4],
        );
    }

    protected function signingKey($dateStamp, $region, $service)
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey(), TRUE);
        $kRegion = hash_hmac('sha256', $region, $kDate, TRUE);
        $kService = hash_hmac('sha256', $service, $kRegion, TRUE);
        return hash_hmac('sha256', 'aws4_request', $kService, TRUE);
    }

    protected function computeSignature($canonicalRequest, $amzDate, $dateStamp, $region, $service)
    {
        $stringToSign = self::ALGO . "\n"
            . $amzDate . "\n"
            . $dateStamp . '/' . $region . '/' . $service . '/aws4_request' . "\n"
            . hash('sha256', $canonicalRequest);

        $key = $this->signingKey($dateStamp, $region, $service);
        return hash_hmac('sha256', $stringToSign, $key);
    }

    protected function parseAmzDate($amzDate)
    {
        $dt = DateTime::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : FALSE;
    }

    /**
     * Header-based Authorization: AWS4-HMAC-SHA256 Credential=..., SignedHeaders=..., Signature=...
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
        list(, $credentialStr, $signedHeadersStr, $signature) = $m;

        $credential = $this->parseCredential($credentialStr);
        if (!$credential || $credential['terminator'] !== 'aws4_request') {
            return array('ok' => FALSE, 'error' => 'MalformedCredential');
        }
        if (!hash_equals($this->accessKeyId(), $credential['access_key_id'])) {
            return array('ok' => FALSE, 'error' => 'InvalidAccessKeyId');
        }

        $amzDate = $this->getHeader('X-Amz-Date');
        if (!$amzDate) {
            return array('ok' => FALSE, 'error' => 'MissingDateHeader');
        }
        $ts = $this->parseAmzDate($amzDate);
        $skew = (int) $this->cfg('s3_clock_skew_tolerance');
        if ($ts === FALSE || abs(time() - $ts) > $skew) {
            return array('ok' => FALSE, 'error' => 'RequestTimeTooSkewed');
        }

        // Payload integrity isn't independently re-verified against the real
        // body (same pragmatic tradeoff as Sigv4_signer — see its docblock):
        // the signature covers whatever hash value the client declared, so
        // a client can only defeat their own integrity check, not forge auth.
        $payloadHash = $this->getHeader('X-Amz-Content-Sha256') ?: self::UNSIGNED_PAYLOAD;

        $signedHeaders = explode(';', $signedHeadersStr);
        $canonicalRequest = strtoupper($method) . "\n"
            . $this->canonicalUri($path) . "\n"
            . $this->canonicalQueryString($queryParams) . "\n"
            . $this->canonicalHeaders($signedHeaders) . "\n"
            . $signedHeadersStr . "\n"
            . $payloadHash;

        $dateStamp = substr($amzDate, 0, 8);
        $expected = $this->computeSignature($canonicalRequest, $amzDate, $dateStamp, $credential['region'], $credential['service']);
        if (!hash_equals($expected, $signature)) {
            return array('ok' => FALSE, 'error' => 'SignatureDoesNotMatch');
        }

        return array('ok' => TRUE, 'error' => NULL);
    }

    /**
     * Presigned-URL (query-string) auth: X-Amz-Algorithm / Credential / Date /
     * Expires / SignedHeaders / Signature query params.
     */
    public function verifyQueryAuth($method, $path, array $queryParams)
    {
        foreach (array('X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature') as $required) {
            if (!isset($queryParams[$required]) || $queryParams[$required] === '') {
                return array('ok' => FALSE, 'error' => 'MissingPresignParam:' . $required);
            }
        }
        if ($queryParams['X-Amz-Algorithm'] !== self::ALGO) {
            return array('ok' => FALSE, 'error' => 'UnsupportedAlgorithm');
        }

        $credential = $this->parseCredential($queryParams['X-Amz-Credential']);
        if (!$credential || $credential['terminator'] !== 'aws4_request') {
            return array('ok' => FALSE, 'error' => 'MalformedCredential');
        }
        if (!hash_equals($this->accessKeyId(), $credential['access_key_id'])) {
            return array('ok' => FALSE, 'error' => 'InvalidAccessKeyId');
        }

        $amzDate = $queryParams['X-Amz-Date'];
        $ts = $this->parseAmzDate($amzDate);
        if ($ts === FALSE) {
            return array('ok' => FALSE, 'error' => 'InvalidDate');
        }
        $expires = (int) $queryParams['X-Amz-Expires'];
        $maxTtl = (int) $this->cfg('s3_presign_max_ttl');
        if ($expires < 1 || $expires > $maxTtl) {
            return array('ok' => FALSE, 'error' => 'InvalidExpires');
        }
        $skew = (int) $this->cfg('s3_clock_skew_tolerance');
        $now = time();
        if ($now > $ts + $expires + $skew) {
            return array('ok' => FALSE, 'error' => 'RequestExpired');
        }
        if ($now < $ts - $skew) {
            return array('ok' => FALSE, 'error' => 'RequestNotYetValid');
        }

        $signedHeaders = explode(';', $queryParams['X-Amz-SignedHeaders']);
        $payloadHash = isset($queryParams['X-Amz-Content-Sha256']) ? $queryParams['X-Amz-Content-Sha256'] : self::UNSIGNED_PAYLOAD;

        $canonicalRequest = strtoupper($method) . "\n"
            . $this->canonicalUri($path) . "\n"
            . $this->canonicalQueryString($queryParams, array('X-Amz-Signature')) . "\n"
            . $this->canonicalHeaders($signedHeaders) . "\n"
            . $queryParams['X-Amz-SignedHeaders'] . "\n"
            . $payloadHash;

        $dateStamp = substr($amzDate, 0, 8);
        $expected = $this->computeSignature($canonicalRequest, $amzDate, $dateStamp, $credential['region'], $credential['service']);
        if (!hash_equals($expected, $queryParams['X-Amz-Signature'])) {
            return array('ok' => FALSE, 'error' => 'SignatureDoesNotMatch');
        }

        return array('ok' => TRUE, 'error' => NULL);
    }
}
