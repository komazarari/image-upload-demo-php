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

    // Image upload request endpoint
    // Returns a GUID and signed URL for client to use when uploading image
    $app->post('/images/upload-request', function (Request $request, Response $response) {
        // Create minimal mock GcsService for now
        $mockGcsService = new class extends \ImageUploadDemo\Services\GcsService {
            public function generateSignedUrl(
                string $bucket,
                string $objectName,
                int $expirySeconds = 3600,
                string $method = 'PUT'
            ): string {
                // Mock implementation - returns placeholder URL
                return sprintf(
                    'https://storage.googleapis.com/upload/%s?signature=mock_token',
                    urlencode($objectName)
                );
            }

            public function copyObject(
                string $sourceBucket,
                string $sourceObject,
                string $destBucket,
                string $destObject
            ): bool {
                return false;
            }

            public function downloadObject(
                string $bucket,
                string $objectName,
                string $localPath
            ): bool {
                return false;
            }

            public function deleteObject(
                string $bucket,
                string $objectName
            ): bool {
                return false;
            }
        };

        $storageService = new \ImageUploadDemo\Services\StorageService();
        $controller = new \ImageUploadDemo\Controllers\ImageUploadController(
            $storageService,
            $mockGcsService
        );

        return $controller->uploadRequest($request, $response);
    });

    // Image status endpoint
    // Returns the current status of an upload
    $app->get('/images/{guid}/status', function (Request $request, Response $response, array $args) {
        $storageService = new \ImageUploadDemo\Services\StorageService();
        $controller = new \ImageUploadDemo\Controllers\ImageStatusController($storageService);
        return $controller->getStatus($request, $response, $args);
    });

    // TODO: Add actual route handlers
    // - POST /image-event → ImageEventController::handlePubSubEvent()
}

// Run the application
$app->run();
