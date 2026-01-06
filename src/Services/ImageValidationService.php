<?php
/**
 * Image Validation Service
 *
 * Handles validation of uploaded images including MIME type, dimensions,
 * file size, and magic bytes verification.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Services;

use ImageUploadDemo\Models\ImageValidation;

class ImageValidationService
{
    /**
     * Allowed MIME types (whitelist)
     *
     * @var string[]
     */
    private array $allowedMimeTypes;

    /**
     * Maximum image width in pixels
     */
    private int $maxWidth;

    /**
     * Maximum image height in pixels
     */
    private int $maxHeight;

    /**
     * Maximum file size in bytes
     */
    private int $maxFileSize;

    /**
     * Constructor
     *
     * @param string[] $allowedMimeTypes Whitelist of allowed MIME types
     * @param int $maxWidth Maximum image width
     * @param int $maxHeight Maximum image height
     * @param int $maxFileSize Maximum file size in bytes
     */
    public function __construct(
        array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        int $maxWidth = 4000,
        int $maxHeight = 3000,
        int $maxFileSize = 5242880
    ) {
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->maxFileSize = $maxFileSize;
    }

    /**
     * Validate image file
     *
     * Checks MIME type, dimensions, file size, and magic bytes.
     *
     * @param string $filePath Path to uploaded file
     * @param string $mimeType MIME type from Pub/Sub or upload metadata
     * @return ImageValidation Validation result
     */
    public function validate(string $filePath, string $mimeType = ''): ImageValidation
    {
        $validation = new ImageValidation();

        // Check file exists
        if (!file_exists($filePath)) {
            $validation->addError('File not found: ' . $filePath);
            return $validation;
        }

        // Get file size
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            $validation->addError('Unable to determine file size');
            return $validation;
        }
        $validation->fileSize = $fileSize;

        // Validate file size
        if (!$this->validateFileSize($fileSize, $validation)) {
            return $validation;
        }

        // Detect actual MIME type and dimensions using getimagesize()
        $imageInfo = getimagesize($filePath);
        if ($imageInfo === false) {
            $validation->addError('File is not a valid image (getimagesize() failed)');
            return $validation;
        }

        $validation->width = $imageInfo[0];
        $validation->height = $imageInfo[1];
        $detectedMimeType = $imageInfo['mime'] ?? '';

        // If MIME type provided, verify it matches detected type
        if (!empty($mimeType)) {
            $validation->mimeType = $mimeType;
            if ($mimeType !== $detectedMimeType) {
                $validation->addError(
                    sprintf(
                        'MIME type mismatch: claimed %s but detected %s',
                        $mimeType,
                        $detectedMimeType
                    )
                );
            }
        } else {
            $validation->mimeType = $detectedMimeType;
        }

        // Validate MIME type whitelist
        if (!$this->validateMimeType($validation->mimeType, $validation)) {
            return $validation;
        }

        // Validate image dimensions
        if (!$this->validateDimensions($validation->width, $validation->height, $validation)) {
            return $validation;
        }

        // All checks passed
        $validation->isValid = true;
        return $validation;
    }

    /**
     * Validate MIME type against whitelist
     *
     * @param string $mimeType MIME type to validate
     * @param ImageValidation $validation Validation object to update
     * @return bool True if valid, false otherwise
     */
    private function validateMimeType(string $mimeType, ImageValidation $validation): bool
    {
        if (!in_array($mimeType, $this->allowedMimeTypes, true)) {
            $validation->addError(
                sprintf(
                    'MIME type not allowed: %s (allowed: %s)',
                    $mimeType,
                    implode(', ', $this->allowedMimeTypes)
                )
            );
            return false;
        }
        return true;
    }

    /**
     * Validate image dimensions
     *
     * @param int $width Image width in pixels
     * @param int $height Image height in pixels
     * @param ImageValidation $validation Validation object to update
     * @return bool True if valid, false otherwise
     */
    private function validateDimensions(int $width, int $height, ImageValidation $validation): bool
    {
        if ($width > $this->maxWidth || $height > $this->maxHeight) {
            $validation->addError(
                sprintf(
                    'Image dimensions exceed maximum: %dx%d (max: %dx%d)',
                    $width,
                    $height,
                    $this->maxWidth,
                    $this->maxHeight
                )
            );
            return false;
        }
        return true;
    }

    /**
     * Validate file size
     *
     * @param int $fileSize File size in bytes
     * @param ImageValidation $validation Validation object to update
     * @return bool True if valid, false otherwise
     */
    private function validateFileSize(int $fileSize, ImageValidation $validation): bool
    {
        if ($fileSize > $this->maxFileSize) {
            $validation->addError(
                sprintf(
                    'File size exceeds maximum: %d bytes (max: %d bytes, %.2f MB)',
                    $fileSize,
                    $this->maxFileSize,
                    $this->maxFileSize / 1024 / 1024
                )
            );
            return false;
        }
        if ($fileSize === 0) {
            $validation->addError('File size is 0 bytes');
            return false;
        }
        return true;
    }

    /**
     * Get allowed MIME types
     *
     * @return string[]
     */
    public function getAllowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }
}
