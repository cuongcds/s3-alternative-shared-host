<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Short-term bucket-scoped tokens (see migration 20260101000300) used to
 * authorize `POST /internal/presign-with-token` without the real
 * ACCESS_KEY_ID/SECRET_ACCESS_KEY — see application/controllers/Internal.php.
 */
class Access_token_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return array{token: string, expires_at: string} the raw token is only
     *   ever returned here — the DB stores its hash, so callers must show it
     *   to the user immediately, it cannot be recovered afterwards.
     */
    public function create($bucketId, $ttlSeconds)
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (int) $ttlSeconds);

        $this->db->insert('access_tokens', array(
            'bucket_id' => $bucketId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ));

        return array('token' => $token, 'expires_at' => $expiresAt);
    }

    /**
     * Looks up a raw token (as presented by a client) and, if it's valid
     * (not revoked, not expired), returns the token row joined with its
     * bucket. Returns NULL for anything invalid — callers must not
     * distinguish "not found" from "expired"/"revoked" in error responses.
     */
    public function findValidByToken($token)
    {
        $hash = hash('sha256', (string) $token);
        return $this->db->select('access_tokens.id, access_tokens.bucket_id, access_tokens.expires_at,
                 buckets.name AS bucket_name, buckets.allow_short_term_tokens', FALSE)
            ->from('access_tokens')
            ->join('buckets', 'buckets.id = access_tokens.bucket_id')
            ->where('access_tokens.token_hash', $hash)
            ->where('access_tokens.revoked_at', NULL)
            ->where('access_tokens.expires_at >', date('Y-m-d H:i:s'))
            ->where('buckets.deleted_at', NULL)
            ->get()
            ->row_array();
    }

    public function listActiveByBucket($bucketId)
    {
        return $this->db->select('id, created_at, expires_at')
            ->where('bucket_id', $bucketId)
            ->where('revoked_at', NULL)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->order_by('created_at', 'DESC')
            ->get('access_tokens')
            ->result_array();
    }

    /**
     * Scoped to $bucketId so a revoke request can't touch another bucket's
     * token by guessing its id.
     */
    public function revoke($id, $bucketId)
    {
        return $this->db->where('id', $id)
            ->where('bucket_id', $bucketId)
            ->update('access_tokens', array('revoked_at' => date('Y-m-d H:i:s')));
    }
}
