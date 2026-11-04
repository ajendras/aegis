<?php

declare(strict_types=1);

namespace AegisFlow\Devices;

use AegisFlow\Storage\JsonRepo;
use InvalidArgumentException;

/**
 * JSON-backed device registry.
 *
 * Persists device metadata, status and tags using the JsonRepo abstraction.
 * Provides fast lookup, tag-based filtering and immutable last_seen tracking.
 */
final class Registry
{
    private JsonRepo $repo;
    private const ENTITY = 'devices';

    public function __construct(JsonRepo $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Register a new device or update an existing one.
     *
     * @param array $info Raw device information (platform, name, tags, etc.)
     * @return string The assigned device ID
     *
     * @throws InvalidArgumentException
     */
    public function add(array $info): string
    {
        if (!isset($info['platform']) || trim((string)$info['platform']) === '') {
            throw new InvalidArgumentException('Device info must contain a valid platform.');
        }

        $devices = $this->repo->read(self::ENTITY);
        $platform = strtolower(trim((string)$info['platform']));

        $id = trim((string)($info['id'] ?? ''));
        if ($id === '') {
            $id = 'dev-' . substr(bin2hex(random_bytes(8)), 0, 12);
        }

        // Ensure ID uniqueness
        $existingIds = array_column($devices, 'id');
        while (in_array($id, $existingIds, true)) {
            $id = 'dev-' . substr(bin2hex(random_bytes(8)), 0, 12);
        }

        $device = [
            'id' => $id,
            'name' => trim((string)($info['name'] ?? $this->generateDefaultName($platform))),
            'platform' => $platform,
            'status' => 'online',
            'battery' => (int)($info['battery'] ?? 100),
            'last_seen' => time(),
            'tags' => $this->normalizeTags($info['tags'] ?? []),
            'os_version' => trim((string)($info['os_version'] ?? 'unknown')),
            'ip' => trim((string)($info['ip'] ?? '')),
        ];

        // Merge any additional fields provided
        $devices[] = array_merge($device, array_diff_key($info, $device));

        $this->repo->write(self::ENTITY, $devices);

        return $id;
    }

    /**
     * Update device status and metadata.
     *
     * Always updates the last_seen timestamp.
     */
    public function updateStatus(string $id, array $status): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('Device ID cannot be empty.');
        }

        $devices = $this->repo->read(self::ENTITY);
        $updated = false;

        foreach ($devices as &$device) {
            if (($device['id'] ?? '') === $id) {
                $device = array_merge($device, $status);
                $device['last_seen'] = time();

                // Ensure tags remain an array
                if (isset($status['tags'])) {
                    $device['tags'] = $this->normalizeTags($status['tags']);
                } elseif (!isset($device['tags']) || !is_array($device['tags'])) {
                    $device['tags'] = [];
                }

                $updated = true;
                break;
            }
        }

        if ($updated) {
            $this->repo->write(self::ENTITY, $devices);
        } else {
            // Auto-register unknown devices with minimal info
            $this->add(array_merge(['id' => $id], $status));
        }
    }

    /**
     * Find a device by ID.
     *
     * @return array Device record or empty array if not found
     */
    public function find(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return [];
        }

        $devices = $this->repo->read(self::ENTITY);

        foreach ($devices as $device) {
            if (($device['id'] ?? '') === $id) {
                return $device;
            }
        }

        return [];
    }

    /**
     * Return all registered devices.
     *
     * @return array<int, array>
     */
    public function all(): array
    {
        return $this->repo->read(self::ENTITY);
    }

    /**
     * Return devices that match a given tag.
     *
     * @return array<int, array>
     */
    public function byTag(string $tag): array
    {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            return $this->all();
        }

        $devices = $this->repo->read(self::ENTITY);
        $result = [];

        foreach ($devices as $device) {
            $deviceTags = array_map('strtolower', $this->normalizeTags($device['tags'] ?? []));
            if (in_array($tag, $deviceTags, true)) {
                $result[] = $device;
            }
        }

        return $result;
    }

    /**
     * Normalize a tags input into a clean string array.
     *
     * @param mixed $tags
     * @return array<string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }

        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn($t) => strtolower(trim((string)$t)),
            $tags
        ))));
    }

    /**
     * Generate a friendly default name based on platform.
     */
    private function generateDefaultName(string $platform): string
    {
        return match ($platform) {
            'android' => 'Android Device',
            'ios' => 'iOS Device',
            'windows' => 'Windows Device',
            default => 'Connected Device',
        };
    }
}
