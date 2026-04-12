<?php

function panicParseEnvValue(string $rawValue): string {
    $value = trim($rawValue);
    if ($value === '') {
        return '';
    }

    $first = $value[0];
    $last = $value[strlen($value) - 1];

    if (($first === '"' || $first === "'") && $last === $first && strlen($value) >= 2) {
        $inner = substr($value, 1, -1);
        if ($first === '"') {
            $inner = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $inner);
        }
        return $inner;
    }

    $hashPos = strpos($value, ' #');
    if ($hashPos !== false) {
        $value = substr($value, 0, $hashPos);
    }

    return trim($value);
}

function panicLoadEnvFiles(?array $paths = null): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $defaultPaths = [
        __DIR__ . '/../.env',
        __DIR__ . '/../.env.local',
    ];

    $paths = $paths ?: $defaultPaths;

    foreach ($paths as $path) {
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            if (getenv($key) !== false) {
                continue;
            }

            $rawValue = substr($trimmed, $eqPos + 1);
            $value = panicParseEnvValue($rawValue);

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
