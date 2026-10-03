<?php

namespace App\Outbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * jira-outbox, where Ticket Worker's replies wait as drafts until you send them
 * (docs/OUTBOX-API.md). Ticket Worker never posts to Jira itself.
 */
class OutboxClient
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
    ) {}

    public function configured(): bool
    {
        return filled($this->url) && filled($this->token);
    }

    /**
     * Hand a reply over as a draft. The same externalId again updates that draft
     * while it is still one.
     *
     * @param  'public'|'internal'  $visibility
     * @return array{id: string, state: string, url: string}
     */
    public function draft(string $externalId, string $issueKey, string $body, string $visibility, string $sourceUrl): array
    {
        return $this->send('post', '/api/v1/drafts', [
            'externalId' => $externalId,
            'issueKey' => $issueKey,
            'body' => $body,
            'visibility' => $visibility,
            'source' => ['name' => 'Ticket Worker', 'url' => $sourceUrl],
        ]);
    }

    /**
     * Every message still to go out, by ticket key: drafts, scheduled and failed ones,
     * whoever wrote them. Empty when the outbox is not set up or does not answer: the
     * overview works without it.
     *
     * @return array<string, list<array>>
     */
    public function waiting(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $messages = $this->send('get', '/api/v1/scheduled')['messages'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        return collect($messages)->groupBy('issueKey')->map->values()->map->all()->all();
    }

    /** What became of a draft: draft, scheduled, sent, failed or discarded. */
    public function status(string $id): array
    {
        return $this->send('get', '/api/v1/drafts/'.rawurlencode($id));
    }

    /**
     * Whether the outbox answers and takes the token, without creating anything: asking
     * for a draft that does not exist gives 404 with a good token and 401 with a bad one.
     */
    public function check(): ?string
    {
        if (! $this->configured()) {
            return 'Not set up: OUTBOX_URL and OUTBOX_API_TOKEN in shared/.env.';
        }

        try {
            $status = $this->request()->get('/api/v1/drafts/connection-check')->status();
        } catch (ConnectionException) {
            return "No answer from {$this->url}.";
        }

        return match ($status) {
            404 => null,
            401, 403 => 'The outbox refused the token.',
            default => "The outbox answered {$status}.",
        };
    }

    private function send(string $method, string $path, array $data = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The outbox is not set up: OUTBOX_URL and OUTBOX_API_TOKEN in shared/.env.');
        }

        $response = $this->request()->{$method}($path, $data);

        if ($response->failed()) {
            throw new RuntimeException('The outbox answered '.$response->status().': '.($response->json('error') ?? $response->body()));
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) $this->url)->withToken((string) $this->token)->acceptJson()->asJson()->timeout(15);
    }
}
