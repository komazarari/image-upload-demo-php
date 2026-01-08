<?php
/**
 * Firestore Storage Implementation
 *
 * Persists UploadRecord instances to Google Cloud Firestore.
 * Production-ready persistent storage with query capabilities.
 */

declare(strict_types=1);

namespace ImageUploadDemo\Implementations;

use Google\Cloud\Firestore\FirestoreClient;
use ImageUploadDemo\Models\UploadRecord;
use ImageUploadDemo\Services\StorageServiceInterface;

class FirestoreStorageService extends StorageServiceInterface
{
    /**
     * Firestore client
     */
    private FirestoreClient $client;

    /**
     * Collection name for upload records
     */
    private string $collectionName;

    /**
     * Constructor
     *
     * @param string|null $projectId GCP project ID (defaults to env or auto-detect)
     * @param string|null $keyFilePath Path to service account JSON key file
     * @param string $collectionName Firestore collection name
     */
    public function __construct(
        ?string $projectId = null,
        ?string $keyFilePath = null,
        string $collectionName = 'upload_records'
    ) {
        $config = [];

        if ($projectId !== null) {
            $config['projectId'] = $projectId;
        }

        if ($keyFilePath !== null && file_exists($keyFilePath)) {
            $config['keyFilePath'] = $keyFilePath;
        }

        $this->client = new FirestoreClient($config);
        $this->collectionName = $collectionName;
    }

    /**
     * Save UploadRecord to Firestore
     *
     * @param UploadRecord $record Upload record to save
     * @return bool True if successful
     */
    public function save(UploadRecord $record): bool
    {
        try {
            $collection = $this->client->collection($this->collectionName);
            $docRef = $collection->document($record->guid);

            $data = $record->toArray();
            
            // Convert DateTime objects to Firestore timestamps
            if (isset($data['createdAt'])) {
                $data['createdAt'] = new \DateTime($data['createdAt']);
            }
            if (isset($data['updatedAt'])) {
                $data['updatedAt'] = new \DateTime($data['updatedAt']);
            }

            $docRef->set($data);

            error_log(sprintf('Saved UploadRecord %s to Firestore', $record->guid));
            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to save UploadRecord %s to Firestore: %s',
                $record->guid,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Load UploadRecord from Firestore
     *
     * @param string $guid Upload record GUID
     * @return UploadRecord|null Upload record if found, null otherwise
     */
    public function load(string $guid): ?UploadRecord
    {
        try {
            $collection = $this->client->collection($this->collectionName);
            $docRef = $collection->document($guid);
            $snapshot = $docRef->snapshot();

            if (!$snapshot->exists()) {
                return null;
            }

            $data = $snapshot->data();

            // Convert Firestore timestamps back to ISO 8601 strings
            if (isset($data['createdAt']) && $data['createdAt'] instanceof \DateTimeInterface) {
                $data['createdAt'] = $data['createdAt']->format('c');
            }
            if (isset($data['updatedAt']) && $data['updatedAt'] instanceof \DateTimeInterface) {
                $data['updatedAt'] = $data['updatedAt']->format('c');
            }

            return UploadRecord::fromArray($data);
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to load UploadRecord %s from Firestore: %s',
                $guid,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Check if record exists
     *
     * @param string $guid Upload record GUID
     * @return bool True if record exists
     */
    public function exists(string $guid): bool
    {
        try {
            $collection = $this->client->collection($this->collectionName);
            $docRef = $collection->document($guid);
            $snapshot = $docRef->snapshot();

            return $snapshot->exists();
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to check existence of UploadRecord %s in Firestore: %s',
                $guid,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Delete record from Firestore
     *
     * @param string $guid Upload record GUID
     * @return bool True if successful
     */
    public function delete(string $guid): bool
    {
        try {
            $collection = $this->client->collection($this->collectionName);
            $docRef = $collection->document($guid);
            $docRef->delete();

            error_log(sprintf('Deleted UploadRecord %s from Firestore', $guid));
            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to delete UploadRecord %s from Firestore: %s',
                $guid,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Get all record GUIDs
     *
     * @return string[] Array of GUIDs
     */
    public function getAllGuids(): array
    {
        try {
            $collection = $this->client->collection($this->collectionName);
            $documents = $collection->documents();

            $guids = [];
            foreach ($documents as $document) {
                $guids[] = $document->id();
            }

            return $guids;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to get all GUIDs from Firestore: %s',
                $e->getMessage()
            ));
            return [];
        }
    }

    /**
     * Query records by status
     *
     * @param string $status Status to filter by
     * @return UploadRecord[] Array of matching records
     */
    public function findByStatus(string $status): array
    {
        try {
            $collection = $this->client->collection($this->collectionName);
            $query = $collection->where('status', '=', $status);
            $documents = $query->documents();

            $records = [];
            foreach ($documents as $document) {
                $data = $document->data();
                
                // Convert timestamps
                if (isset($data['createdAt']) && $data['createdAt'] instanceof \DateTimeInterface) {
                    $data['createdAt'] = $data['createdAt']->format('c');
                }
                if (isset($data['updatedAt']) && $data['updatedAt'] instanceof \DateTimeInterface) {
                    $data['updatedAt'] = $data['updatedAt']->format('c');
                }

                $records[] = UploadRecord::fromArray($data);
            }

            return $records;
        } catch (\Exception $e) {
            error_log(sprintf(
                'Failed to query records by status in Firestore: %s',
                $e->getMessage()
            ));
            return [];
        }
    }
}
