<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Short-term (~3h) bucket-scoped tokens for delegated presigned-URL issuance
 * (docs/plans_v2.md section 7.2 follow-up) — lets a bucket owner hand a less
 * trusted client (e.g. a browser session) something that can call
 * `POST /internal/presign-with-token` without ever seeing the real
 * ACCESS_KEY_ID/SECRET_ACCESS_KEY. Gated per-bucket by
 * `buckets.allow_short_term_tokens` so this stays opt-in.
 *
 * `token_hash` (not the raw token) is stored, same convention as
 * `access_keys.secret_access_key_hash` in the baseline schema.
 */
class Migration_Create_access_tokens_table extends CI_Migration
{
    public function up()
    {
        $this->db->query("ALTER TABLE buckets
  ADD COLUMN allow_short_term_tokens TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public");

        $this->db->query("CREATE TABLE IF NOT EXISTS access_tokens (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  bucket_id INT NOT NULL,
  token_hash VARCHAR(64) UNIQUE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  KEY idx_bucket (bucket_id),
  CONSTRAINT fk_access_tokens_bucket FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS access_tokens');
        $this->db->query('ALTER TABLE buckets DROP COLUMN allow_short_term_tokens');
    }
}
