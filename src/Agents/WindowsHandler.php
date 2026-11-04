<?php

declare(strict_types=1);

namespace AegisFlow\Agents;

use InvalidArgumentException;

/**
 * Converts generic Aegis Flow commands into Windows-specific payloads.
 *
 * The Windows agent (typically a lightweight PowerShell-based or native
 * executable companion) expects an "action" key and a structured "payload".
 * This handler maps canonical command types to PowerShell-friendly structures,
 * performs input sanitization, and supplies safe defaults.
 */
final class WindowsHandler
{
    /**
     * @var array<string,string> Maps generic command types to Windows agent actions
     */
    private const ACTION_MAP = [
        'shell' => 'powershell_execute',
        'screenshot' => 'capture_screen',
        'push' => 'file_push',
        'pull' => 'file_pull',
        'install' => 'install_package',
        'get_battery' => 'get_system_info',
        'notify' => 'send_notification',
        'diagnostics' => 'run_diagnostics',
    ];

    /**
     * Translate a generic command into the exact structure expected by a Windows agent.
     *
     * @param array $cmd Command array containing at minimum a 'type' key
     * @return array Windows-specific payload ready for encryption and transmission
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $cmd): array
    {
        if (!isset($cmd['type']) || !is_string($cmd['type'])) {
            throw new InvalidArgumentException('WindowsHandler requires a string "type" key in the command.');
        }

        $type = strtolower(trim($cmd['type']));
        $params = $cmd['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        $commandId = $cmd['id'] ?? 'cmd-win-' . substr(bin2hex(random_bytes(6)), 0, 12);

        if (!array_key_exists($type, self::ACTION_MAP)) {
            throw new InvalidArgumentException(
                sprintf(
                    'WindowsHandler does not support command type "%s". Supported: %s.',
                    $type,
                    implode(', ', array_keys(self::ACTION_MAP))
                )
            );
        }

        $action = self::ACTION_MAP[$type];

        $payload = [
            'command_id' => $commandId,
            'action' => $action,
            'platform' => 'windows',
            'timestamp' => time(),
            'payload' => $this->buildPlatformPayload($type, $params),
        ];

        // Default timeout for Windows commands
        if (!isset($payload['payload']['timeout'])) {
            $payload['payload']['timeout'] = 30;
        }

        return $payload;
    }

    /**
     * Build the inner payload that the Windows agent will interpret.
     */
    private function buildPlatformPayload(string $type, array $params): array
    {
        return match ($type) {
            'shell' => [
                'script' => (string)($params['cmd'] ?? $params['command'] ?? 'Get-Host'),
                'as_admin' => (bool)($params['admin'] ?? $params['runas'] ?? false),
                'execution_policy' => $params['execution_policy'] ?? 'Bypass',
                'timeout' => (int)($params['timeout'] ?? 30),
            ],
            'screenshot' => [
                'format' => in_array(($params['format'] ?? ''), ['png', 'jpg', 'bmp'], true)
                    ? $params['format']
                    : 'png',
                'quality' => max(10, min(100, (int)($params['quality'] ?? 85))),
                'output_path' => $this->sanitizePath($params['path'] ?? '%TEMP%\aegis_screenshot.png'),
                'capture_cursor' => (bool)($params['cursor'] ?? true),
            ],
            'push' => [
                'destination' => $this->sanitizePath($params['destination'] ?? '%USERPROFILE%\Downloads\aegis_payload.bin'),
                'content' => (string)($params['content'] ?? 'Aegis Flow test payload'),
                'content_type' => $params['mime'] ?? $params['content_type'] ?? 'text/plain',
                'overwrite' => (bool)($params['overwrite'] ?? true),
            ],
            'pull' => [
                'source' => $this->sanitizePath($params['path'] ?? $params['source'] ?? '%USERPROFILE%\Documents\report.txt'),
                'max_size_bytes' => (int)($params['max_size'] ?? 10485760),
            ],
            'install' => [
                'package_url' => filter_var($params['url'] ?? '', FILTER_VALIDATE_URL) ?: '',
                'package_name' => (string)($params['package'] ?? ''),
                'silent' => (bool)($params['silent'] ?? true),
                'use_winget' => (bool)($params['winget'] ?? false),
            ],
            'get_battery' => [
                'detail_level' => 'full',
                'include_power_profile' => true,
            ],
            'notify' => [
                'title' => (string)($params['title'] ?? 'Aegis Flow'),
                'body' => (string)($params['body'] ?? 'Command executed successfully.'),
                'duration_ms' => (int)($params['duration'] ?? 8000),
                'severity' => in_array(($params['severity'] ?? ''), ['info', 'warning', 'error'], true)
                    ? $params['severity']
                    : 'info',
            ],
            'diagnostics' => [
                'tests' => is_array($params['tests'] ?? null)
                    ? $params['tests']
                    : ['cpu', 'memory', 'disk', 'battery', 'network'],
            ],
            default => [],
        };
    }

    /**
     * Sanitize filesystem paths for Windows (prevent directory traversal).
     */
    private function sanitizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '%TEMP%\aegis_temp';
        }

        // Remove traversal sequences
        $path = str_replace(['../', '..\\', "\0", '..'], '', $path);

        // Expand common environment variables if missing
        if (!str_contains($path, '%') && !str_starts_with($path, 'C:\\')) {
            $path = '%USERPROFILE%\\' . ltrim($path, '\\/');
        }

        return $path;
    }
}
