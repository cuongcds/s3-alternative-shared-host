<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Health extends CI_Controller
{
    public function index()
    {
        $this->output->set_content_type('application/json')->set_output(json_encode(array('status' => 'ok')));
    }
}
