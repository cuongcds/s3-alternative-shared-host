<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controller for every /admin/* page (docs/plans_v2.md). Entirely
 * separate auth path from MY_Controller (the S3/internal API base): session
 * cookie + email/password instead of SigV4/OS3-HMAC, own CSRF handling
 * scoped to just this controller tree (see verifyCsrf()), no shared code
 * with the API auth on purpose.
 *
 * Mirrors MY_Controller's convention of NOT auto-enforcing anything in the
 * constructor — concrete controllers call requireLogin()/verifyCsrf()
 * explicitly where needed (Auth::login/logout intentionally don't).
 */
class Admin_Controller extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->config('s3', TRUE);
        $this->load->database();
        $this->load->helper(array('url', 'form', 's3'));
        $this->configureAdminSession();
        $this->load->library('session');
    }

    protected function configureAdminSession()
    {
        // Own cookie name + database driver so admin login survives PHP-FPM
        // worker/container restarts and never collides with any other
        // service on the same host — see docs/plans_v2.md section 5.1.
        $this->config->set_item('sess_driver', 'database');
        $this->config->set_item('sess_save_path', 'ci_sessions');
        $this->config->set_item('sess_cookie_name', 's3_admin_session');
        $this->config->set_item('sess_match_ip', FALSE);
        $this->config->set_item('sess_time_to_update', 300);
        $this->config->set_item('sess_expiration', 7200);
    }

    protected function request_method()
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    protected function requireLogin()
    {
        if (!$this->session->userdata('admin_id')) {
            redirect('admin/login');
        }
    }

    protected function currentAdminEmail()
    {
        return $this->session->userdata('admin_email');
    }

    /**
     * Token is generated once per session (not regenerated per-request) so
     * a user with several admin tabs open doesn't get a stale-token 403 in
     * whichever tab they submit second — reset only on next login.
     */
    protected function csrfToken()
    {
        $token = $this->session->userdata('admin_csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $this->session->set_userdata('admin_csrf_token', $token);
        }
        return $token;
    }

    /**
     * Call at the top of every POST/PUT/DELETE admin action. Scoped entirely
     * to this controller tree instead of CI's global csrf_protection config
     * — that would apply to every POST in the app, requiring an exclude list
     * for every SigV4-signed S3 API route (which can't carry a form token).
     */
    protected function verifyCsrf()
    {
        $token = (string) $this->input->post('csrf_token');
        $expected = (string) $this->session->userdata('admin_csrf_token');
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            $this->session->sess_destroy();
            show_error('Invalid or missing CSRF token. Please log in again.', 403, 'Access Denied');
        }
    }

    /**
     * Renders $view wrapped in the shared sidebar/header layout. Login page
     * renders standalone instead (see Auth::login), it has no sidebar.
     */
    protected function render($view, array $data = array())
    {
        $data['csrf_token'] = $this->csrfToken();
        $data['admin_email'] = $this->currentAdminEmail();
        $data['flash_error'] = $this->session->flashdata('flash_error');
        $data['flash_success'] = $this->session->flashdata('flash_success');
        $data['current_uri'] = uri_string();

        $content = $this->load->view($view, $data, TRUE);
        $data['content'] = $content;
        $this->load->view('admin/layout', $data);
    }
}
