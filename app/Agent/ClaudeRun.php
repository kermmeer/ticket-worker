<?php

namespace App\Agent;

use Illuminate\Support\Facades\Process;

/**
 * Runs Claude Code once, headless, read-only, and reports each step as it happens.
 * Shared by ticket turns and system scans (CONCEPT.md §10).
 */
class ClaudeRun
{
    /**
     * The options every run gets: streamed output, the configured model, and read-only
     * tools only. No shell, no network, no writing; nothing that would ask anyone.
     */
    /**
     * The tokens a run used, from its result line: what it read (fresh, from the cache,
     * into the cache) and what it wrote.
     *
     * @return array{input_tokens: ?int, cache_read_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int}
     */
    public static function tokens(?array $result): array
    {
        $usage = $result['usage'] ?? [];
        $count = fn (string $key) => isset($usage[$key]) ? (int) $usage[$key] : null;

        return [
            'input_tokens' => $count('input_tokens'),
            'cache_read_tokens' => $count('cache_read_input_tokens'),
            'cache_write_tokens' => $count('cache_creation_input_tokens'),
            'output_tokens' => $count('output_tokens'),
        ];
    }

    public static function command(float $budgetUsd, bool $write = false, array $mcpTools = []): array
    {
        // Writing is for patch turns only. Restricted mode keeps it inside the workspace,
        // and the systems are read-only mounts besides.
        $tools = $write ? 'Read,Grep,Glob,Edit,Write' : 'Read,Grep,Glob';
        // The tool's own MCP tools (API calls) are allowed by name, the built-in ones by --tools.
        $allowed = implode(',', [$tools, ...$mcpTools]);

        return [config('agent.claude.bin'), '-p',
            '--output-format', 'stream-json', '--verbose',
            '--model', config('agent.claude.model'), '--effort', config('agent.claude.effort'),
            '--restricted', '--strict-mcp-config',
            '--tools', $tools,
            '--allowedTools', $allowed,
            '--permission-prompts', 'none',
            '--max-budget-usd', (string) $budgetUsd,
        ];
    }

    /**
     * @param  callable(string $type, string $summary): void  $onStep
     * @param  callable(): bool  $shouldStop  asked every two seconds
     * @return array{result: ?array, errors: string, stopped: bool}
     */
    public function run(array $command, string $cwd, string $prompt, array $shorten, callable $onStep, callable $shouldStop, int $timeout): array
    {
        $reader = new StreamReader($shorten);

        // The prompt goes in on stdin: every option takes a value, and a prompt left as
        // the last argument could be read as one of them.
        $process = Process::path($cwd)
            ->command($command)
            ->env(Instructions::credentials())
            ->input($prompt)
            ->timeout($timeout)
            ->start();

        $result = null;
        $stopped = false;
        $lastCheck = microtime(true);

        $take = function (array $events) use (&$result, $onStep) {
            foreach ($events as $event) {
                if ($event['type'] === 'result') {
                    $result = $event['result'];
                } else {
                    $onStep($event['type'], $event['summary']);
                }
            }
        };

        while ($process->running()) {
            $take($reader->feed($process->latestOutput()));

            if (microtime(true) - $lastCheck > 2) {
                $lastCheck = microtime(true);
                if ($shouldStop()) {
                    // SIGINT, then SIGKILL, by number: the image has no pcntl constants.
                    $process->signal(2);
                    usleep(1500000);
                    if ($process->running()) {
                        $process->signal(9);
                    }
                    $stopped = true;
                    break;
                }
            }
            usleep(300000);
        }

        $take($reader->feed($process->latestOutput()));
        $finished = $process->wait();
        $take($reader->finish());

        return ['result' => $result, 'errors' => $finished->errorOutput(), 'stopped' => $stopped];
    }
}
