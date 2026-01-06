<?php
/**
 * Upload Status Enumeration
 *
 * Represents the lifecycle states of an image upload.
 * Using PHP 8.1+ Enum for type safety and clarity.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Enums;

enum UploadStatus: string
{
    case Initialized = 'initialized';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Check if status is terminal (no further transitions possible)
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Check if status allows transition to processing
     */
    public function canTransitionToProcessing(): bool
    {
        return $this === self::Initialized;
    }

    /**
     * Check if status allows transition to completed
     */
    public function canTransitionToCompleted(): bool
    {
        return $this === self::Processing;
    }

    /**
     * Check if status allows transition to failed
     */
    public function canTransitionToFailed(): bool
    {
        return $this === self::Initialized || $this === self::Processing;
    }
}
