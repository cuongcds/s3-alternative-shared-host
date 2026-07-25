<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Admin_Controller isn't named "MY_*", so CI3's core-extension autoloading
// (which only recognizes application/core/MY_<CoreClass>.php) doesn't pick
// it up — every controller under controllers/admin/ requires it explicitly.
require_once APPPATH . 'core/Admin_Controller.php';

/**
 * Admin login/logout (docs/plans_v2.md section 5.2). Deliberately does NOT
 * call requireLogin() on login() (that would redirect-loop) — every other
 * controller under controllers/admin/ does.
 */
class Auth extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('admin_model');
    }

    public function login()
    {
        if ($this->session->userdata('admin_id')) {
            redirect('admin');
        }

        if ($this->request_method() === 'POST') {
            $this->verifyCsrf();

            $email = trim((string) $this->input->post('email'));
            $password = (string) $this->input->post('password');
            $admin = $email !== '' ? $this->admin_model->findByEmail($email) : NULL;

            if (!$admin || !(int) $admin['is_active']) {
                $this->session->set_flashdata('flash_error', 'Invalid email or password.');
                redirect('admin/login');
            }

            if ($this->admin_model->isLocked($admin)) {
                $this->session->set_flashdata('flash_error', 'Too many failed attempts. Try again in a few minutes.');
                redirect('admin/login');
            }

            if (!password_verify($password, $admin['password_hash'])) {
                $justLocked = $this->admin_model->recordFailedLogin($admin['id']);
                $this->session->set_flashdata('flash_error', $justLocked
                    ? 'Too many failed attempts. Account locked for ' . Admin_model::LOCKOUT_MINUTES . ' minutes.'
                    : 'Invalid email or password.');
                redirect('admin/login');
            }

            $this->admin_model->recordSuccessfulLogin($admin['id']);
            // New session id on privilege change — mitigates session fixation.
            $this->session->sess_regenerate(TRUE);
            $this->session->set_userdata(array(
                'admin_id' => $admin['id'],
                'admin_email' => $admin['email'],
            ));
            redirect('admin');
        }

        $data = array(
            'csrf_token' => $this->csrfToken(),
            'flash_error' => $this->session->flashdata('flash_error'),
        );
        $this->load->view('admin/login', $data);
    }

    public function logout()
    {
        if ($this->request_method() !== 'POST') {
            $this->output->set_status_header(405);
            $this->output->set_output('Method Not Allowed');
            return;
        }
        $this->verifyCsrf();
        $this->session->sess_destroy();
        redirect('admin/login');
    }
}
