<?php

declare(strict_types=1);

use AegisFlow\Core\Application;
use AegisFlow\Panel\View;
use Exception;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    // Strict security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.tailwindcss.com; style-src \'self\' \'unsafe-inline\'; connect-src \'self\' ws://localhost:8081 wss:; img-src \'self\' data: blob:; font-src \'self\';');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Cache-Control: no-store, must-revalidate');

    if (isset($_SERVER['HTTP_ORIGIN'])) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $app = new Application();

    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($uri === '/' || $uri === '/index.php' || $uri === '') {
        // Dashboard route
        $data = $app->getDashboardData();
        $view = new View();
        echo $view->render($data);
        exit;
    }

    if (str_starts_with($uri, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');

        $payload = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput !== false && $rawInput !== '') {
                $jsonPayload = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($jsonPayload)) {
                    $payload = $jsonPayload;
                }
            }
            $payload = array_merge($payload, $_POST);
        } elseif ($method === 'GET') {
            $payload = $_GET;
        }

        // Basic auth check - full implementation lives in AuthManager
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
        if (str_contains($uri, '/command') && empty($token)) {
            http_response_code(401);
            echo json_encode(['error' => 'Missing or invalid authorization token'], JSON_THROW_ON_ERROR);
            exit;
        }

        $response = $app->handleHttp($uri, $payload);
        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // 404 for unknown routes
    http_response_code(404);
    if (str_starts_with($uri, '/api/')) {
        echo json_encode(['error' => 'API endpoint not found', 'path' => $uri], JSON_THROW_ON_ERROR);
    } else {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>404 - Not Found</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-950 text-slate-200 flex items-center justify-center h-screen"><div class="text-center"><h1 class="text-6xl font-bold text-lime-400 mb-4">404</h1><p class="text-xl">Page not found</p><a href="/" class="mt-8 inline-block px-6 py-3 bg-lime-500 hover:bg-lime-600 text-slate-950 font-semibold rounded-2xl transition">Return to Dashboard</a></div></body></html>';
    }
} catch (Exception $e) {
    error_log('[AegisFlow] Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $isApi = isset($uri) && str_starts_with($uri, '/api/');
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'error' => 'Internal server error',
            'message' => 'An unexpected error occurred. Check server logs for details.'
        ], JSON_THROW_ON_ERROR);
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-950 text-slate-200 flex items-center justify-center h-screen"><div class="max-w-md mx-auto text-center"><div class="bg-red-500/10 border border-red-400 text-red-400 p-8 rounded-3xl"><h1 class="text-2xl font-semibold mb-3">Server Error</h1><p class="mb-6 text-slate-400">' . htmlspecialchars($e->getMessage()) . '</p><a href="/" class="inline-flex items-center px-5 py-2.5 bg-white text-slate-900 rounded-2xl font-medium hover:bg-amber-200 transition">Return Home</a></div></div></body></html>';
    }
}
