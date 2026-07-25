<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Local-filesystem object storage backend.
 *
 * Layout: {root}/{bucket}/objects/{sha1(key)[0:2]}/{sha1(key)[2:6]}/{rawurlencode(key)}
 * The key is never used verbatim as a path component (only its rawurlencode()'d
 * form, which can't contain '/' or '..'), so traversal via object keys is not
 * possible regardless of what characters the key contains.
 */
class Filesystem_driver
{
    protected $root;

    /**
     * @param string|null $root Pass explicitly for standalone (non-CI) use,
     *  e.g. from cli/worker.php. Left NULL, it's read from CI config.
     */
    public function __construct($root = NULL)
    {
        if ($root === NULL && function_exists('get_instance')) {
            $ci = &get_instance();
            $ci->config->load('s3', TRUE);
            $root = $ci->config->item('s3_storage_root', 's3');
        }
        $this->root = $root ?: rtrim(getenv('STORAGE_ROOT') ?: '/data', '/');
    }

    public function validateBucketName($name)
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9\-\.]{1,61}[a-z0-9]$/', $name);
    }

    public function bucketDir($bucket)
    {
        return $this->root . '/' . $bucket;
    }

    public function bucketExists($bucket)
    {
        return is_dir($this->bucketDir($bucket));
    }

    public function ensureBucketDir($bucket)
    {
        $dir = $this->bucketDir($bucket) . '/objects';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, TRUE);
        }
    }

    /**
     * Returns TRUE if the bucket directory was empty and removed, FALSE if
     * it still contains objects (mirrors S3's "bucket must be empty" rule).
     */
    public function deleteBucketDir($bucket)
    {
        $objectsDir = $this->bucketDir($bucket) . '/objects';
        if (is_dir($objectsDir) && $this->dirHasFiles($objectsDir)) {
            return FALSE;
        }
        @rmdir($objectsDir);
        @rmdir($this->bucketDir($bucket));
        return TRUE;
    }

    protected function dirHasFiles($dir)
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function objectPath($bucket, $key)
    {
        $hash = sha1($key);
        return $this->bucketDir($bucket) . '/objects/'
            . substr($hash, 0, 2) . '/' . substr($hash, 2, 4) . '/' . rawurlencode($key);
    }

    /**
     * Write a readable resource to storage atomically (tmp file + rename),
     * computing size/MD5 in a single streaming pass. Enforces $maxSize while
     * writing so oversized uploads are rejected without buffering fully.
     *
     * @return array{size:int,etag:string,path:string}
     * @throws RuntimeException on I/O failure or if $maxSize is exceeded
     */
    public function putObjectFromStream($bucket, $key, $resource, $maxSize = NULL)
    {
        $this->ensureBucketDir($bucket);
        $finalPath = $this->objectPath($bucket, $key);
        $dir = dirname($finalPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, TRUE);
        }
        $tmpPath = $finalPath . '.tmp-' . bin2hex(random_bytes(8));

        $dest = fopen($tmpPath, 'wb');
        if ($dest === FALSE) {
            throw new RuntimeException('Failed to open destination for writing');
        }

        $ctx = hash_init('md5');
        $size = 0;
        while (!feof($resource)) {
            $chunk = fread($resource, 1048576);
            if ($chunk === FALSE) {
                fclose($dest);
                @unlink($tmpPath);
                throw new RuntimeException('Failed to read upload stream');
            }
            $size += strlen($chunk);
            if ($maxSize !== NULL && $size > $maxSize) {
                fclose($dest);
                @unlink($tmpPath);
                throw new RuntimeException('EntityTooLarge');
            }
            hash_update($ctx, $chunk);
            fwrite($dest, $chunk);
        }
        fclose($dest);

        $etag = hash_final($ctx);
        rename($tmpPath, $finalPath);

        return array('size' => $size, 'etag' => $etag, 'path' => $finalPath);
    }

    public function putObjectFromString($bucket, $key, $data)
    {
        $resource = fopen('php://temp', 'r+b');
        fwrite($resource, $data);
        rewind($resource);
        $result = $this->putObjectFromStream($bucket, $key, $resource);
        fclose($resource);
        return $result;
    }

    /**
     * Defense-in-depth: confirm a stored path is really inside STORAGE_ROOT
     * before it's ever opened for read/delete.
     */
    public function assertPathWithinRoot($path)
    {
        $real = realpath($path);
        $realRoot = realpath($this->root);
        if ($real === FALSE || $realRoot === FALSE || strpos($real, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Refusing to access path outside storage root');
        }
        return $real;
    }

    public function deleteObjectFile($path)
    {
        if (is_file($path)) {
            $real = $this->assertPathWithinRoot($path);
            @unlink($real);
        }
    }
}
