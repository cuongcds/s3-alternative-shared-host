<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Object_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('filesystem_driver');
    }

    public function getCurrent($bucketId, $key)
    {
        return $this->db->where('bucket_id', $bucketId)
            ->where('object_key', $key)
            ->where('is_deleted', 0)
            ->order_by('created_at', 'DESC')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get('objects')
            ->row_array();
    }

    public function getVersion($bucketId, $key, $versionId)
    {
        return $this->db->where('bucket_id', $bucketId)
            ->where('object_key', $key)
            ->where('version_id', $versionId)
            ->get('objects')
            ->row_array();
    }

    public function listByPrefix($bucketId, $prefix = '', $limit = 1000, $marker = '')
    {
        $builder = $this->db->select('object_key, size, etag, content_type, created_at')
            ->where('bucket_id', $bucketId)
            ->where('is_deleted', 0)
            ->where('version_id', NULL)
            ->order_by('object_key', 'ASC')
            ->limit($limit + 1);

        if ($prefix !== '') {
            $builder->like('object_key', $prefix, 'after');
        }
        if ($marker !== '') {
            $builder->where('object_key >', $marker);
        }

        return $builder->get('objects')->result_array();
    }

    /**
     * Groups current object keys directly under $prefix into "folders"
     * (the part up to the next '/') and leaf "files", for the admin tree
     * browser (docs/plans_v2.md section 7.3) — object_key has no real
     * directory concept, this just mimics S3 ListObjectsV2's
     * CommonPrefixes/Contents split for a delimiter of '/'.
     *
     * Scans at most 5000 rows under the prefix (one directory level, not
     * the whole bucket) so a very large bucket can't make one call slow;
     * `truncated` tells the caller (and thus the UI) when that cap was hit.
     *
     * @return array{folders:array,files:array,truncated:bool}
     */
    public function listFolder($bucketId, $prefix = '', $limit = 500)
    {
        $scanLimit = 5000;
        $builder = $this->db->select('object_key, size, etag, content_type, created_at')
            ->where('bucket_id', $bucketId)
            ->where('is_deleted', 0)
            ->where('version_id', NULL)
            ->order_by('object_key', 'ASC')
            ->limit($scanLimit);

        if ($prefix !== '') {
            $builder->like('object_key', $prefix, 'after');
        }

        $rows = $builder->get('objects')->result_array();

        $prefixLen = strlen($prefix);
        $folderCounts = array();
        $files = array();
        foreach ($rows as $row) {
            $rest = substr($row['object_key'], $prefixLen);
            $slashPos = strpos($rest, '/');
            if ($slashPos !== FALSE) {
                $folderName = substr($rest, 0, $slashPos);
                if (!isset($folderCounts[$folderName])) {
                    $folderCounts[$folderName] = 0;
                }
                $folderCounts[$folderName]++;
                continue;
            }
            $files[] = array(
                'key' => $row['object_key'],
                'name' => $rest,
                'size' => (int) $row['size'],
                'etag' => $row['etag'],
                'content_type' => $row['content_type'],
                'created_at' => $row['created_at'],
            );
        }

        $folders = array();
        foreach ($folderCounts as $name => $count) {
            $folders[] = array('name' => $name, 'count' => $count);
        }

        return array(
            'folders' => $folders,
            'files' => array_slice($files, 0, $limit),
            'truncated' => count($rows) >= $scanLimit || count($files) > $limit,
        );
    }

    /**
     * Persist a newly uploaded object. When the bucket has versioning
     * disabled, any previous current row (and its file) is removed first so
     * exactly one row represents the object, matching S3's non-versioned
     * PutObject behaviour (overwrite in place).
     */
    public function put($bucket, $key, $streamResult, $contentType, $metadata = array())
    {
        $bucketId = $bucket['id'];
        $versioningEnabled = (bool) $bucket['versioning_enabled'];
        $versionId = $versioningEnabled ? $this->generateVersionId() : NULL;

        $this->db->trans_start();

        if (!$versioningEnabled) {
            $existing = $this->db->where('bucket_id', $bucketId)
                ->where('object_key', $key)
                ->where('version_id', NULL)
                ->get('objects')->row_array();
            if ($existing) {
                $this->filesystem_driver->deleteObjectFile($existing['storage_path']);
                $this->db->where('id', $existing['id'])->delete('objects');
            }
        }

        $this->db->insert('objects', array(
            'bucket_id' => $bucketId,
            'object_key' => $key,
            'version_id' => $versionId,
            'size' => $streamResult['size'],
            'etag' => $streamResult['etag'],
            'content_type' => $contentType,
            'storage_path' => $streamResult['path'],
            'metadata' => empty($metadata) ? NULL : json_encode($metadata),
            'is_deleted' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $objectId = $this->db->insert_id();

        $this->db->trans_complete();

        return array(
            'id' => $objectId,
            'version_id' => $versionId,
            'etag' => $streamResult['etag'],
            'size' => $streamResult['size'],
        );
    }

    /**
     * Delete an object. With versioning off (or a specific versionId given)
     * this is a hard delete of the row + file; with versioning on and no
     * versionId given, S3 semantics apply: a delete-marker row is inserted
     * instead and prior versions are preserved.
     */
    public function delete($bucket, $key, $versionId = NULL)
    {
        $bucketId = $bucket['id'];
        $versioningEnabled = (bool) $bucket['versioning_enabled'];

        if ($versioningEnabled && $versionId === NULL) {
            $this->db->insert('objects', array(
                'bucket_id' => $bucketId,
                'object_key' => $key,
                'version_id' => $this->generateVersionId(),
                'size' => 0,
                'etag' => '',
                'content_type' => NULL,
                'storage_path' => '',
                'is_deleted' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            return TRUE;
        }

        $builder = $this->db->where('bucket_id', $bucketId)->where('object_key', $key);
        $builder = $versionId !== NULL ? $builder->where('version_id', $versionId) : $builder->where('version_id', NULL);
        $row = $builder->get('objects')->row_array();
        if (!$row) {
            return FALSE;
        }
        if (!empty($row['storage_path'])) {
            $this->filesystem_driver->deleteObjectFile($row['storage_path']);
        }
        $this->db->where('id', $row['id'])->delete('objects');
        return TRUE;
    }

    protected function generateVersionId()
    {
        return bin2hex(random_bytes(16));
    }
}
