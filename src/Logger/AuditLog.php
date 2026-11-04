<?php

declare(strict_types=1);

namespace AegisFlow\Logger;

use AegisFlow\Storage\JsonRepo;
use InvalidArgumentException;
use RuntimeException;

/**
 * Append-only security audit log.
 *
 * Persists every significant action (registration, command dispatch,
 * responses, automation, WS events) to logs.json. The log is intentionally
 * immutable — entries are never updated, only appended. Query supports
 * simple substring filtering across action and context.
 */
final class AuditLog
{
    private JsonRepo $repo;

    /**
     * Maximum number of log entries to retain (prevents unbounded growth).
     */
    private const MAX_ENTRIES = 2000;

    public function __construct(JsonRepo $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Record an immutable audit entry.
     *
     * @param string $action The action identifier (e.g. 'command_dispatched')
     * @param array $context Arbitrary context data (device_id, command_type, etc.)
     * @param string $actor Who performed the action ('system', 'web', 'agent')
     *
     * @throws InvalidArgumentException
     */
    public function record(string $action, array $context = [], string $actor = 'system'): void
    {
        $action = trim($action);
        if ($action === '') {
            throw new InvalidArgumentException('Audit action cannot be empty.');
        }

        $actor = trim($actor);
        if ($actor === '') {
            $actor = 'system';
        }

        $entry = [
            'id' => 'log_' . bin2hex(random_bytes(8)),
            'timestamp' => time(),
            'action' => $action,
            'actor' => $actor,
            'context' => $context,
        ];

        // Add remote address when available from web context
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $entry['ip'] = $_SERVER['REMOTE_ADDR'];
        }

        $logs = $this->repo->read('logs');
        $logs[] = $entry;

        // Enforce retention limit (keep newest entries)
        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_ENTRIES);
        }

        $this->repo->write('logs', $logs);
    }

    /**
     * Query the audit log with optional free-text filter.
     *
     * Filter performs case-insensitive substring match against action
     * and the JSON-encoded context. Returns entries in reverse
     * chronological order (newest first).
     *
     * @param string $filter Optional filter string
     * @return array List of log entries
     */
    public function query(string $filter = ''): array
    {
        $logs = $this->repo->read('logs');

        if ($filter === '') {
            return $this->sortNewestFirst($logs);
        }

        $filter = strtolower(trim($filter));
        $matches = [];

        foreach ($logs as $log) {
            $searchable = strtolower($log['action'] ?? '');

            if (isset($log['context']) && is_array($log['context'])) {
                $searchable .= ' ' . strtolower(json_encode($log['context']));
            }

            if (str_contains($searchable, $filter)) {
                $matches[] = $log;
            }
        }

        return $this->sortNewestFirst($matches);
    }

    /**
     * Sort log entries by timestamp descending (newest first).
     *
     * @param array $logs
     * @return array
     */
    private function sortNewestFirst(array $logs): array
    {
        usort($logs, static fn (array $a, array $b): int =>
            ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0)
        );

        return $logs;
    }
}
