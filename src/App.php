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

// Initialize services based on environment configuration
$services = initializeServices($env);

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
defineRoutes($app, $services);

/**
 * Initialize services based on environment configuration
 *
 * @param array<string, string> $env Environment variables
 * @return array<string, object> Service instances
 */
function initializeServices(array $env): array
{
    // Determine which storage backend to use
    $storageBackend = $env['STORAGE_BACKEND'] ?? 'json';
    $storageDir = $env['STORAGE_DIR'] ?? '/var/www/html/storage/uploads';

    if ($storageBackend === 'firestore') {
        $storageService = new \ImageUploadDemo\Implementations\FirestoreStorageService(
            $env['GCP_PROJECT_ID'] ?? null,
            $env['GCP_KEY_FILE'] ?? null,
            $env['FIRESTORE_COLLECTION'] ?? 'upload_records'
        );
    } else {
        // Default to JSON file storage
        $storageService = new \ImageUploadDemo\Implementations\JsonStorageService($storageDir);
    }

    // Determine whether to use real GCS or mock
    $useRealGcs = ($env['USE_REAL_GCS'] ?? 'false') === 'true';

    if ($useRealGcs) {
        $gcsService = new \ImageUploadDemo\Implementations\GcsServiceImpl(
            $env['GCP_PROJECT_ID'] ?? null,
            $env['GCP_KEY_FILE'] ?? null
        );
    } else {
        // Use mock GCS service for local development
        $gcsService = new class extends \ImageUploadDemo\Services\GcsService {
            public function generateSignedUrl(
                string $bucket,
                string $objectName,
                int $expirySeconds = 3600,
                string $method = 'PUT'
            ): string {
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
                error_log(sprintf('Mock: Would copy %s/%s to %s/%s', 
                    $sourceBucket, $sourceObject, $destBucket, $destObject));
                return true;
            }

            public function downloadObject(
                string $bucket,
                string $objectName,
                string $localPath
            ): bool {
                error_log(sprintf('Mock: Would download %s/%s to %s', 
                    $bucket, $objectName, $localPath));
                return true;
            }

            public function deleteObject(
                string $bucket,
                string $objectName
            ): bool {
                error_log(sprintf('Mock: Would delete %s/%s', $bucket, $objectName));
                return true;
            }
        };
    }

    $validationService = new \ImageUploadDemo\Services\ImageValidationService();
    $conversionService = new \ImageUploadDemo\Services\ImageConversionService();

    return [
        'storage' => $storageService,
        'gcs' => $gcsService,
        'validation' => $validationService,
        'conversion' => $conversionService,
        'uploadBucket' => $env['GCS_UPLOAD_BUCKET'] ?? $env['UPLOAD_BUCKET'] ?? 'uploads',
        'publicBucket' => $env['GCS_PUBLIC_BUCKET'] ?? $env['PUBLIC_BUCKET'] ?? 'public',
        'signedUrlExpiry' => (int) ($env['SIGNED_URL_EXPIRY'] ?? 300),
    ];
}

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
 *
 * @param \Slim\App $app Slim application instance
 * @param array<string, object> $services Service instances
 */
function defineRoutes(\Slim\App $app, array $services): void
{
    // Health check endpoint
    $app->get('/health', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Image upload request endpoint
    // Returns a GUID and signed URL for client to use when uploading image
    $app->post('/images/upload-request', function (Request $request, Response $response) use ($services) {
        $controller = new \ImageUploadDemo\Controllers\ImageUploadController(
            $services['storage'],
            $services['gcs'],
            $services['uploadBucket'],
            $services['signedUrlExpiry']
        );

        return $controller->uploadRequest($request, $response);
    });

    // Image status endpoint
    // Returns the current status of an upload
    $app->get('/images/{guid}/status', function (Request $request, Response $response, array $args) use ($services) {
        $controller = new \ImageUploadDemo\Controllers\ImageStatusController($services['storage']);
        return $controller->getStatus($request, $response, $args);
    });

    // Image event handler (from Pub/Sub)
    // Processes validation, conversion, and storage of uploaded images
    $app->post('/image-event', function (Request $request, Response $response) use ($services) {
        $controller = new \ImageUploadDemo\Controllers\ImageEventController(
            $services['storage'],
            $services['validation'],
            $services['conversion'],
            $services['gcs']
        );

        return $controller->handlePubSubEvent($request, $response);
    });
}

// Run the application
$app->run();
