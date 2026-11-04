<?php

declare(strict_types=1);

namespace AegisFlow\Agents;

use InvalidArgumentException;

/**
 * Translates generic Aegis commands into Android-specific payloads.
 *
 * The Android companion agent expects an "action" and structured
 * "payload" that map to ADB-like operations or Intents. This handler
 * performs the mapping while enforcing platform constraints and
 * providing helpful validation messages.
 */
final class AndroidHandler
{
    /**
     * @var array<string,string> Maps generic command types to Android agent actions
     */
    private const ACTION_MAP = [
        'shell' => 'execute_shell',
        'screenshot' => 'capture_screen',
        'push' => 'file_push',
        'pull' => 'file_pull',
        'install' => 'install_app',
        'get_battery' => 'query_battery',
        'notify' => 'send_notification',
        'diagnostics' => 'run_diagnostics',
    ];

    /**
     * Translate a generic command (usually from Protocol::createCommand)
     * into the exact JSON structure expected by the Android agent.
     *
     * @param array $cmd Command array containing at minimum 'type' and optionally 'params'
     * @return array Android-specific payload ready for encryption and transmission
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $cmd): array
    {
        if (!isset($cmd['type']) || !is_string($cmd['type'])) {
            throw new InvalidArgumentException('AndroidHandler requires a string "type" key in the command.');
        }

        $type = strtolower(trim($cmd['type']));
        $params = $cmd['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        $commandId = $cmd['id'] ?? 'cmd-android-' . substr(bin2hex(random_bytes(6)), 0, 12);

        if (!array_key_exists($type, self::ACTION_MAP)) {
            throw new InvalidArgumentException(
                sprintf(
                    'AndroidHandler does not support command type "%s". Supported: %s.',
                    $type,
                    implode(', ', array_keys(self::ACTION_MAP))
                )
            );
        }

        $action = self::ACTION_MAP[$type];

        $payload = [
            'command_id' => $commandId,
            'action' => $action,
            'platform' => 'android',
            'timestamp' => time(),
            'payload' => $this->buildPlatformPayload($type, $params),
            'requires_root' => $this->requiresRoot($type, $params),
        ];

        // Add optional timeout for all commands
        if (!isset($payload['payload']['timeout'])) {
            $payload['payload']['timeout'] = 45;
        }

        return $payload;
    }

    /**
     * Build the inner payload that the Android agent will interpret.
     */
    private function buildPlatformPayload(string $type, array $params): array
    {
        return match ($type) {
            'shell' => [
                'command' => $params['cmd'] ?? $params['command'] ?? 'echo "Aegis Flow ready"',
                'timeout' => (int)($params['timeout'] ?? 30),
                'use_su' => (bool)($params['use_root'] ?? false),
                'working_dir' => $params['cwd'] ?? '/sdcard',
            ],
            'screenshot' => [
                'format' => in_array(($params['format'] ?? ''), ['png', 'jpg'], true) ? $params['format'] : 'png',
                'quality' => max(10, min(100, (int)($params['quality'] ?? 85))),
                'scale' => max(25, min(100, (int)($params['scale'] ?? 100))),
                'include_notifications' => (bool)($params['notifications'] ?? false),
            ],
            'push' => [
                'destination' => $this->sanitizePath($params['destination'] ?? '/sdcard/Download/aegis_payload.bin'),
                'content' => (string)($params['content'] ?? 'Aegis Flow test payload'),
                'content_type' => $params['mime'] ?? $params['content_type'] ?? 'text/plain',
            ],
            'pull' => [
                'source' => $this->sanitizePath($params['path'] ?? $params['source'] ?? '/sdcard/DCIM/camera.jpg'),
                'max_size_bytes' => (int)($params['max_size'] ?? 10485760), // 10 MiB default
            ],
            'install' => [
                'package_url' => filter_var($params['url'] ?? '', FILTER_VALIDATE_URL) ?: '',
                'package_name' => $params['package'] ?? '',
                'grant_permissions' => (bool)($params['grant_all'] ?? false),
            ],
            'get_battery' => [
                'detail_level' => 'full',
            ],
            'notify' => [
                'title' => (string)($params['title'] ?? 'Aegis Flow'),
                'body' => (string)($params['body'] ?? 'Command executed'),
                'channel' => $params['channel'] ?? 'control',
            ],
            'diagnostics' => [
                'tests' => is_array($params['tests'] ?? null) ? $params['tests'] : ['battery', 'network', 'storage'],
            ],
            default => [],
        };
    }

    /**
     * Determine if a command should request root privileges.
     */
    private function requiresRoot(string $type, array $params): bool
    {
        if ($type === 'shell') {
            return (bool)($params['use_root'] ?? false);
        }

        return false;
    }

    /**
     * Sanitize filesystem paths for Android (prevent directory traversal).
     */
    private function sanitizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/sdcard/aegis_temp';
        }

        // Remove any attempts at directory traversal
        $path = str_replace(['../', '..\\', "\0"], '', $path);

        // Ensure path starts with a safe Android location
        if (!str_starts_with($path, '/sdcard') && !str_starts_with($path, '/storage')) {
            return '/sdcard/' . ltrim($path, '/');
        }

        return $path;
    }
}
