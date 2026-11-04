<?php

declare(strict_types=1);

namespace AegisFlow\Core;

use AegisFlow\Devices\Registry;
use AegisFlow\Security\AuthManager;
use AegisFlow\Logger\AuditLog;
use AegisFlow\Storage\JsonRepo;
use AegisFlow\Commands\Dispatcher;
use AegisFlow\Automation\Assistant;
use AegisFlow\Connection\Hub;
use Exception;
use InvalidArgumentException;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use RuntimeException;

/**
 * Core Application bootstrapper and HTTP request handler.
 *
 * Coordinates Config, Registry, Auth, Logging and dispatches
 * commands and automation requests. Starts Ratchet WebSocket
 * server when run from CLI.
 */
final class Application
{
    private Config $config;
    private Registry $registry;
    private AuthManager $auth;
    private AuditLog $auditLog;
    private JsonRepo $repo;

    public function __construct()
    {
        $this->repo = new JsonRepo();
        $this->config = new Config();
        $this->registry = new Registry($this->repo);
        $this->auth = new AuthManager($this->config);
        $this->auditLog = new AuditLog($this->repo);

        // Ensure encryption seed exists
        if (empty($this->config->get('encryption_seed'))) {
            $this->config->setEncryptionSeed('aegis-flow-master-seed-' . bin2hex(random_bytes(16)));
        }

        $this->auditLog->record('application_boot', [
            'php_version' => PHP_VERSION,
            'mode' => php_sapi_name()
        ]);
    }

    /**
     * Run the application.
     *
     * Starts WebSocket server when executed from CLI.
     * Otherwise acts as a no-op for HTTP front-controller.
     */
    public function run(): void
    {
        if (php_sapi_name() === 'cli') {
            $this->startWebSocketServer();
        }
    }

    private function startWebSocketServer(): void
    {
        $port = (int) $this->config->get('ws_port', 8081);

        echo "Aegis Flow WebSocket Hub starting on port {$port}...\n";
        echo "Press Ctrl+C to stop.\n\n";

        $hub = new Hub($this->registry, $this->config, $this->auditLog);

        $server = IoServer::factory(
            new HttpServer(
                new WsServer($hub)
            ),
            $port
        );

        $server->run();
    }

    /**
     * Return structured data for the dashboard view.
     *
     * @return array{
     *   devices: array,
     *   online_count: int,
     *   total_devices: int,
     *   logs: array,
     *   theme: string
     * }
     */
    public function getDashboardData(): array
    {
        $allDevices = $this->registry->all();

        $online = 0;
        foreach ($allDevices as &$device) {
            $lastSeen = $device['last_seen'] ?? 0;
            $device['relative_time'] = $this->relativeTime($lastSeen);
            $device['is_online'] = (time() - $lastSeen) < 300;
            if ($device['is_online']) {
                $online++;
            }
        }

        $recentLogs = $this->auditLog->query('');
        if (count($recentLogs) > 15) {
            $recentLogs = array_slice($recentLogs, 0, 15);
        }

        return [
            'devices' => $allDevices,
            'online_count' => $online,
            'total_devices' => count($allDevices),
            'logs' => $recentLogs,
            'theme' => $this->config->get('theme', 'dark'),
            'connection_status' => 'ready'
        ];
    }

    /**
     * Handle all API HTTP requests.
     *
     * @param string $uri The requested URI (starting with /api/)
     * @param array $post Parsed POST/GET payload
     * @return array Response payload (will be JSON encoded by front controller)
     */
    public function handleHttp(string $uri, array $post): array
    {
        $path = trim($uri, '/');
        $segments = explode('/', $path);

        if ($segments[0] !== 'api') {
            return ['error' => 'Invalid API route'];
        }

        $action = $segments[1] ?? '';

        try {
            // All routes except status require valid token
            if ($action !== 'status' && !$this->auth->validateToken($post['token'] ?? '')) {
                return [
                    'error' => 'Unauthorized',
                    'code' => 401
                ];
            }

            return match ($action) {
                'devices' => $this->handleDevicesEndpoint($post),
                'command' => $this->handleCommandEndpoint($post),
                'automation', 'suggest' => $this->handleAutomationEndpoint($post),
                'logs' => $this->handleLogsEndpoint($post),
                'status' => ['status' => 'ok', 'version' => '0.1.0'],
                default => ['error' => 'Unknown endpoint: ' . $action]
            };
        } catch (InvalidArgumentException $e) {
            return [
                'error' => 'Validation error',
                'message' => $e->getMessage()
            ];
        } catch (Exception $e) {
            $this->auditLog->record('http_error', [
                'uri' => $uri,
                'exception' => $e->getMessage()
            ]);

            return [
                'error' => 'Internal error',
                'message' => 'An unexpected error occurred.'
            ];
        }
    }

    private function handleDevicesEndpoint(array $post): array
    {
        $tag = $post['tag'] ?? '';
        if ($tag !== '') {
            return ['devices' => $this->registry->byTag($tag)];
        }

        return ['devices' => $this->registry->all()];
    }

    private function handleCommandEndpoint(array $post): array
    {
        $deviceId = trim($post['device_id'] ?? '');
        $type = trim($post['type'] ?? '');
        $params = $post['params'] ?? [];

        if ($deviceId === '' || $type === '') {
            throw new InvalidArgumentException('device_id and type are required');
        }

        if (!$this->registry->find($deviceId)) {
            return ['error' => 'Device not found', 'device_id' => $deviceId];
        }

        $dispatcher = new Dispatcher($this->registry, $this->config, $this->auditLog);
        $success = $dispatcher->dispatch($deviceId, $type, (array) $params);

        $this->auditLog->record('command_sent', [
            'device_id' => $deviceId,
            'type' => $type,
            'params' => array_keys($params)
        ], 'web');

        return [
            'success' => $success,
            'message' => $success ? 'Command accepted by hub' : 'Command could not be dispatched'
        ];
    }

    private function handleAutomationEndpoint(array $post): array
    {
        $goal = trim($post['goal'] ?? '');
        if ($goal === '') {
            throw new InvalidArgumentException('Goal text is required for automation suggestion');
        }

        $assistant = new Assistant();
        $suggestions = $assistant->suggest($goal);

        $this->auditLog->record('ai_suggestion', ['goal' => $goal], 'web');

        return [
            'suggestions' => $suggestions,
            'goal' => $goal
        ];
    }

    private function handleLogsEndpoint(array $post): array
    {
        $filter = $post['filter'] ?? '';
        return ['logs' => $this->auditLog->query($filter)];
    }

    private function relativeTime(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 30) {
            return 'just now';
        }

        if ($diff < 3600) {
            return (int)($diff / 60) . 'm ago';
        }

        if ($diff < 86400) {
            return (int)($diff / 3600) . 'h ago';
        }

        return (int)($diff / 86400) . 'd ago';
    }
}
