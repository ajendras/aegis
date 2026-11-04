<?php

declare(strict_types=1);

namespace AegisFlow\Automation;

use AegisFlow\Devices\Protocol;
use InvalidArgumentException;

/**
 * Keyword-based automation assistant.
 *
 * Provides a lightweight, deterministic "AI" that converts natural-language
 * goals into sequences of Aegis commands. No external LLM is used — matching
 * is performed against a curated keyword map with support for multi-step
 * workflows. The expand() method turns high-level steps into fully validated
 * command envelopes via Protocol.
 */
final class Assistant
{
    /**
     * @var array<string, array<array<string,mixed>>> Keyword to command template(s)
     */
    private const KEYWORD_MAP = [
        'battery' => [
            ['type' => 'get_battery', 'params' => ['detail_level' => 'full']],
        ],
        'screenshot' => [
            ['type' => 'screenshot', 'params' => ['format' => 'png', 'quality' => 90]],
        ],
        'screen' => [
            ['type' => 'screenshot', 'params' => ['format' => 'png']],
        ],
        'capture' => [
            ['type' => 'screenshot', 'params' => ['format' => 'png']],
        ],
        'shell' => [
            ['type' => 'shell', 'params' => ['cmd' => 'whoami']],
        ],
        'command' => [
            ['type' => 'shell', 'params' => ['cmd' => 'hostname']],
        ],
        'execute' => [
            ['type' => 'shell', 'params' => ['cmd' => 'echo "Aegis Flow ready"']],
        ],
        'run' => [
            ['type' => 'shell', 'params' => []],
        ],
        'push' => [
            ['type' => 'push', 'params' => ['content' => 'Hello from Aegis Flow automation']],
        ],
        'pull' => [
            ['type' => 'pull', 'params' => ['path' => '/Documents/report.pdf']],
        ],
        'install' => [
            ['type' => 'install', 'params' => ['url' => 'https://example.com/app.pkg']],
        ],
        'notify' => [
            ['type' => 'notify', 'params' => ['title' => 'Aegis Flow', 'body' => 'Automation step completed']],
        ],
        'alert' => [
            ['type' => 'notify', 'params' => ['title' => 'Alert', 'body' => 'Action required']],
        ],
        'diagnostics' => [
            ['type' => 'diagnostics', 'params' => []],
        ],
        'check' => [
            ['type' => 'diagnostics', 'params' => []],
        ],
        'status' => [
            ['type' => 'get_battery', 'params' => []],
            ['type' => 'diagnostics', 'params' => []],
        ],
        'backup' => [
            ['type' => 'notify', 'params' => ['title' => 'Backup', 'body' => 'Starting backup sequence']],
            ['type' => 'screenshot', 'params' => []],
            ['type' => 'pull', 'params' => ['path' => '/important/data']],
        ],
        'monitor' => [
            ['type' => 'get_battery', 'params' => []],
            ['type' => 'diagnostics', 'params' => []],
            ['type' => 'notify', 'params' => ['title' => 'Monitoring', 'body' => 'System snapshot captured']],
        ],
    ];

    private Protocol $protocol;

    public function __construct()
    {
        $this->protocol = new Protocol();
    }

    /**
     * Suggest a multi-step automation from a natural language goal.
     *
     * Performs simple keyword matching (case-insensitive) against the goal.
     * Multiple matching keywords produce a combined sequence. If nothing
     * matches, a safe default diagnostic + notification sequence is returned.
     *
     * @param string $goal Plain English description of desired automation
     * @return array<array<string,mixed>> List of step templates (type + params)
     *
     * @throws InvalidArgumentException
     */
    public function suggest(string $goal): array
    {
        $goal = strtolower(trim($goal));
        if ($goal === '') {
            throw new InvalidArgumentException('Automation goal cannot be empty.');
        }

        $suggestions = [];
        $keywordsFound = false;

        foreach (self::KEYWORD_MAP as $keyword => $steps) {
            if (str_contains($goal, $keyword)) {
                $suggestions = array_merge($suggestions, $steps);
                $keywordsFound = true;
            }
        }

        if (!$keywordsFound) {
            // Default safe fallback
            $suggestions = [
                ['type' => 'get_battery', 'params' => []],
                ['type' => 'notify', 'params' => [
                    'title' => 'Aegis Flow',
                    'body' => 'Goal received: ' . substr($goal, 0, 60) . '...',
                ]],
            ];
        }

        // Deduplicate while preserving order
        $seen = [];
        $unique = [];
        foreach ($suggestions as $step) {
            $signature = ($step['type'] ?? '') . json_encode($step['params'] ?? []);
            if (!isset($seen[$signature])) {
                $seen[$signature] = true;
                $unique[] = $step;
            }
        }

        return $unique;
    }

    /**
     * Expand high-level step definitions into fully formed, validated command envelopes.
     *
     * Each step is passed through Protocol::createCommand() so the resulting
     * array contains id, ts, protocol_version, normalized params, etc.
     *
     * @param array $steps Array of ['type' => string, 'params' => array] entries
     * @return array Fully expanded command envelopes
     *
     * @throws InvalidArgumentException
     */
    public function expand(array $steps): array
    {
        if (!is_array($steps)) {
            throw new InvalidArgumentException('Steps must be provided as an array.');
        }

        $expanded = [];

        foreach ($steps as $step) {
            if (!isset($step['type']) || !is_string($step['type']) || trim($step['type']) === '') {
                continue;
            }

            $params = $step['params'] ?? [];
            if (!is_array($params)) {
                $params = [];
            }

            try {
                $command = $this->protocol->createCommand($step['type'], $params);
                $expanded[] = $command;
            } catch (InvalidArgumentException $e) {
                // Silently skip unsupported command types so a partial
                // automation can still be returned.
            }
        }

        if (empty($expanded)) {
            throw new InvalidArgumentException('No valid commands could be generated from the supplied steps.');
        }

        return $expanded;
    }
}
