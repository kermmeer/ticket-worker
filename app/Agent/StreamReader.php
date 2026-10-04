<?php

namespace App\Agent;

/**
 * Reads Claude Code's stream-json output, one JSON object per line, into what the page
 * shows: what the agent says, which tools it uses, and the final result with its cost.
 */
class StreamReader
{
    private string $buffer = '';

    /** @var array<string, string> long paths shortened for the page: prefix => replacement */
    public function __construct(private readonly array $shorten = []) {}

    /**
     * Feed raw output; get back the complete lines' events.
     *
     * @return list<array{type: string, summary?: string, result?: array}>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $newline));
            $this->buffer = substr($this->buffer, $newline + 1);
            if ($line !== '') {
                array_push($events, ...$this->line($line));
            }
        }

        return $events;
    }

    /** What is left once the process ends. */
    public function finish(): array
    {
        $rest = trim($this->buffer);
        $this->buffer = '';

        return $rest === '' ? [] : $this->line($rest);
    }

    private function line(string $line): array
    {
        $message = json_decode($line, true);
        if (! is_array($message)) {
            return [];
        }

        if (($message['type'] ?? null) === 'result') {
            return [['type' => 'result', 'result' => $message]];
        }

        if (($message['type'] ?? null) !== 'assistant') {
            return [];
        }

        $events = [];
        foreach ($message['message']['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && trim($block['text'] ?? '') !== '') {
                $events[] = ['type' => 'text', 'summary' => trim($block['text'])];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $events[] = ['type' => 'tool', 'summary' => $this->tool($block['name'] ?? '?', $block['input'] ?? [])];
            }
        }

        return $events;
    }

    private function tool(string $name, array $input): string
    {
        $where = $this->short((string) ($input['file_path'] ?? $input['path'] ?? ''));

        return match ($name) {
            'Read' => 'reading '.$where,
            'Grep' => 'searching "'.($input['pattern'] ?? '').'"'.($where !== '' ? ' in '.$where : ''),
            'Glob' => 'listing '.($input['pattern'] ?? '').($where !== '' ? ' in '.$where : ''),
            default => strtolower($name).' '.$where,
        };
    }

    private function short(string $path): string
    {
        foreach ($this->shorten as $prefix => $replacement) {
            if (str_starts_with($path, $prefix)) {
                return $replacement.ltrim(substr($path, strlen($prefix)), '/');
            }
        }

        return $path;
    }
}
