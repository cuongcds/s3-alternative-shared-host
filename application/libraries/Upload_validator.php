<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Upload_validator
{
    public function validateSize($size, $maxSize)
    {
        return $size <= $maxSize;
    }

    /**
     * @param string $mime
     * @param array|null $allowed NULL means "no restriction"
     */
    public function validateMime($mime, $allowed)
    {
        if (empty($allowed)) {
            return TRUE;
        }
        return in_array($mime, $allowed, TRUE);
    }
}
