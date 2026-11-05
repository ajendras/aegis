<?php

declare(strict_types=1);

namespace AegisFlow\Agents;

use InvalidArgumentException;

/**
 * Translates generic Aegis commands into macOS-specific payloads with first-class
 * AppleScript support.
 *
 * The macOS companion agent uses osascript for AppleScript execution, built-in
 * tools for screenshots, file operations and system queries. AppleScript commands
 * enable rich UI automation, application control, dialogs and notifications.
 */
final class MacosHandler
{
    /**
     * @var array<string,string> Maps generic command types to macOS agent actions
     */
    private const ACTION_MAP = [
        'shell' => 'execute_shell',
        'applescript' => 'execute_applescript',
        'screenshot' => 'capture_screen',
        'push' => 'file_push',
        'pull' => 'file_pull',
        'install' => 'install_app',
        'get_battery' => 'query_power',
        'notify' => 'send_notification',
        'diagnostics' => 'run_diagnostics',
    ];

    /**
     * Translate a generic command (usually from Protocol::createCommand)
     * into the exact JSON structure expected by the macOS agent.
     *
     * Supports dedicated 'applescript' type for direct osascript execution.
     *
     * @param array $cmd Command array containing at minimum 'type' and optionally 'params'
     * @return array macOS-specific payload ready for encryption and transmission
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $cmd): array
    {
        if (!isset($cmd['type']) || !is_string($cmd['type'])) {
            throw new InvalidArgumentException('MacosHandler requires a string "type" key in the command.');
        }

        $type = strtolower(trim($cmd['type']));
        $params = $cmd['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        $commandId = $cmd['id'] ?? 'cmd-macos-' . substr(bin2hex(random_bytes(6)), 0, 12);

        if (!array_key_exists($type, self::ACTION_MAP)) {
            throw new InvalidArgumentException(
                sprintf(
                    'MacosHandler does not support command type "%s". Supported: %s.',
                    $type,
                    implode(', ', array_keys(self::ACTION_MAP))
                )
            );
        }

        $action = self::ACTION_MAP[$type];

        $payload = [
            'command_id' => $commandId,
            'action' => $action,
            'platform' => 'macos',
            'timestamp' => time(),
            'payload' => $this->buildPlatformPayload($type, $params),
        ];

        if (!isset($payload['payload']['timeout'])) {
            $payload['payload']['timeout'] = 30;
        }

        return $payload;
    }

    /**
     * Build the inner payload that the macOS agent will interpret.
     */
    private function buildPlatformPayload(string $type, array $params): array
    {
        return match ($type) {
            'shell' => [
                'command' => (string)($params['cmd'] ?? $params['command'] ?? 'echo "Aegis Flow macOS ready"'),
                'shell' => $params['shell'] ?? 'zsh',
                'timeout' => (int)($params['timeout'] ?? 30),
                'as_admin' => (bool)($params['admin'] ?? false),
            ],
            'applescript' => [
                'script' => (string)($params['script'] ?? $params['code'] ?? 'display dialog "Aegis Flow ready"'),
                'timeout' => (int)($params['timeout'] ?? 20),
                'use_osascript' => true,
            ],
            'screenshot' => [
                'format' => in_array(($params['format'] ?? ''), ['png', 'jpg'], true) ? $params['format'] : 'png',
                'quality' => max(60, min(100, (int)($params['quality'] ?? 90))),
                'output_path' => $this->sanitizePath($params['path'] ?? '~/Desktop/aegis_screenshot.png'),
                'capture_cursor' => (bool)($params['cursor'] ?? true),
                'use_applescript' => (bool)($params['use_applescript'] ?? false),
            ],
            'push' => [
                'destination' => $this->sanitizePath($params['destination'] ?? '~/Downloads/aegis_payload.txt'),
                'content' => (string)($params['content'] ?? 'Aegis Flow test payload'),
                'content_type' => $params['mime'] ?? $params['content_type'] ?? 'text/plain',
            ],
            'pull' => [
                'source' => $this->sanitizePath($params['path'] ?? $params['source'] ?? '~/Documents/report.txt'),
                'max_size_bytes' => (int)($params['max_size'] ?? 10485760),
            ],
            'install' => [
                'package_url' => filter_var($params['url'] ?? '', FILTER_VALIDATE_URL) ?: '',
                'package_name' => (string)($params['package'] ?? ''),
                'silent' => (bool)($params['silent'] ?? true),
            ],
            'get_battery' => [
                'detail_level' => 'full',
                'include_thermal' => true,
            ],
            'notify' => [
                'title' => (string)($params['title'] ?? 'Aegis Flow'),
                'body' => (string)($params['body'] ?? 'Command executed on macOS'),
                'sound' => $params['sound'] ?? 'default',
            ],
            'diagnostics' => [
                'tests' => is_array($params['tests'] ?? null) ? $params['tests'] : ['battery', 'storage', 'cpu', 'memory'],
            ],
            default => [],
        };
    }

    /**
     * Sanitize filesystem paths for macOS (prevent directory traversal).
     */
    private function sanitizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '~/aegis_temp';
        }

        // Remove any attempts at directory traversal
        $path = str_replace(['../', '..\\', "\0", '..'], '', $path);

        // If no absolute or home prefix, assume under home directory
        if (!str_starts_with($path, '/') && !str_starts_with($path, '~')) {
            $path = '~/' . ltrim($path, './');
        }

        return $path;
    }
}
