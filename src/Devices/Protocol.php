<?php

declare(strict_types=1);

namespace AegisFlow\Devices;

use InvalidArgumentException;

/**
 * Defines the unified command schema and response validation rules.
 *
 * All commands dispatched to agents must conform to the envelope
 * produced by createCommand(). Responses are validated before being
 * stored or forwarded to the AuditLog and Registry.
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
        'acknowledged',
        'partial',
    ];

    /**
     * Create a standardized, signed command envelope.
     *
     * The returned array is the canonical format passed to
     * platform-specific handlers and then encrypted.
     *
     * @param string $type One of the VALID_COMMAND_TYPES
     * @param array $params Type-specific parameters (will be normalized)
     * @return array{
     *   id: string,
     *   type: string,
     *   params: array,
     *   ts: int,
     *   protocol_version: string
     * }
     *
     * @throws InvalidArgumentException
     */
    public function createCommand(string $type, array $params = []): array
    {
        $type = strtolower(trim($type));

        if ($type === '') {
            throw new InvalidArgumentException('Command type cannot be empty.');
        }

        if (!in_array($type, self::VALID_COMMAND_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported command type "%s". Valid types: %s.',
                    $type,
                    implode(', ', self::VALID_COMMAND_TYPES)
                )
            );
        }

        if (!is_array($params)) {
            throw new InvalidArgumentException('Command parameters must be provided as an array.');
        }

        return [
            'id' => 'cmd_' . bin2hex(random_bytes(8)),
            'type' => $type,
            'params' => $this->normalizeParams($params),
            'ts' => time(),
            'protocol_version' => '1.1',
        ];
    }

    /**
     * Validate a response received from any agent.
     *
     * Ensures structural integrity, required fields, and allowed status values.
     * Used by Hub, Dispatcher and AuditLog before persisting results.
     */
    public function validateResponse(array $response): bool
    {
        if (empty($response)) {
            return false;
        }

        $requiredFields = ['id', 'status', 'command_type'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $response)) {
                return false;
            }

            $value = $response[$field];
            if (is_string($value) && trim($value) === '') {
                return false;
            }
        }

        if (!in_array($response['status'], self::VALID_RESPONSE_STATUSES, true)) {
            return false;
        }

        // Optional fields must have sane types when present
        if (isset($response['result'])) {
            if (!is_array($response['result']) && !is_scalar($response['result']) && $response['result'] !== null) {
                return false;
            }
        }

        if (isset($response['error']) && !is_string($response['error'])) {
            return false;
        }

        // Command ID in response must match expected pattern
        if (isset($response['id']) && !str_starts_with((string)$response['id'], 'cmd_')) {
            return false;
        }

        return true;
    }

    /**
     * Recursively normalize and sanitize parameters.
     *
     * Trims strings, coerces numeric values, recurses into arrays.
     * Prevents injection of unexpected data shapes into encrypted payloads.
     */
    private function normalizeParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $rawKey => $value) {
            $key = strtolower(trim((string)$rawKey));

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            } elseif (is_int($value) || is_float($value)) {
                $normalized[$key] = $value;
            } elseif (is_bool($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeParams($value);
            } else {
                // null or other scalars become null to avoid serialization surprises
                $normalized[$key] = null;
            }
        }

        return $normalized;
    }
}
