<?php

declare(strict_types=1);

namespace AegisFlow\Devices;

use AegisFlow\Storage\JsonRepo;
use InvalidArgumentException;

/**
 * JSON‑backed device registry.
 *
 * Stores device metadata (platform, name, tags, status, timestamps) in a
 * `devices.json` file via {@see JsonRepo}. Provides simple CRUD‑style
 * operations used throughout the application and test suite.
 */
final class Registry
{
    private JsonRepo $repo;

    /**
     * @param JsonRepo $repo Optional repository; if omitted a default instance is created.
     */
    public function __construct(JsonRepo $repo = null)
    {
        $this->repo = $repo ?? new JsonRepo();
    }

    /**
     * Add a new device to the registry.
     *
     * @param array $info Must contain at least a `platform` key. Optional keys:
     *                    `name`, `os_version`, `tags` (array of strings).
     *
     * @return string The generated device identifier.
     *
     * @throws InvalidArgumentException
     */
    public function add(array $info): string
    {
        if (!isset($info['platform']) || !is_string($info['platform']) || trim($info['platform']) === '') {
            throw new InvalidArgumentException('Device info must contain a non‑empty "platform" string.');
        }

        $platform = strtolower(trim($info['platform']));

        $deviceId = 'dev-' . bin2hex(random_bytes(6));

        $device = [
            'id' => $deviceId,
            'platform' => $platform,
            'name' => $info['name'] ?? null,
            'os_version' => $info['os_version'] ?? null,
            'tags' => array_map('strtolower', $info['tags'] ?? []),
            'status' => 'offline',
            'created_at' => time(),
            'last_seen' => time(),
        ];

        $devices = $this->repo->read('devices');
        $devices[] = $device;
        $this->repo->write('devices', $devices);

        return $deviceId;
    }

    /**
     * Find a device by its identifier.
     *
     * @param string $deviceId
     *
     * @return array Empty array if not found.
     */
    public function find(string $deviceId): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return [];
        }

        $devices = $this->repo->read('devices');

        foreach ($devices as $device) {
            if (($device['id'] ?? '') === $deviceId) {
                return $device;
            }
        }

        return [];
    }

    /**
     * Update status‑related fields for a device.
     *
     * @param string $deviceId
     * @param array  $data     Arbitrary key/value pairs to merge into the device record.
     *
     * @throws InvalidArgumentException If the device does not exist.
     */
    public function updateStatus(string $deviceId, array $data): void
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException('Device ID cannot be empty.');
        }

        $devices = $this->repo->read('devices');
        $found = false;

        foreach ($devices as &$device) {
            if (($device['id'] ?? '') === $deviceId) {
                // Merge provided data; preserve existing keys unless overwritten.
                $device = array_merge($device, $data);
                // Ensure timestamps are sensible.
                $device['last_seen'] = $data['last_seen'] ?? time();
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new InvalidArgumentException(sprintf('Device "%s" not found.', $deviceId));
        }

        $this->repo->write('devices', $devices);
    }

    /**
     * Return all registered devices.
     *
     * @return array<int, array> List of device records.
     */
    public function all(): array
    {
        return $this->repo->read('devices');
    }

    /**
     * Return devices that have the given tag.
     *
     * Tag comparison is case‑insensitive.
     *
     * @param string $tag
     *
     * @return array<int, array>
     */
    public function byTag(string $tag): array
    {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            return [];
        }

        $devices = $this->repo->read('devices');
        $matched = [];

        foreach ($devices as $device) {
            $deviceTags = $device['tags'] ?? [];
            if (is_array($deviceTags) && in_array($tag, $deviceTags, true)) {
                $matched[] = $device;
            }
        }

        return $matched;
    }
}
