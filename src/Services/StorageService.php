<?php
/**
 * Storage Service
 *
 * Persists UploadRecord instances to local JSON files for demo purposes.
 * In production, this would be replaced with Firestore or another database.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Services;

use ImageUploadDemo\Models\UploadRecord;

class StorageService
{
    /**
     * Base directory for storing upload records
     */
    private string $storageDir;

    /**
     * Constructor
     *
     * @param string $storageDir Base directory for storing records
     */
    public function __construct(string $storageDir = '/var/www/html/storage/uploads')
    {
        $this->storageDir = rtrim($storageDir, '/');
        
        // Ensure directory exists
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Save UploadRecord to JSON file
     *
     * @param UploadRecord $record Upload record to save
     * @return bool True if successful
     */
    public function save(UploadRecord $record): bool
    {
        $path = $this->getRecordPath($record->guid);
        $json = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            error_log(sprintf('Failed to encode UploadRecord %s to JSON', $record->guid));
            return false;
        }

        $result = file_put_contents($path, $json);

        if ($result === false) {
            error_log(sprintf('Failed to save UploadRecord %s to %s', $record->guid, $path));
            return false;
        }

        return true;
    }

    /**
     * Load UploadRecord from JSON file
     *
     * @param string $guid Upload record GUID
     * @return UploadRecord|null Upload record if found, null otherwise
     */
    public function load(string $guid): ?UploadRecord
    {
        $path = $this->getRecordPath($guid);

        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            error_log(sprintf('Failed to read UploadRecord from %s', $path));
            return null;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            error_log(sprintf('Failed to decode JSON from %s', $path));
            return null;
        }

        return UploadRecord::fromArray($data);
    }

    /**
     * Check if record exists
     *
     * @param string $guid Upload record GUID
     * @return bool True if record exists
     */
    public function exists(string $guid): bool
    {
        return file_exists($this->getRecordPath($guid));
    }

    /**
     * Delete record file
     *
     * @param string $guid Upload record GUID
     * @return bool True if successful
     */
    public function delete(string $guid): bool
    {
        $path = $this->getRecordPath($guid);

        if (!file_exists($path)) {
            return true; // Already deleted
        }

        if (!unlink($path)) {
            error_log(sprintf('Failed to delete UploadRecord from %s', $path));
            return false;
        }

        return true;
    }

    /**
     * Get all record GUIDs
     *
     * @return string[] Array of GUIDs
     */
    public function getAllGuids(): array
    {
        $files = glob($this->storageDir . '/*.json');
        if ($files === false) {
            return [];
        }

        $guids = [];
        foreach ($files as $file) {
            $guid = basename($file, '.json');
            $guids[] = $guid;
        }

        return $guids;
    }

    /**
     * Get file path for record
     *
     * @param string $guid Upload record GUID
     * @return string File path
     */
    private function getRecordPath(string $guid): string
    {
        // Sanitize GUID to prevent directory traversal
        $sanitized = preg_replace('/[^a-f0-9-]/i', '', $guid);
        return sprintf('%s/%s.json', $this->storageDir, $sanitized);
    }
}
