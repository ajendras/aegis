<?php

declare(strict_types=1);

/**
 * Aegis Flow Agent Simulator (CLI Demo)
 *
 * This script connects to the local WebSocket hub (ws://localhost:8081),
 * registers a device, sends periodic heartbeats, and responds to incoming
 * commands with realistic mock payloads depending on the selected platform.
 *
 * Usage:
 *   php examples/agent-simulator.php --platform=android --name="Test Pixel 8"
 *   php examples/agent-simulator.php --platform=windows --name="Test Laptop" --tags=lab,dev
 *
 * Press Ctrl+C to stop.
 */

if (php_sapi_name() !== 'cli') {
    exit("This script must be executed from the command line.\n");
}

$options = getopt('', ['platform:', 'name:', 'tags:']);

$platform = strtolower($options['platform'] ?? 'android');
if (!in_array($platform, ['android', 'ios', 'windows'], true)) {
    $platform = 'android';
}

$name = $options['name'] ?? ucfirst($platform) . ' Simulator';
$tags = isset($options['tags'])
    ? array_map('trim', explode(',', $options['tags']))
    : ['simulator', 'demo'];

$wsHost = 'localhost';
$wsPort = 8081;
$deviceId = null;

echo "=== Aegis Flow Agent Simulator ===\n";
echo "Platform : {$platform}\n";
echo "Name     : {$name}\n";
echo "Tags     : " . implode(', ', $tags) . "\n";
echo "Connecting to ws://{$wsHost}:{$wsPort}...\n\n";

$socket = null;
try {
    $socket = connectWebSocket("ws://{$wsHost}:{$wsPort}");
    echo "✓ Connected. Performing handshake...\n";

    // Register with the hub
    $registerMessage = [
        'type' => 'register',
        'platform' => $platform,
        'name' => $name,
        'os_version' => $platform === 'android' ? '14' : ($platform === 'ios' ? '17.2' : '11.0'),
        'tags' => $tags,
    ];

    sendJson($socket, $registerMessage);
    echo "✓ Registration sent.\n";

    $lastHeartbeat = time();
    $running = true;

    pcntl_signal(SIGINT, function () use (&$running) {
        echo "\nShutting down simulator...\n";
        $running = false;
    });

    while ($running) {
        // Non-blocking check for incoming messages
        $read = [$socket];
        $write = $except = [];
        $tv = 0;

        if (stream_select($read, $write, $except, 0, 200000) > 0) {
            $frame = receiveFrame($socket);
            if ($frame !== null) {
                handleIncomingFrame($frame, $platform, $socket, $deviceId);
            }
        }

        // Heartbeat every 15 seconds
        if (time() - $lastHeartbeat > 15) {
            $heartbeat = [
                'type' => 'heartbeat',
                'device_id' => $deviceId,
                'battery' => rand(65, 98),
                'status' => 'online',
            ];
            sendJson($socket, $heartbeat);
            $lastHeartbeat = time();
            echo "→ Heartbeat sent (battery ~" . $heartbeat['battery'] . "%)\n";
        }

        usleep(100000); // 100ms
    }
} catch (Throwable $e) {
    echo "\nError: " . $e->getMessage() . "\n";
} finally {
    if ($socket) {
        fclose($socket);
    }
    echo "Simulator terminated.\n";
}

/**
 * Establish a basic WebSocket connection with HTTP upgrade.
 */
function connectWebSocket(string $url): resource
{
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? 'localhost';
    $port = $parsed['port'] ?? 8081;
    $path = $parsed['path'] ?? '/';

    $key = base64_encode(random_bytes(16));

    $header = "GET {$path} HTTP/1.1\r\n";
    $header .= "Host: {$host}:{$port}\r\n";
    $header .= "Upgrade: websocket\r\n";
    $header .= "Connection: Upgrade\r\n";
    $header .= "Sec-WebSocket-Key: {$key}\r\n";
    $header .= "Sec-WebSocket-Version: 13\r\n\r\n";

    $socket = fsockopen($host, $port, $errno, $errstr, 5.0);
    if (!$socket) {
        throw new RuntimeException("Failed to connect to {$host}:{$port} — {$errstr}");
    }

    fwrite($socket, $header);
    $response = fread($socket, 4096);

    if (!str_contains($response, '101 Switching Protocols')) {
        fclose($socket);
        throw new RuntimeException('WebSocket handshake failed. Is the hub running on port 8081?');
    }

    stream_set_blocking($socket, false);
    return $socket;
}

/**
 * Send a JSON message as a WebSocket text frame.
 */
function sendJson($socket, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    sendFrame($socket, $json);
}

/**
 * Send a WebSocket text frame (masked).
 */
function sendFrame($socket, string $data): void
{
    $length = strlen($data);
    $mask = random_bytes(4);

    $frame = "\x81"; // FIN + text opcode

    if ($length < 126) {
        $frame .= chr($length | 0x80);
    } elseif ($length < 0xFFFF) {
        $frame .= chr(126 | 0x80) . pack('n', $length);
    } else {
        $frame .= chr(127 | 0x80) . pack('J', $length);
    }

    $frame .= $mask;

    for ($i = 0; $i < $length; $i++) {
        $frame .= $data[$i] ^ $mask[$i % 4];
    }

    fwrite($socket, $frame);
}

/**
 * Receive and decode a basic WebSocket frame.
 * Returns the payload string or null on failure/empty.
 */
function receiveFrame($socket): ?string
{
    $data = fread($socket, 2048);
    if ($data === false || strlen($data) < 2) {
        return null;
    }

    $firstByte = ord($data[0]);
    $opcode = $firstByte & 0x0F;

    if ($opcode === 0x8) {
        // Connection close
        return null;
    }

    $secondByte = ord($data[1]);
    $masked = (bool)($secondByte & 0x80);
    $payloadLength = $secondByte & 0x7F;

    $offset = 2;

    if ($payloadLength === 126) {
        $payloadLength = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
    } elseif ($payloadLength === 127) {
        $payloadLength = unpack('J', substr($data, $offset, 8))[1];
        $offset += 8;
    }

    if ($masked) {
        $mask = substr($data, $offset, 4);
        $offset += 4;
    }

    $payload = substr($data, $offset, $payloadLength);

    if ($masked && isset($mask)) {
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $unmasked;
    }

    return $payload;
}

/**
 * Handle any message received from the hub.
 */
function handleIncomingFrame(string $frame, string $platform, $socket, ?string &$deviceId): void
{
    $data = json_decode($frame, true);

    if (!is_array($data)) {
        echo "Received non-JSON frame.\n";
        return;
    }

    if (isset($data['type'])) {
        if ($data['type'] === 'registered' && isset($data['device_id'])) {
            $GLOBALS['deviceId'] = $deviceId = $data['device_id'];
            echo "✓ Registered with ID: {$deviceId}\n";
            echo "Simulator is now online and ready to receive commands.\n\n";
        } elseif ($data['type'] === 'welcome') {
            echo "Received welcome from hub.\n";
        }
        return;
    }

    // Assume any other message may be an encrypted command.
    // For the simulator we treat it as a command and respond with a mock.
    echo "← Command received from hub (encrypted payload)\n";

    $mockResponse = generateMockResponse($platform);

    // In a real agent this would be encrypted with ChannelEncryptor::encryptFor
    // For the demo we send a JSON response that the Hub can process.
    $responseEnvelope = [
        'id' => $mockResponse['command_id'] ?? 'cmd_' . bin2hex(random_bytes(4)),
        'status' => 'success',
        'command_type' => $mockResponse['action'] ?? 'unknown',
        'result' => $mockResponse,
        'battery' => rand(60, 95),
    ];

    sendJson($socket, $responseEnvelope);
    echo "→ Mock response sent for {$platform} command.\n\n";
}

/**
 * Generate realistic mock responses depending on platform and command type.
 */
function generateMockResponse(string $platform): array
{
    $action = match (rand(1, 5)) {
        1 => 'capture_screen',
        2 => 'execute_shell',
        3 => 'query_battery',
        4 => 'send_notification',
        default => 'run_diagnostics',
    };

    $base = [
        'command_id' => 'rsp_' . bin2hex(random_bytes(6)),
        'action' => $action,
        'platform' => $platform,
        'success' => true,
        'timestamp' => time(),
    ];

    return match ($platform) {
        'android' => array_merge($base, [
            'payload' => [
                'output' => $action === 'execute_shell'
                    ? 'uid=2000(aegis) gid=2000(aegis)'
                    : 'Mock Android response',
                'exit_code' => 0,
            ],
        ]),
        'ios' => array_merge($base, [
            'payload' => [
                'result' => $action === 'capture_screenshot'
                    ? 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAJ/0O8F5y0XwAAAABJRU5ErkJggg=='
                    : 'iOS command completed',
            ],
        ]),
        'windows' => array_merge($base, [
            'payload' => [
                'stdout' => $action === 'powershell_execute'
                    ? 'Windows PowerShell 5.1 ready'
                    : 'Mock Windows output',
                'exit_code' => 0,
            ],
        ]),
        default => $base,
    };
}
