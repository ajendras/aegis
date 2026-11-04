<?php

declare(strict_types=1);

namespace AegisFlow\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuration manager for Aegis Flow.
 *
 * Loads and persists settings from/to config.json.
 * Manages the master encryption seed and provides
 * deterministic per-device key derivation using Libsodium-compatible
 * key material.
 */
final class Config
{
    private const CONFIG_PATH = __DIR__ . '/../../config.json';

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct()
    {
        $this->load();
    }

    /**
     * Load configuration, creating a default file if none exists.
     */
    private function load(): void
    {
        if (!file_exists(self::CONFIG_PATH)) {
            $this->data = [
                'app_name' => 'Aegis Flow',
                'encryption_seed' => bin2hex(random_bytes(32)),
                'ws_port' => 8081,
                'theme' => 'dark',
                'log_retention_days' => 90,
                'api_key' => 'change-this-in-production-' . bin2hex(random_bytes(12)),
            ];
            $this->save();
            return;
        }

        $content = file_get_contents(self::CONFIG_PATH);
        if ($content === false) {
            throw new RuntimeException('Cannot read config.json');
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('config.json must contain a JSON object');
        }

        $this->data = $decoded;
    }

    /**
     * Persist current configuration to disk.
     */
    private function save(): void
    {
        $directory = dirname(self::CONFIG_PATH);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create configuration directory');
            }
        }

        $written = file_put_contents(
            self::CONFIG_PATH,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        if ($written === false) {
            throw new RuntimeException('Failed to write configuration file');
        }
    }

    /**
     * Retrieve configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Update the master encryption seed and persist immediately.
     *
     * @throws InvalidArgumentException
     */
    public function setEncryptionSeed(string $seed): void
    {
        $seed = trim($seed);
        if (strlen($seed) < 32) {
            throw new InvalidArgumentException('Encryption seed must be at least 32 characters for sufficient entropy.');
        }

        $this->data['encryption_seed'] = $seed;
        $this->save();
    }

    /**
     * Derive a 32-byte key for the given device ID.
     *
     * Uses HMAC-SHA256 with the master seed as key. The output
     * is exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes and is
     * suitable for use with ChannelEncryptor.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function getDeviceKey(string $deviceId): string
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException('Device ID must not be empty.');
        }

        $seed = $this->get('encryption_seed');
        if (empty($seed) || !is_string($seed)) {
            throw new RuntimeException('No encryption seed configured. Run setEncryptionSeed first.');
        }

        $derived = hash_hmac('sha256', $deviceId, $seed, true);

        if (strlen($derived) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Derived key length is incorrect.');
        }

        return $derived;
    }
}
