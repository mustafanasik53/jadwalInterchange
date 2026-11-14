<?php
/**
 * Tiny .env parser — no phpdotenv dependency.
 *
 * Loads KEY=VALUE pairs from a .env file (same directory as this project's
 * root, i.e. one level up from src/) into getenv()/$_ENV/$_SERVER so the rest
 * of the app can just call env('SUPABASE_URL') etc.
 *
 * Supported syntax:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='value with spaces'
 *   # comment lines and blank lines are ignored
 */

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip matching surrounding quotes.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($key === '') {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

/**
 * Read an environment variable with an optional default.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

// Load the .env file that sits at the project root (one directory above src/).
load_env(dirname(__DIR__) . '/.env');
