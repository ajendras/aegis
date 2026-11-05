<?php

declare(strict_types=1);

namespace AegisFlow\Commands;

use AegisFlow\Devices\Registry;
use AegisFlow\Core\Config;
use AegisFlow\Logger\AuditLog;
use AegisFlow\Devices\Protocol;
use AegisFlow\Security\ChannelEncryptor;
use AegisFlow\Agents\AndroidHandler;
use AegisFlow\Agents\IosHandler;
use AegisFlow\Agents\WindowsHandler;
use AegisFlow\Agents\MacosHandler;
use AegisFlow\Storage\JsonRepo;
use InvalidArgumentException;
use RuntimeException;

/**
 * Routes generic commands to the correct platform handler, encrypts them,
 * persists them for delivery, and supports bulk operations by tag.
 *
 * All commands are first normalized by Protocol, then transformed by the
 * appropriate *Handler (including the new MacosHandler with AppleScript
 * support), encrypted per-device, and stored in commands.json.
 * The WebSocket Hub can later pick up pending commands for connected agents.
 */
final class Dispatcher
{
    private Registry $registry;
    private Config $config;
    private AuditLog $auditLog;
    private JsonRepo $repo;
    private Protocol $protocol;

    public function __construct(Registry $registry, Config $config, AuditLog $auditLog)
    {
        $this->registry = $registry;
        $this->config = $config;
        $this->auditLog = $auditLog;
        $this->repo = new JsonRepo();
        $this->protocol = new Protocol();
    }

    /**
     * Dispatch a command to a single device.
     *
     * @param string $deviceId The target device identifier
     * @param string $type Command type (must be supported by Protocol)
     * @param array $params Command-specific parameters
     * @return bool True if the command was prepared and stored successfully
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function dispatch(string $deviceId, string $type, array $params = []): bool
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException('Device ID cannot be empty.');
        }

        $device = $this->registry->find($deviceId);
        if (empty($device) || !isset($device['platform'])) {
            throw new InvalidArgumentException(sprintf('Device "%s" not found or has no platform.', $deviceId));
        }

        $platform = strtolower($device['platform']);

        // Create canonical command envelope
        $command = $this->protocol->createCommand($type, $params);

        // Select and run the appropriate platform handler
        $handler = $this->getHandlerForPlatform($platform);
        $platformPayload = $handler->handle($command);

        // Encrypt for the specific device
        $encryptor = new ChannelEncryptor($this->config);
        $encrypted = $encryptor->encryptFor($deviceId, $platformPayload);

        // Persist the pending command
        $this->storeCommand($deviceId, $command, $encrypted, $platform);

        $this->auditLog->record('command_dispatched', [
            'device_id' => $deviceId,
            'command_id' => $command['id'],
            'type' => $type,
            'platform' => $platform,
            'actor' => 'web',
        ], 'web');

        return true;
    }

    /**
     * Broadcast a command to all devices that match a given tag.
     *
     * @param string $tag Tag to match against registered devices
     * @param array $command Generic command shape: ['type' => string, 'params' => array]
     * @return int Number of devices the command was successfully dispatched to
     */
    public function broadcastToTag(string $tag, array $command): int
    {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            throw new InvalidArgumentException('Tag cannot be empty for broadcast.');
        }

        if (!isset($command['type']) || !is_string($command['type'])) {
            throw new InvalidArgumentException('Broadcast command must contain a valid "type".');
        }

        $devices = $this->registry->byTag($tag);
        if (empty($devices)) {
            $this->auditLog->record('broadcast_no_devices', ['tag' => $tag]);
            return 0;
        }

        $params = $command['params'] ?? [];
        $successCount = 0;

        foreach ($devices as $device) {
            try {
                $this->dispatch((string)$device['id'], $command['type'], (array)$params);
                $successCount++;
            } catch (InvalidArgumentException|RuntimeException $e) {
                $this->auditLog->record('broadcast_error', [
                    'device_id' => $device['id'] ?? 'unknown',
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->auditLog->record('broadcast_completed', [
            'tag' => $tag,
            'command_type' => $command['type'],
            'devices_targeted' => count($devices),
            'devices_successful' => $successCount,
        ]);

        return $successCount;
    }

    /**
     * Return the correct handler instance for a platform.
     */
    private function getHandlerForPlatform(string $platform): object
    {
        return match ($platform) {
            'android' => new AndroidHandler(),
            'ios' => new IosHandler(),
            'windows' => new WindowsHandler(),
            'macos' => new MacosHandler(),
            default => throw new InvalidArgumentException(sprintf('Unsupported platform "%s". Supported: android, ios, windows, macos.', $platform)),
        };
    }

    /**
     * Persist an encrypted command so the WebSocket Hub or polling agents can deliver it.
     */
    private function storeCommand(string $deviceId, array $command, string $encrypted, string $platform): void
    {
        $commands = $this->repo->read('commands');

        $commands[] = [
            'id' => $command['id'],
            'device_id' => $deviceId,
            'platform' => $platform,
            'type' => $command['type'],
            'encrypted_payload' => $encrypted,
            'sent_at' => time(),
            'status' => 'pending',
            'protocol_version' => $command['protocol_version'] ?? '1.1',
        ];

        // Keep only the most recent 500 commands (prevent unbounded growth)
        if (count($commands) > 500) {
            $commands = array_slice($commands, -500);
        }

        $this->repo->write('commands', $commands);
    }
}
