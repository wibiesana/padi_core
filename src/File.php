<?php

namespace Wibiesana\Padi\Core;

use Exception;

class File
{
    private static string $uploadDir = 'uploads';

    /**
     * Upload a file
     */
    public static function upload(array $file, string $subDir = '', array $allowedTypes = [], int $maxSize = 5242880): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error code: " . $file['error']);
        }

        // Validate size
        if ($file['size'] > $maxSize) {
            throw new Exception("File size exceeds limit (" . round($maxSize / 1024 / 1024, 2) . "MB)");
        }

        // Validate type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!empty($allowedTypes) && !in_array($ext, $allowedTypes)) {
            throw new Exception("File type not allowed. Allowed: " . implode(', ', $allowedTypes));
        }

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $baseDir = $root . '/' . self::$uploadDir;
        $targetDir = $baseDir . ($subDir ? '/' . trim($subDir, '/') : '');

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $filename = uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetFile = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            throw new Exception("Failed to move uploaded file");
        }

        return ($subDir ? trim($subDir, '/') . '/' : '') . $filename;
    }

    /**
     * Delete a file
     */
    public static function delete(string $path): bool
    {
        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $fullPath = $root . '/' . self::$uploadDir . '/' . ltrim($path, '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    /**
     * Get full URL for a file
     */
    public static function url(string $path): string
    {
        $appUrl = Env::get('APP_URL', 'http://localhost:8085');
        return rtrim($appUrl, '/') . '/' . self::$uploadDir . '/' . ltrim($path, '/');
    }
}
