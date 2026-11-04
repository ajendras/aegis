<?php

declare(strict_types=1);

namespace AegisFlow\Storage;

use RuntimeException;

/**
 * Simple file-based JSON repository.
 *
 * Provides persistence for devices, commands, logs and other entities
 * without requiring a traditional database. All data is stored in
 * the ./storage directory as *.json files. Thread-safe writes using
 * file locking.
 */
final class JsonRepo
{
    private const STORAGE_PATH = __DIR__ . '/../../storage';

    /**
     * Read entity data from its JSON file.
     *
     * @param string $entity Name of the entity (devices, logs, commands, etc.)
     * @return array The decoded data or empty array if file doesn't exist
     *
     * @throws RuntimeException
     */
    public function read(string $entity): array
    {
        $filePath = $this->getFilePath($entity);

        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read storage file for entity: {$entity}");
        }

        if (trim($content) === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\JsonException $e) {
            throw new RuntimeException("Invalid JSON in {$entity}.json: " . $e->getMessage());
        }
    }

    /**
     * Write data to the entity's JSON file.
     *
     * Uses exclusive locking to prevent concurrent write corruption.
     *
     * @param string $entity
     * @param array $data
     * @return void
     *
     * @throws RuntimeException
     */
    public function write(string $entity, array $data): void
    {
        $filePath = $this->getFilePath($entity);
        $directory = dirname($filePath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create storage directory: {$directory}");
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $handle = fopen($filePath, 'c');
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$entity}.json for writing");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Unable to lock {$entity}.json");
        }

        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Get the full path for an entity file.
     */
    private function getFilePath(string $entity): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $entity);
        if ($sanitized === '') {
            $sanitized = 'default';
        }

        return self::STORAGE_PATH . '/' . $sanitized . '.json';
    }
}
