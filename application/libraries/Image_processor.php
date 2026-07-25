<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GD-based resize/thumbnail helper. Used synchronously by the backend-upload
 * pipeline (usecase 2) and asynchronously by the worker (usecase 3).
 * Every method is best-effort: returns NULL on any unsupported/broken input
 * instead of throwing, since thumbnailing must never fail the actual upload.
 */
class Image_processor
{
    public function isImage($mime)
    {
        return strpos((string) $mime, 'image/') === 0;
    }

    /**
     * Resize an image (by absolute path) down to fit within $maxDim x $maxDim,
     * preserving aspect ratio, and return JPEG bytes — or NULL on failure.
     */
    public function thumbnail($srcPath, $maxDim = 256)
    {
        if (!is_file($srcPath)) {
            return NULL;
        }

        $info = @getimagesize($srcPath);
        if (!$info) {
            return NULL;
        }
        list($width, $height, $type) = $info;

        $src = $this->createFromType($srcPath, $type);
        if (!$src) {
            return NULL;
        }

        $ratio = min($maxDim / $width, $maxDim / $height, 1);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($dst, NULL, 82);
        $bytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $bytes === FALSE ? NULL : $bytes;
    }

    protected function createFromType($path, $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return @imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return @imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : NULL;
            default:
                return NULL;
        }
    }
}
