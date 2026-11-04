<?php

declare(strict_types=1);

namespace AegisFlow\Security;

use AegisFlow\Core\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Per-device end-to-end encryption using Libsodium secretbox.
 *
 * Each device derives a unique 32-byte key from the master seed via
 * the Config service. Messages are JSON-serialized, encrypted with
 * a random nonce, and returned as base64(nonce + ciphertext).
 */
final class ChannelEncryptor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;

        // Ensure Libsodium is available
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The libsodium PHP extension is required for ChannelEncryptor.');
        }
    }

    /**
     * Encrypt a payload for a specific device.
     *
     * @param string $deviceId Device identifier
     * @param array $payload Structured command or response data
     * @return string Base64-encoded encrypted blob (nonce prepended)
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function encryptFor(string $deviceId, array $payload): string
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException('Device ID cannot be empty.');
        }

        $key = $this->config->getDeviceKey($deviceId);
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Invalid key length returned from Config.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to encode payload as JSON: ' . $e->getMessage(), 0, $e);
        }

        $ciphertext = sodium_crypto_secretbox($json, $nonce, $key);
        $combined = $nonce . $ciphertext;

        return base64_encode($combined);
    }

    /**
     * Decrypt a ciphertext received from a device.
     *
     * @param string $deviceId Device identifier
     * @param string $cipher Base64-encoded encrypted blob
     * @return array Decrypted and JSON-decoded payload
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function decryptFrom(string $deviceId, string $cipher): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException('Device ID cannot be empty.');
        }

        $cipher = trim($cipher);
        if ($cipher === '') {
            throw new InvalidArgumentException('Ciphertext cannot be empty.');
        }

        $key = $this->config->getDeviceKey($deviceId);
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Invalid key length returned from Config.');
        }

        $decoded = base64_decode($cipher, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Ciphertext is not valid base64.');
        }

        $nonceBytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($decoded) < $nonceBytes) {
            throw new InvalidArgumentException('Ciphertext is too short to contain a nonce.');
        }

        $nonce = substr($decoded, 0, $nonceBytes);
        $ciphertext = substr($decoded, $nonceBytes);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed. Data may have been tampered with or key is incorrect.');
        }

        try {
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Decrypted data is not valid JSON.', 0, $e);
        }

        if (!is_array($payload)) {
            throw new RuntimeException('Decrypted payload must be a JSON object.');
        }

        return $payload;
    }
}
