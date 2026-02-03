<?php

/*
|--------------------------------------------------------------------------
| Load Testing Environment
|--------------------------------------------------------------------------
|
| When running tests, load .env.testing to ensure the test database is used.
| This must happen before the Application is created.
|
*/

$basePath = $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__);

// Detect if we're running in PHPUnit or artisan test
$isRunningTests = defined('PHPUNIT_COMPOSER_INSTALL')
    || defined('__PHPUNIT_PHAR__')
    || (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === 'test');

if ($isRunningTests) {
    $testingEnvFile = $basePath . '/.env.testing';
    if (file_exists($testingEnvFile)) {
        // Parse .env.testing and set environment variables (only if not already set)
        $lines = file($testingEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // Skip comments
            if (strpos($line, '=') === false) continue;

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // Don't override if already set (e.g., by GitHub Actions)
            if (getenv($name) === false && !isset($_ENV[$name])) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application($basePath);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
