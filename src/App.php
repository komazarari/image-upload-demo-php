<?php
/**
 * Application Bootstrap
 *
 * Initializes the Slim Framework application with middleware,
 * routes, and dependency injection configuration.
 */

declare(strict_types=1);

namespace ImageUploadDemo;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Middleware\ErrorMiddleware;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env file
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos($line, '#') === 0) {
            continue;
        }
        // Parse key=value pairs
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }
} else {
    $env = [];
}

// Create Slim app
$app = AppFactory::create();

// Add error middleware
$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: ($env['APP_ENV'] ?? 'production') === 'development',
    logErrors: true,
    logErrorDetails: true
);

// Define middleware and routes
addMiddleware($app);
defineRoutes($app);

/**
 * Add middleware to the application
 */
function addMiddleware(\Slim\App $app): void
{
    // Add request logging middleware
    $app->add(function (Request $request, \Psr\Http\Server\RequestHandlerInterface $handler): Response {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        error_log(json_encode([
            'timestamp' => date('c'),
            'event' => 'request',
            'method' => $method,
            'path' => $path,
        ]));

        return $handler->handle($request);
    });
}

/**
 * Define application routes
 */
function defineRoutes(\Slim\App $app): void
{
    // Health check endpoint
    $app->get('/health', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // TODO: Add actual route handlers
    // - POST /images/upload-request → ImageUploadController::uploadRequest()
    // - POST /image-event → ImageEventController::handlePubSubEvent()
    // - POST /images/status → ImageStatusController::getStatus()
}

// Run the application
$app->run();
