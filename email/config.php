<?php
declare(strict_types=1);

/*
 * BrainPal SMTP configuration.
 *
 * Loads values from ../.env when available.
 * Never hard-code the Gmail App Password in this file.
 */

$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

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

        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            putenv($key . '=' . $value);
        }
    }
}

return [
  'host' => getenv('BP_SMTP_HOST') ?: 'smtp.gmail.com',
  'port' => (int)(getenv('BP_SMTP_PORT') ?: 587),
  'username' => getenv('BP_SMTP_USERNAME') ?: 'tbrainpal@gmail.com',
  'password' => str_replace(' ', '', (string)(getenv('BP_SMTP_PASSWORD') ?: '')),
  'encryption' => getenv('BP_SMTP_ENCRYPTION') ?: 'tls',
  'from_email' => getenv('BP_SMTP_FROM') ?: 'tbrainpal@gmail.com',
  'from_name' => getenv('BP_SMTP_FROM_NAME') ?: 'BrainPal Team',
];
