<?php

declare(strict_types=1);

namespace AegisFlow\Agents;

use InvalidArgumentException;

/**
 * Maps generic Aegis commands to iOS companion-app protocol payloads.
 *
 * iOS agents run inside a companion application that communicates
 * via WebSocket and limited file-system access (Documents, tmp).
 * Commands are translated into the exact shape expected by the
 * iOS NEPacketTunnelProvider / background daemon.
 */
final class IosHandler
{
    /**
     * @var array<string,string> Maps generic command types to iOS agent actions
     */
    private const ACTION_MAP = [
        'shell' => 'execute_intent',
        'screenshot' => 'capture_screenshot',
        'push' => 'file_transfer',
        'pull' => 'file_transfer',
        'install' => 'install_profile',
        'get_battery' => 'query_system_state',
        'notify' => 'local_notification',
        'diagnostics' => 'run_diagnostics',
    ];

    /**
     * Translate a generic command into an iOS-specific payload.
     *
     * @param array $cmd Command array (typically produced by Protocol::createCommand)
     * @return array iOS companion protocol structure
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $cmd): array
    {
        if (!isset($cmd['type']) || !is_string($cmd['type'])) {
            throw new InvalidArgumentException('IosHandler requires a string "type" key in the command array.');
        }

        $type = strtolower(trim($cmd['type']));
        $params = $cmd['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        $commandId = $cmd['id'] ?? 'cmd-ios-' . bin2hex(random_bytes(6));

        if (!array_key_exists($type, self::ACTION_MAP)) {
            throw new InvalidArgumentException(
                sprintf(
                    'IosHandler does not support command type "%s". Supported types: %s.',
                    $type,
                    implode(', ', array_keys(self::ACTION_MAP))
                )
            );
        }

        $action = self::ACTION_MAP[$type];

        $payload = [
            'command_id' => $commandId,
            'action' => $action,
            'platform' => 'ios',
            'protocol' => 'companion_v2',
            'timestamp' => time(),
            'payload' => $this->buildIosPayload($type, $params),
        ];

        // iOS commands are more latency sensitive; default timeout is lower
        if (!isset($payload['payload']['timeout'])) {
            $payload['payload']['timeout'] = 25;
        }

        return $payload;
    }

    /**
     * Build the platform-specific inner payload for the iOS companion app.
     */
    private function buildIosPayload(string $type, array $params): array
    {
        return match ($type) {
            'shell' => [
                'intent' => $params['intent'] ?? 'com.aegisflow.custom',
                'arguments' => (string)($params['cmd'] ?? $params['args'] ?? 'echo "Aegis iOS ready"'),
                'timeout' => (int)($params['timeout'] ?? 20),
                'use_background' => true,
            ],
            'screenshot' => [
                'format' => in_array(($params['format'] ?? ''), ['png', 'heic'], true)
                    ? $params['format']
                    : 'png',
                'quality' => max(60, min(100, (int)($params['quality'] ?? 90))),
                'include_status_bar' => (bool)($params['status_bar'] ?? true),
                'scale' => max(30, min(100, (int)($params['scale'] ?? 100))),
            ],
            'push' => [
                'operation' => 'push',
                'target_path' => $this->sanitizeIosPath($params['destination'] ?? '/Documents/aegis_payload.txt'),
                'data' => base64_encode((string)($params['content'] ?? 'Aegis Flow test data')),
                'mime_type' => $params['mime'] ?? 'text/plain',
            ],
            'pull' => [
                'operation' => 'pull',
                'source_path' => $this->sanitizeIosPath($params['path'] ?? $params['source'] ?? '/Documents/export.jpg'),
                'max_size' => (int)($params['max_size'] ?? 8388608), // 8 MiB
            ],
            'install' => [
                'url' => filter_var($params['url'] ?? '', FILTER_VALIDATE_URL) ?: '',
                'type' => $params['type'] ?? 'profile',
                'requires_user_interaction' => true,
            ],
            'get_battery' => [
                'include_detailed' => true,
                'include_thermal_state' => true,
            ],
            'notify' => [
                'title' => (string)($params['title'] ?? 'Aegis Flow'),
                'body' => (string)($params['body'] ?? 'Command completed on iOS device'),
                'category' => $params['category'] ?? 'control',
            ],
            'diagnostics' => [
                'checks' => is_array($params['checks'] ?? null)
                    ? $params['checks']
                    : ['battery', 'storage', 'network'],
            ],
            default => $params,
        };
    }

    /**
     * Sanitize file paths for iOS sandbox (Documents, tmp, Library).
     *
     * Prevents escaping the app container.
     */
    private function sanitizeIosPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/Documents/aegis_temp';
        }

        // Strip dangerous sequences
        $path = str_replace(['../', '..\\', "\0", '~'], '', $path);

        $allowedPrefixes = ['/Documents', '/tmp', '/Library'];
        $hasAllowedPrefix = false;

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $hasAllowedPrefix = true;
                break;
            }
        }

        if (!$hasAllowedPrefix) {
            return '/Documents/' . ltrim($path, '/');
        }

        return $path;
    }
}
