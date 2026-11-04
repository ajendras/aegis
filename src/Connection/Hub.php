<?php

declare(strict_types=1);

namespace AegisFlow\Connection;

use AegisFlow\Devices\Registry;
use AegisFlow\Core\Config;
use AegisFlow\Logger\AuditLog;
use AegisFlow\Devices\Protocol;
use AegisFlow\Security\ChannelEncryptor;
use AegisFlow\Storage\JsonRepo;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;
use Exception;

/**
 * WebSocket Hub using Ratchet.
 *
 * Manages persistent connections from device agents (Android, iOS, Windows)
 * and the browser-based control panel. Handles registration, command delivery,
 * encrypted response processing, status updates and live broadcasting of
 * events to connected UI clients.
 */
final class Hub implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface> */
    private \SplObjectStorage $clients;

    private Registry $registry;
    private Config $config;
    private AuditLog $auditLog;
    private Protocol $protocol;
    private JsonRepo $repo;

    /** @var array<string, ConnectionInterface> Maps device IDs to their active connection */
    private array $deviceConnections = [];

    public function __construct(Registry $registry, Config $config, AuditLog $auditLog)
    {
        $this->clients = new \SplObjectStorage();
        $this->registry = $registry;
        $this->config = $config;
        $this->auditLog = $auditLog;
        $this->protocol = new Protocol();
        $this->repo = new JsonRepo();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $conn->deviceId = null;
        $conn->isUiClient = false;

        $this->auditLog->record('ws_connection_opened', [
            'resource_id' => $conn->resourceId,
            'remote_address' => $conn->remoteAddress ?? 'unknown'
        ]);

        // Send welcome with server capabilities
        $conn->send(json_encode([
            'type' => 'welcome',
            'server_version' => '0.1.0',
            'protocol_version' => '1.1',
            'timestamp' => time(),
        ]));
    }

    public function onMessage(ConnectionInterface $from, string $msg): void
    {
        try {
            // Try parsing as JSON first (for control messages)
            $data = json_decode($msg, true);

            if (is_array($data) && isset($data['type'])) {
                match ($data['type']) {
                    'register' => $this->handleRegistration($from, $data),
                    'heartbeat' => $this->handleHeartbeat($from, $data),
                    'ui_subscribe' => $this->handleUiSubscription($from, $data),
                    default => $this->broadcastToUi([
                        'type' => 'unknown_message',
                        'payload' => $data
                    ]),
                };
                return;
            }

            // If not recognized JSON control message, treat as encrypted payload from agent
            if ($from->deviceId !== null) {
                $this->handleEncryptedResponse($from, $msg);
            } else {
                $from->send(json_encode(['error' => 'Must register before sending data']));
            }
        } catch (Exception $e) {
            $this->auditLog->record('ws_message_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            try {
                $from->send(json_encode(['error' => 'Invalid message format']));
            } catch (Exception $sendEx) {
                // Connection may be closed
            }
        }
    }

    private function handleRegistration(ConnectionInterface $conn, array $data): void
    {
        $platform = strtolower(trim($data['platform'] ?? ''));
        if (!in_array($platform, ['android', 'ios', 'windows'], true)) {
            $conn->send(json_encode(['error' => 'Invalid or missing platform']));
            return;
        }

        $info = [
            'platform' => $platform,
            'name' => $data['name'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'tags' => $data['tags'] ?? [],
        ];

        $deviceId = $this->registry->add($info);

        $conn->deviceId = $deviceId;
        $this->deviceConnections[$deviceId] = $conn;

        $this->registry->updateStatus($deviceId, [
            'status' => 'online',
            'last_seen' => time(),
            'ip' => $conn->remoteAddress ?? '',
        ]);

        $this->auditLog->record('device_registered_via_ws', [
            'device_id' => $deviceId,
            'platform' => $platform,
            'name' => $info['name'],
        ], 'agent');

        // Confirm registration
        $conn->send(json_encode([
            'type' => 'registered',
            'device_id' => $deviceId,
            'status' => 'success'
        ]));

        // Push any pending commands immediately
        $this->pushPendingCommands($deviceId, $conn);
    }

    private function handleHeartbeat(ConnectionInterface $conn, array $data): void
    {
        $deviceId = $conn->deviceId ?? $data['device_id'] ?? null;

        if ($deviceId) {
            $this->registry->updateStatus((string)$deviceId, [
                'battery' => (int)($data['battery'] ?? 100),
                'status' => 'online',
            ]);

            $this->broadcastToUi([
                'type' => 'device_heartbeat',
                'device_id' => $deviceId,
                'battery' => $data['battery'] ?? 100,
            ]);
        }

        $conn->send(json_encode(['type' => 'heartbeat_ack', 'timestamp' => time()]));
    }

    private function handleUiSubscription(ConnectionInterface $conn, array $data): void
    {
        $conn->isUiClient = true;
        $conn->send(json_encode([
            'type' => 'ui_subscribed',
            'message' => 'Live updates enabled'
        ]));

        // Send current state snapshot
        $this->broadcastToUi([
            'type' => 'initial_state',
            'devices' => $this->registry->all(),
        ], $conn);
    }

    private function handleEncryptedResponse(ConnectionInterface $conn, string $encryptedMsg): void
    {
        if ($conn->deviceId === null) {
            return;
        }

        try {
            $encryptor = new ChannelEncryptor($this->config);
            $response = $encryptor->decryptFrom($conn->deviceId, $encryptedMsg);

            if (!$this->protocol->validateResponse($response)) {
                $this->auditLog->record('invalid_response', [
                    'device_id' => $conn->deviceId,
                ]);
                return;
            }

            $response['device_id'] = $conn->deviceId;
            $response['received_at'] = time();

            // Update device status from response if provided
            if (isset($response['battery'])) {
                $this->registry->updateStatus($conn->deviceId, [
                    'battery' => (int)$response['battery'],
                    'status' => 'online'
                ]);
            }

            // Mark the command as completed
            $this->markCommandAsCompleted($response['id'] ?? '', $conn->deviceId);

            $this->auditLog->record('command_response_received', $response, 'agent');

            // Broadcast the response to all UI clients
            $this->broadcastToUi([
                'type' => 'response',
                'payload' => $response,
            ]);

        } catch (RuntimeException | InvalidArgumentException $e) {
            $this->auditLog->record('decryption_failed', [
                'device_id' => $conn->deviceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function pushPendingCommands(string $deviceId, ConnectionInterface $conn): void
    {
        $allCommands = $this->repo->read('commands');
        $pending = [];

        foreach ($allCommands as $cmd) {
            if (($cmd['device_id'] ?? '') === $deviceId && ($cmd['status'] ?? 'pending') === 'pending') {
                $pending[] = $cmd;
            }
        }

        foreach ($pending as $command) {
            try {
                $conn->send($command['encrypted_payload']);

                // Update status to 'sent'
                $this->updateCommandStatus($command['id'], 'sent');
            } catch (Exception $e) {
                $this->auditLog->record('failed_to_send_pending_command', [
                    'command_id' => $command['id'] ?? 'unknown',
                    'device_id' => $deviceId
                ]);
            }
        }
    }

    private function markCommandAsCompleted(string $commandId, string $deviceId): void
    {
        $commands = $this->repo->read('commands');

        foreach ($commands as &$cmd) {
            if (($cmd['id'] ?? '') === $commandId && ($cmd['device_id'] ?? '') === $deviceId) {
                $cmd['status'] = 'completed';
                $cmd['completed_at'] = time();
                break;
            }
        }

        $this->repo->write('commands', $commands);
    }

    private function updateCommandStatus(string $commandId, string $status): void
    {
        $commands = $this->repo->read('commands');

        foreach ($commands as &$cmd) {
            if (($cmd['id'] ?? '') === $commandId) {
                $cmd['status'] = $status;
                if ($status === 'sent') {
                    $cmd['sent_at'] = time();
                }
                break;
            }
        }

        $this->repo->write('commands', $commands);
    }

    /**
     * Broadcast a message to all UI-connected clients.
     */
    private function broadcastToUi(array $message, ?ConnectionInterface $onlyTo = null): void
    {
        $payload = json_encode($message);
        if ($payload === false) {
            return;
        }

        foreach ($this->clients as $client) {
            if ($client->isUiClient ?? false) {
                if ($onlyTo === null || $client === $onlyTo) {
                    try {
                        $client->send($payload);
                    } catch (Exception $e) {
                        // Client may have disconnected
                        $this->clients->detach($client);
                    }
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (isset($conn->deviceId) && $conn->deviceId !== null) {
            unset($this->deviceConnections[$conn->deviceId]);

            $this->registry->updateStatus($conn->deviceId, [
                'status' => 'offline',
                'last_seen' => time()
            ]);

            $this->broadcastToUi([
                'type' => 'device_offline',
                'device_id' => $conn->deviceId
            ]);
        }

        $this->clients->detach($conn);

        $this->auditLog->record('ws_connection_closed', [
            'device_id' => $conn->deviceId ?? 'ui-client',
            'resource_id' => $conn->resourceId
        ]);
    }

    public function onError(ConnectionInterface $conn, Exception $e): void
    {
        $this->auditLog->record('ws_error', [
            'error' => $e->getMessage(),
            'device_id' => $conn->deviceId ?? null
        ]);

        $conn->close();
    }
}
