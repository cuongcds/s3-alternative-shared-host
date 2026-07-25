<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Baseline migration: captures db/schema.sql (the 6 tables that existed
 * before migrations were adopted, see docs/plans_v2.md section 2) as-is —
 * this migration changes nothing, it only gives the `migrations` table a
 * starting point so every future schema change can go through CI Migration
 * instead of hand-editing db/schema.sql.
 *
 * For a DB that was already provisioned via db/schema.sql (any environment
 * running before this migration was introduced), running `up()` again is
 * harmless (every statement is CREATE TABLE IF NOT EXISTS) but the correct
 * step is to mark this version as already applied instead — see the
 * "Adopting migrations on an existing database" note in README.md.
 */
class Migration_Baseline_schema extends CI_Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS access_keys (
  id INT PRIMARY KEY AUTO_INCREMENT,
  access_key_id VARCHAR(64) UNIQUE NOT NULL,
  secret_access_key_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS buckets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(63) UNIQUE NOT NULL,
  region VARCHAR(32) NOT NULL DEFAULT 'us-east-1',
  versioning_enabled TINYINT(1) NOT NULL DEFAULT 0,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  cors_config JSON NULL,
  notification_url VARCHAR(255) NULL,
  max_object_size BIGINT NOT NULL DEFAULT 5368709120,
  allowed_mime_types JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS objects (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  bucket_id INT NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  version_id VARCHAR(64) NULL,
  size BIGINT NOT NULL,
  etag VARCHAR(64) NOT NULL,
  content_type VARCHAR(255) NULL,
  storage_path VARCHAR(1024) NOT NULL,
  metadata JSON NULL,
  storage_class VARCHAR(32) NOT NULL DEFAULT 'STANDARD',
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bucket_key (bucket_id, object_key(191)),
  CONSTRAINT fk_objects_bucket FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS multipart_uploads (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  upload_id VARCHAR(64) UNIQUE NOT NULL,
  bucket_id INT NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  content_type VARCHAR(255) NULL,
  status ENUM('in_progress','completed','aborted') NOT NULL DEFAULT 'in_progress',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mpu_bucket FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS multipart_parts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  upload_id VARCHAR(64) NOT NULL,
  part_number INT NOT NULL,
  etag VARCHAR(64) NOT NULL,
  size BIGINT NOT NULL,
  storage_path VARCHAR(1024) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_upload_part (upload_id, part_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS events (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  bucket_id INT NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  payload JSON NULL,
  status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(1024) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  action VARCHAR(64) NOT NULL,
  bucket VARCHAR(63) NULL,
  object_key VARCHAR(1024) NULL,
  ip VARCHAR(45) NULL,
  status_code INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        // Reverse FK dependency order.
        $this->db->query('DROP TABLE IF EXISTS multipart_parts');
        $this->db->query('DROP TABLE IF EXISTS multipart_uploads');
        $this->db->query('DROP TABLE IF EXISTS objects');
        $this->db->query('DROP TABLE IF EXISTS events');
        $this->db->query('DROP TABLE IF EXISTS audit_logs');
        $this->db->query('DROP TABLE IF EXISTS buckets');
        $this->db->query('DROP TABLE IF EXISTS access_keys');
    }
}
