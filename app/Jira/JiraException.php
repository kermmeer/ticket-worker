<?php

namespace App\Jira;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Something Jira refused or did not answer, said in one readable sentence. Jira's own
 * messages are kept; the request, and so the credentials, never are.
 */
class JiraException extends RuntimeException
{
    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];
        $messages = array_merge(
            array_values(array_filter((array) ($body['errorMessages'] ?? []), 'is_string')),
            array_map(fn ($field, $message) => "{$field}: {$message}", array_keys((array) ($body['errors'] ?? [])), (array) ($body['errors'] ?? [])),
        );
        $detail = Str::limit(implode(' ', $messages), 400);
        $status = $response->status();

        $sentence = match (true) {
            $status === 401 => 'Jira refused the credentials (401). Check JIRA_EMAIL and JIRA_TOKEN.',
            $status === 403 => 'Jira says this account may not do that (403).',
            $status === 404 => 'Jira does not know it, or this account cannot see it (404).',
            $status === 429 => 'Jira asked to slow down (429) and kept asking.',
            default => "Jira answered {$status}.",
        };

        return new self(trim($sentence.' '.$detail));
    }
}
