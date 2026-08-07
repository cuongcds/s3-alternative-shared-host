<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Browser-accessible admin-account creation for deploy option 3 (shared
 * hosting — no SSH/CLI access assumed, so cli/create_admin.php can't be run
 * directly). Same pattern as Cronjobs.php: not S3/OS3-signed (a plain GET
 * from a browser can't compute a request signature), gated by a shared
 * secret instead — reuses the existing SECRET_ACCESS_KEY rather than adding
 * yet another one to configure.
 *
 *   https://your-domain/setup/create-admin?secret=<SECRET_ACCESS_KEY>&email=admin@example.com&password=a-strong-password
 *
 * Idempotent by email (updates the password if it already exists), matching
 * cli/create_admin.php. Consider restricting or removing this route once
 * you've created your admin account(s) — it's gated by the same secret as
 * every other privileged action in this app, but there's no reason to leave
 * it reachable indefinitely.
 */
class Setup extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->config('s3', TRUE);
        $this->load->database();
    }

    public function create_admin()
    {
        $expected = (string) $this->config->item('s3_secret_access_key', 's3');
        $given = (string) $this->input->get_post('secret');

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $this->output->set_status_header(403);
            $this->output->set_content_type('text/plain')->set_output('Forbidden');
            return;
        }

        $email = trim((string) $this->input->get_post('email'));
        $password = (string) $this->input->get_post('password');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->output->set_status_header(400);
            $this->output->set_content_type('text/plain')->set_output('Invalid or missing "email" parameter');
            return;
        }
        if (strlen($password) < 8) {
            $this->output->set_status_header(400);
            $this->output->set_content_type('text/plain')->set_output('"password" parameter must be at least 8 characters');
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $this->db->get_where('admins', array('email' => $email))->row_array();

        if ($existing) {
            $this->db->where('id', $existing['id'])->update('admins', array(
                'password_hash' => $hash,
                'is_active' => 1,
                'failed_login_attempts' => 0,
                'locked_until' => NULL,
            ));
        } else {
            $this->db->insert('admins', array(
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT);,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ));
        }

        $this->output->set_content_type('text/plain')->set_output(
            'Admin "' . $email . '" created/updated. Log in at /admin/login. '
            . 'Consider restricting or removing this endpoint now.'
        );
    }
}
