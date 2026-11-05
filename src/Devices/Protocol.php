<?php

declare(strict_types=1);

namespace AegisFlow\Devices;

use InvalidArgumentException;

/**
 * Defines the unified command schema and response validation rules.
 *
 * All commands dispatched to agents must conform to the envelope
 * produced by createCommand(). Responses are validated before being
 * stored or forwarded to the AuditLog and Registry. Now includes
 * 'applescript' command type for the new MacosHandler.
 */
final class Protocol
{
    /**
     * All command types supported by the platform handlers.
     *
     * @var array<string>
     */
    private const VALID_COMMAND_TYPES = [
        'shell',
        'screenshot',
        'push',
        'pull',
        'install',
        'get_battery',
        'notify',
        'diagnostics',
        'applescript',
    ];

    /**
     * Valid response status values returned by agents.
     *
     * @var array<string>
     */
    private const VALID_RESPONSE_STATUSES = [
        'success',
        'error',
        'failed',
        'timeout',
    ];

    /**
     * Create a canonical command envelope that all platform handlers
     * (including MacosHandler) can consume.
     *
     * @param string $type One of the VALID_COMMAND_TYPES
     * @param array $params Additional parameters for the command
     * @return array Command with id, type, params, timestamp etc.
     *
     * @throws InvalidArgumentException
     */
    public function createCommand(string $type, array $params = []): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::VALID_COMMAND_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported command type "%s". Supported types: %s.',
                    $type,
                    implode(', ', self::VALID_COMMAND_TYPES)
                )
            );
        }

        $id = 'cmd-' . bin2hex(random_bytes(6));

        return [
            'id' => $id,
            'type' => $type,
            'params' => $params,
            'ts' => time(),
            'protocol_version' => '1.1',
            'platform_agnostic' => true,
        ];
    }

    /**
     * Validate a response received from an agent.
     *
     * Checks for required fields and that the status is one of the
     * allowed values.
     */
    public function validateResponse(array $response): bool
    {
        if (!isset($response['id'], $response['status'])) {
            return false;
        }

        if (!in_array(strtolower($response['status']), self::VALID_RESPONSE_STATUSES, true)) {
            return false;
        }

        return true;
    }
}
