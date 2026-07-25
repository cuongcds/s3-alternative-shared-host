<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Backing table for CI's Session `database` driver, used only by the admin
 * panel (see Admin_Controller) — schema matches CI3's documented DDL for the
 * database session driver exactly.
 */
class Migration_Create_ci_sessions_table extends CI_Migration
{
    public function up()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS ci_sessions (
  id VARCHAR(128) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  timestamp INT UNSIGNED NOT NULL DEFAULT 0,
  data BLOB NOT NULL,
  PRIMARY KEY (id),
  KEY ci_sessions_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS ci_sessions');
    }
}
