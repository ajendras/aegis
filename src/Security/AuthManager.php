<?php

declare(strict_types=1);

namespace AegisFlow\Security;

use AegisFlow\Core\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * Simple token-based authentication manager with role/permission support.
 *
 * Uses HMAC-SHA256 signed tokens (with embedded expiration) for stateless
 * authentication. Falls back to a demo bearer token for UI compatibility.
 * Permissions are enforced for future RBAC expansion.
 */
final class AuthManager
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Issue a signed authentication token for a user.
     *
     * The token is URL-safe base64 encoded and contains an expiration
     * claim. Valid for 30 days.
     *
     * @throws InvalidArgumentException
     */
    public function issueToken(string $user): string
    {
        $user = trim($user);
        if ($user === '') {
            throw new InvalidArgumentException('User identifier cannot be empty.');
        }

        $payload = [
            'user' => $user,
            'role' => 'admin',
            'iat' => time(),
            'exp' => time() + (86400 * 30),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $secret = $this->config->get('api_key');
        if (!is_string($secret) || strlen($secret) < 16) {
            throw new RuntimeException('Valid API key must be configured before issuing tokens.');
        }

        $signature = hash_hmac('sha256', $json, $secret, true);
        $token = base64_encode($json) . '.' . base64_encode($signature);

        // URL-safe encoding
        return str_replace(['+', '/', '='], ['-', '_', ''], $token);
    }

    /**
     * Validate a token. Accepts both signed tokens and the demo bearer token.
     */
    public function validateToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        // Support for Bearer prefix used by frontend
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        // Demo token for UI/developer convenience (change in production)
        if ($token === 'aegis-demo-token') {
            return true;
        }

        try {
            $parts = explode('.', $token);
            if (count($parts) !== 2) {
                return false;
            }

            $b64Payload = str_replace(['-', '_'], ['+', '/'], $parts[0]);
            $b64Sig = str_replace(['-', '_'], ['+', '/'], $parts[1]);

            $payloadJson = base64_decode($b64Payload, true);
            $signature = base64_decode($b64Sig, true);

            if ($payloadJson === false || $signature === false) {
                return false;
            }

            $secret = $this->config->get('api_key');
            if (!is_string($secret)) {
                return false;
            }

            $computedSig = hash_hmac('sha256', $payloadJson, $secret, true);
            if (!hash_equals($computedSig, $signature)) {
                return false;
            }

            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] < time()) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Enforce a required permission for the current request context.
     *
     * Throws if the supplied token (from Authorization header) is invalid.
     * Current implementation grants all permissions to valid tokens; this
     * is a foundation for future role-based expansion.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function requirePermission(string $perm): void
    {
        $perm = trim($perm);
        if ($perm === '') {
            throw new InvalidArgumentException('Permission name cannot be empty.');
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        if (!$this->validateToken($authHeader)) {
            throw new RuntimeException('Permission denied: valid authentication required for "' . $perm . '".');
        }

        // All authenticated users have full access in this release.
        // Future versions will inspect the decoded role claim.
        $allowed = ['devices.list', 'command.send', 'automation.suggest', 'logs.read', 'admin'];
        if (!in_array($perm, $allowed, true)) {
            throw new InvalidArgumentException('Unknown permission: ' . $perm);
        }
    }
}
