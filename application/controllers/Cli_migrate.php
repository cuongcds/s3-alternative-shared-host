<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CLI-only entry point that runs pending CodeIgniter migrations
 * (application/migrations/) up to the latest version. Invoked by
 * cli/migrate.php right after db/schema.sql is applied — see
 * docs/plans_v2.md section 2. Not reachable over HTTP.
 */
class Cli_migrate extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) {
            show_404();
        }
    }

    public function run()
    {
        $this->load->library('migration');
        if ($this->migration->latest() === FALSE) {
            fwrite(STDERR, "[cli_migrate] " . $this->migration->error_string() . "\n");
            exit(1);
        }
        fwrite(STDOUT, "[cli_migrate] migrations applied.\n");
        exit(0);
    }
}
