<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Exception;

/**
 * File Upload/Download Helper
 *
 * ## Storage Best Practice — "Relative Path in DB, Full URL in Response"
 *
 * File::upload() returns a RELATIVE path (e.g. "images/abc123.jpg").
 * ALWAYS store this relative path in the database — never the full URL.
 *
 *   ✅ DB column: "images/abc123.jpg"
 *   ❌ DB column: "http://192.168.1.5:8085/uploads/images/abc123.jpg"
 *
 * When you need to expose the URL in an API response, call File::url():
 *   File::url('images/abc123.jpg')
 *   → "https://api.example.com/uploads/images/abc123.jpg"
 *
 * This way the URL is always built from the CURRENT request, so the
 * application works on any server (dev, staging, production) without
 * any configuration change.
 *
 * The recommended pattern is to transform paths inside afterLoad():
 *
 *   class Product extends ActiveRecord {
 *       public function afterLoad(array &$items): void {
 *           foreach ($items as &$item) {
 *               if (!empty($item['photo'])) {
 *                   $item['photo_url'] = File::url($item['photo']);
 *               }
 *           }
 *       }
 *   }
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
     *
     * Base URL resolution order (first non-empty wins):
     *  1. APP_URL env  — explicit override (useful behind reverse proxies / CDN).
     *     Leave APP_URL empty (or remove it) to enable full auto-detection.
     *  2. Current HTTP request — scheme + host auto-detected so the URL always
     *     matches whichever server is handling the request.
     *  3. 'http://localhost' — last-resort fallback for CLI / test contexts.
     *
     * This means uploaded files remain accessible after moving to a new server
     * without touching any configuration.
     */
    public static function url(string $path): string
    {
        // If the stored value is already a full URL (e.g. from legacy data), return as-is
        if (self::isAbsoluteUrl($path)) {
            return $path;
        }

        $baseUrl   = self::resolveBaseUrl();
        $sanitized = self::sanitizePath($path);
        return rtrim($baseUrl, '/') . '/' . self::$uploadDir . '/' . $sanitized;
    }

    /**
     * Get file URL, or null if path is empty/null.
     *
     * Safe to use directly on nullable DB columns:
     *
     *   $item['photo_url'] = File::urlOrNull($item['photo'] ?? null);
     *   // Returns null when photo column is empty — no broken URL
     */
    public static function urlOrNull(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        return self::url($path);
    }

    /**
     * Check whether a string is already a fully-qualified URL.
     *
     * Useful in afterLoad() to skip re-prefixing values that were accidentally
     * stored as full URLs in legacy data:
     *
     *   if (!File::isAbsoluteUrl($item['photo'])) {
     *       $item['photo'] = File::url($item['photo']);
     *   }
     */
    public static function isAbsoluteUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * Resolve the application base URL.
     *
     * When APP_URL is set in .env it is returned as-is so operators can
     * explicitly pin the public URL (e.g. behind a CDN or custom domain).
     * When APP_URL is absent or empty the URL is built from the live request,
     * so the API stays portable across servers without any configuration changes.
     */
    private static function resolveBaseUrl(): string
    {
        // 1. Explicit override — respect what the operator configured
        $configured = Env::get('APP_URL', '');
        if ($configured !== '' && $configured !== null) {
            return rtrim((string)$configured, '/');
        }

        // 2. Auto-detect from the current HTTP request
        if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
            // Determine scheme — check common reverse-proxy / load-balancer headers first
            $isHttps =
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

            $scheme = $isHttps ? 'https' : 'http';

            // HTTP_HOST already includes the port when it is non-standard
            return $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        // 3. Last-resort fallback (CLI, tests, etc.)
        return 'http://localhost';
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
