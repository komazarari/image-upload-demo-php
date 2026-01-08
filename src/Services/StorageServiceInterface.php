<?php
/**
 * Storage Service Interface
 *
 * Abstract interface for persisting UploadRecord instances.
 * Allows different storage backends (JSON files, Firestore, SQL, etc.)
 */

declare(strict_types=1);

namespace ImageUploadDemo\Services;

use ImageUploadDemo\Models\UploadRecord;

abstract class StorageServiceInterface
{
    /**
     * Save UploadRecord
     *
     * @param UploadRecord $record Upload record to save
     * @return bool True if successful
     */
    abstract public function save(UploadRecord $record): bool;

    /**
     * Load UploadRecord by GUID
     *
     * @param string $guid Upload record GUID
     * @return UploadRecord|null Upload record if found, null otherwise
     */
    abstract public function load(string $guid): ?UploadRecord;

    /**
     * Check if record exists
     *
     * @param string $guid Upload record GUID
     * @return bool True if record exists
     */
    abstract public function exists(string $guid): bool;

    /**
     * Delete record
     *
     * @param string $guid Upload record GUID
     * @return bool True if successful
     */
    abstract public function delete(string $guid): bool;

    /**
     * Get all record GUIDs
     *
     * @return string[] Array of GUIDs
     */
    abstract public function getAllGuids(): array;
}
