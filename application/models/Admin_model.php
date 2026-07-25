<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_model extends CI_Model
{
    const MAX_FAILED_ATTEMPTS = 5;
    const LOCKOUT_MINUTES = 15;

    public function __construct()
    {
        parent::__construct();
    }

    public function findByEmail($email)
    {
        return $this->db->get_where('admins', array('email' => $email))->row_array();
    }

    public function isLocked(array $admin)
    {
        return !empty($admin['locked_until']) && strtotime($admin['locked_until']) > time();
    }

    /**
     * @return bool TRUE if this failure just locked the account
     */
    public function recordFailedLogin($id)
    {
        $admin = $this->db->where('id', $id)->get('admins')->row_array();
        if (!$admin) {
            return FALSE;
        }
        $attempts = (int) $admin['failed_login_attempts'] + 1;
        $data = array('failed_login_attempts' => $attempts);
        $justLocked = $attempts >= self::MAX_FAILED_ATTEMPTS;
        if ($justLocked) {
            $data['failed_login_attempts'] = 0;
            $data['locked_until'] = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
        }
        $this->db->where('id', $id)->update('admins', $data);
        return $justLocked;
    }

    public function recordSuccessfulLogin($id)
    {
        $this->db->where('id', $id)->update('admins', array(
            'failed_login_attempts' => 0,
            'locked_until' => NULL,
            'last_login_at' => date('Y-m-d H:i:s'),
        ));
    }
}
