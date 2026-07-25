<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Bucket_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function findByName($name)
    {
        return $this->db->get_where('buckets', array('name' => $name, 'deleted_at' => NULL))->row_array();
    }

    public function exists($name)
    {
        return (bool) $this->findByName($name);
    }

    /**
     * @return int|false the new bucket's id, or FALSE if the insert failed
     */
    public function create($name)
    {
        $ok = $this->db->insert('buckets', array(
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        return $ok ? $this->db->insert_id() : FALSE;
    }

    public function listAll()
    {
        return $this->db->select('id, name, created_at')
            ->where('deleted_at', NULL)
            ->order_by('name', 'ASC')
            ->get('buckets')
            ->result_array();
    }

    /**
     * listAll() + object count/total size per bucket, for the admin bucket
     * list/dashboard (docs/plans_v2.md section 7.2) — counts only current
     * (non-deleted, non-versioned-history) rows, matching listByPrefix().
     */
    public function withStats()
    {
        return $this->db->select(
                'buckets.id, buckets.name, buckets.created_at, buckets.is_public,
                 buckets.versioning_enabled,
                 COUNT(objects.id) AS object_count,
                 COALESCE(SUM(objects.size), 0) AS total_size',
                FALSE
            )
            ->from('buckets')
            ->join(
                'objects',
                'objects.bucket_id = buckets.id AND objects.is_deleted = 0 AND objects.version_id IS NULL',
                'left'
            )
            ->where('buckets.deleted_at', NULL)
            ->group_by('buckets.id')
            ->order_by('buckets.name', 'ASC')
            ->get()
            ->result_array();
    }

    /**
     * Hard-delete: the bucket `name` column is UNIQUE across the whole table,
     * so a soft-delete (leaving the row with deleted_at set) would permanently
     * block recreating a bucket with the same name afterwards.
     */
    public function delete($id)
    {
        $this->db->where('id', $id)->delete('buckets');
    }

    public function updatePolicy($id, array $fields)
    {
        $allowed = array('versioning_enabled', 'is_public', 'cors_config', 'notification_url', 'max_object_size', 'allowed_mime_types');
        $data = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $value = $fields[$key];
                if (in_array($key, array('cors_config', 'allowed_mime_types'), TRUE) && $value !== NULL) {
                    $value = json_encode($value);
                }
                $data[$key] = $value;
            }
        }
        if (empty($data)) {
            return FALSE;
        }
        return $this->db->where('id', $id)->update('buckets', $data);
    }
}
