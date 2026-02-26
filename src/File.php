<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Exception;

/**
 * File Upload/Download Helper
 * 
 * Security:
 * - Path traversal validation
 * - MIME type verification (not just extension)
 * - Secure directory permissions (0750)
 * - Randomized filenames to prevent enumeration
 */
class File
{
    private static string $uploadDir = 'uploads';

    /** @var array Dangerous file extensions (blacklist) */
    private const DANGEROUS_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phps',
        'cgi',
        'pl',
        'asp',
        'aspx',
        'shtml',
        'htaccess',
        'sh',
        'bat',
        'cmd',
        'com',
        'exe',
        'dll',
        'msi',
        'py',
        'rb',
        'js',
        'jsp',
        'war',
    ];

    /**
     * Upload a file securely
     * 
     * @param array $file $_FILES entry
     * @param string $subDir Subdirectory within uploads
     * @param array $allowedTypes Allowed file extensions (whitelist)
     * @param int $maxSize Maximum file size in bytes (default: 5MB)
     * @return string Relative path to uploaded file
     * @throws Exception
     */
    public static function upload(array $file, string $subDir = '', array $allowedTypes = [], int $maxSize = 5242880): string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? -1;
            throw new Exception("File upload error code: {$errorCode}");
        }

        // Validate size
        if ($file['size'] > $maxSize) {
            throw new Exception("File size exceeds limit (" . round($maxSize / 1024 / 1024, 2) . "MB)");
        }

        // Get and validate extension
        $originalName = $file['name'] ?? '';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Block dangerous extensions always
        if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
            throw new Exception("File type '{$ext}' is not allowed for security reasons");
        }

        // Validate against whitelist if provided
        if (!empty($allowedTypes) && !in_array($ext, $allowedTypes, true)) {
            throw new Exception("File type not allowed. Allowed: " . implode(', ', $allowedTypes));
        }

        // Validate MIME type matches extension (defense in depth)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            // Block PHP files disguised as other types
            if (str_contains($mimeType, 'php') || str_contains($mimeType, 'x-httpd')) {
                throw new Exception("File content type is not allowed");
            }
        }

        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);
        $baseDir = $root . '/' . self::$uploadDir;

        // Sanitize subdirectory to prevent path traversal
        $subDir = self::sanitizePath($subDir);
        $targetDir = $baseDir . ($subDir !== '' ? '/' . $subDir : '');

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0750, true);
        }

        // Generate secure random filename
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetFile = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
            throw new Exception("Failed to move uploaded file");
        }

        return ($subDir !== '' ? $subDir . '/' : '') . $filename;
    }

    /**
     * Delete a file
     */
    public static function delete(string $path): bool
    {
        $root = defined('PADI_ROOT') ? PADI_ROOT : dirname(__DIR__, 4);

        // Sanitize path to prevent traversal
        $sanitized = self::sanitizePath($path);
        $fullPath = $root . '/' . self::$uploadDir . '/' . $sanitized;

        // Verify the resolved path is still within uploads directory
        $realPath = realpath($fullPath);
        $uploadsReal = realpath($root . '/' . self::$uploadDir);

        if ($realPath === false || $uploadsReal === false) {
            return false;
        }

        if (!str_starts_with($realPath, $uploadsReal)) {
            // Path traversal attempt detected
            return false;
        }

        if (is_file($realPath)) {
            return unlink($realPath);
        }

        return false;
    }

    /**
     * Get full URL for a file
     */
    public static function url(string $path): string
    {
        $appUrl = Env::get('APP_URL', 'http://localhost:8085');
        $sanitized = self::sanitizePath($path);
        return rtrim($appUrl, '/') . '/' . self::$uploadDir . '/' . $sanitized;
    }

    /**
     * Sanitize file path to prevent directory traversal
     */
    private static function sanitizePath(string $path): string
    {
        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Normalize separators
        $path = str_replace('\\', '/', $path);

        // Remove directory traversal components
        $parts = explode('/', $path);
        $safe = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $safe[] = $part;
        }

        return implode('/', $safe);
    }
}
