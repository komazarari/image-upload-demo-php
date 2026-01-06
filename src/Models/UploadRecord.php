<?php
/**
 * UploadRecord Model
 *
 * Represents a single image upload request lifecycle from initiation through
 * processing to completion or failure.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Models;

class UploadRecord
{
    /**
     * Unique identifier for this upload (UUID v4)
     */
    public string $guid;

    /**
     * Requester identifier
     */
    public string $userId;

    /**
     * Current state of upload (initialized, processing, completed, failed)
     */
    public string $status;

    /**
     * Original filename provided by client (for logging, nullable)
     */
    public ?string $originalFilename;

    /**
     * URL to processed image in public bucket (null until completed)
     */
    public ?string $publicUrl;

    /**
     * Timestamp when upload request was initiated (ISO 8601, UTC)
     */
    public string $createdAt;

    /**
     * Timestamp when image processing completed (null until completed)
     */
    public ?string $processedAt;

    /**
     * Human-readable error message if validation failed (null if successful)
     */
    public ?string $errorMessage;

    /**
     * Signed URL for client to upload image to (generated once, PUT only)
     */
    public string $signedUrl;

    /**
     * MIME type of uploaded image (e.g., image/jpeg, null until uploaded)
     */
    public ?string $contentType;

    /**
     * Size of uploaded file in bytes (null until uploaded)
     */
    public ?int $fileSize;

    /**
     * Image dimensions as array with width and height (null until processed)
     *
     * @var array{width: int, height: int}|null
     */
    public ?array $imageDimensions;

    /**
     * Constructor
     *
     * @param string $guid Unique identifier
     * @param string $userId Requester identifier
     * @param string $status Initial status (typically 'initialized')
     * @param string $signedUrl Signed URL for upload
     * @param string|null $originalFilename Optional original filename
     */
    public function __construct(
        string $guid,
        string $userId,
        string $status = 'initialized',
        string $signedUrl = '',
        ?string $originalFilename = null
    ) {
        $this->guid = $guid;
        $this->userId = $userId;
        $this->status = $status;
        $this->signedUrl = $signedUrl;
        $this->originalFilename = $originalFilename;
        $this->publicUrl = null;
        $this->createdAt = date('c');
        $this->processedAt = null;
        $this->errorMessage = null;
        $this->contentType = null;
        $this->fileSize = null;
        $this->imageDimensions = null;
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'guid' => $this->guid,
            'userId' => $this->userId,
            'status' => $this->status,
            'originalFilename' => $this->originalFilename,
            'publicUrl' => $this->publicUrl,
            'createdAt' => $this->createdAt,
            'processedAt' => $this->processedAt,
            'errorMessage' => $this->errorMessage,
            'signedUrl' => $this->signedUrl,
            'contentType' => $this->contentType,
            'fileSize' => $this->fileSize,
            'imageDimensions' => $this->imageDimensions,
        ];
    }

    /**
     * Create instance from array (e.g., from JSON)
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $record = new self(
            $data['guid'] ?? '',
            $data['userId'] ?? '',
            $data['status'] ?? 'initialized',
            $data['signedUrl'] ?? '',
            $data['originalFilename'] ?? null
        );

        $record->publicUrl = $data['publicUrl'] ?? null;
        $record->createdAt = $data['createdAt'] ?? date('c');
        $record->processedAt = $data['processedAt'] ?? null;
        $record->errorMessage = $data['errorMessage'] ?? null;
        $record->contentType = $data['contentType'] ?? null;
        $record->fileSize = $data['fileSize'] ?? null;
        $record->imageDimensions = $data['imageDimensions'] ?? null;

        return $record;
    }

    /**
     * Mark upload as processing
     */
    public function markProcessing(): void
    {
        $this->status = 'processing';
    }

    /**
     * Mark upload as completed with public URL
     */
    public function markCompleted(string $publicUrl): void
    {
        $this->status = 'completed';
        $this->publicUrl = $publicUrl;
        $this->processedAt = date('c');
    }

    /**
     * Mark upload as failed with error message
     */
    public function markFailed(string $errorMessage): void
    {
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
        $this->processedAt = date('c');
    }
}
