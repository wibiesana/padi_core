<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Env - Environment Variable Loader
 *
 * Loads .env files once and caches values in $_ENV.
 * Worker-mode safe: loaded once during bootstrap, reused across requests.
 * Shared hosting safe: no external dependencies required.
 */
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
            $trimmed = ltrim($line);

            // Skip comments  
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            // Parse name=value
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));

            // Remove inline comments (only when not inside quotes)
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $hashPos = strpos($value, ' #');
                if ($hashPos !== false) {
                    $value = rtrim(substr($value, 0, $hashPos));
                }
            }

            // Remove surrounding quotes
            $valueLen = strlen($value);
            if ($valueLen >= 2) {
                $first = $value[0];
                $last = $value[$valueLen - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, $valueLen - 2);
                }
            }

            // Only set if not already defined (system env takes precedence)
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }

    /**
     * Get environment variable
     * 
     * Priority: $_ENV > getenv() > $default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }

        return $default;
    }
}
