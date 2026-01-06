<?php
/**
 * ImageValidation Model
 *
 * Represents the result of image validation process including MIME type,
 * dimensions, file size, and any validation errors.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Models;

class ImageValidation
{
    /**
     * Whether image passed all validation checks
     */
    public bool $isValid;

    /**
     * Detected MIME type (e.g., image/jpeg)
     */
    public ?string $mimeType;

    /**
     * Image width in pixels
     */
    public ?int $width;

    /**
     * Image height in pixels
     */
    public ?int $height;

    /**
     * File size in bytes
     */
    public ?int $fileSize;

    /**
     * Array of validation error messages
     *
     * @var string[]
     */
    public array $errors;

    /**
     * Whether image was converted/reencoded during validation
     */
    public bool $conversionApplied;

    /**
     * Error messages from conversion attempt (if any)
     *
     * @var string[]
     */
    public array $conversionErrors;

    /**
     * Constructor
     *
     * @param bool $isValid Whether validation passed
     * @param string|null $mimeType Detected MIME type
     * @param int|null $width Image width
     * @param int|null $height Image height
     * @param int|null $fileSize File size in bytes
     * @param string[] $errors Validation errors
     * @param bool $conversionApplied Whether image was converted
     * @param string[] $conversionErrors Conversion errors
     */
    public function __construct(
        bool $isValid = false,
        ?string $mimeType = null,
        ?int $width = null,
        ?int $height = null,
        ?int $fileSize = null,
        array $errors = [],
        bool $conversionApplied = false,
        array $conversionErrors = []
    ) {
        $this->isValid = $isValid;
        $this->mimeType = $mimeType;
        $this->width = $width;
        $this->height = $height;
        $this->fileSize = $fileSize;
        $this->errors = $errors;
        $this->conversionApplied = $conversionApplied;
        $this->conversionErrors = $conversionErrors;
    }

    /**
     * Add validation error
     */
    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->isValid = false;
    }

    /**
     * Add conversion error
     */
    public function addConversionError(string $error): void
    {
        $this->conversionErrors[] = $error;
    }

    /**
     * Get all error messages combined
     *
     * @return string[]
     */
    public function getAllErrors(): array
    {
        return array_merge($this->errors, $this->conversionErrors);
    }

    /**
     * Convert to array for logging
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'isValid' => $this->isValid,
            'mimeType' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'fileSize' => $this->fileSize,
            'errors' => $this->errors,
            'conversionApplied' => $this->conversionApplied,
            'conversionErrors' => $this->conversionErrors,
        ];
    }
}
