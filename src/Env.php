<?php

declare(strict_types=1);

namespace Core;

class Env
{
    private static bool $loaded = false;

    /**
     * Load .env file
     */
    public static function load(string $path): void
    {
        if (self::$loaded || !file_exists($path)) {
            return;
        }

        self::$loaded = true;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse line
            if (strpos($line, '=') !== false) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Remove inline comments
                if (strpos($value, '#') !== false) {
                    $value = substr($value, 0, strpos($value, '#'));
                    $value = trim($value);
                }

                // Remove quotes
                $value = trim($value, '"\'');

                // Set environment variable
                if (!array_key_exists($name, $_ENV)) {
                    $_ENV[$name] = $value;
                    putenv("{$name}={$value}");
                }
            }
        }
    }

    /**
     * Get environment variable
     */
    public static function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
