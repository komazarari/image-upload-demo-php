<?php
/**
 * Test Bootstrap
 *
 * Initializes autoloading and test configuration.
 */

declare(strict_types=1);

// Load Composer autoloader
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Ensure environment variables are available
$envFile = __DIR__ . '/../.env.test';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Set test environment
$_ENV['APP_ENV'] = 'test';
