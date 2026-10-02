<?php

namespace App\Jira;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one Jira site, read with the API token from .env (CONCEPT.md §12).
 *
 * Read-only for now: nothing here can post, transition or assign. Every call goes
 * through send(), which retries what Jira asks to have retried (429, 5xx) and turns
 * a refusal into a JiraException that says what happened.
 */
class JiraClient
{
    /** What the overview needs of a ticket; the description and comments come later. */
    public const TICKET_FIELDS = ['summary', 'status', 'priority', 'issuetype', 'reporter', 'assignee', 'created', 'updated'];

    public function __construct(
        private readonly string $base,
        private readonly ?string $email,
        private readonly ?string $token,
    ) {}

    public function configured(): bool
    {
        return filled($this->base) && filled($this->email) && filled($this->token);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function browseUrl(string $key): string
    {
        return $this->base.'/browse/'.$key;
    }

    /** @return array{accountId?: string, displayName?: string} */
    public function myself(): array
    {
        return $this->send('get', '/rest/api/3/myself');
    }

    /**
     * Every space the account can see.
     *
     * @return list<array{id: string, key: string, name: string, type: string}>
     */
    public function projects(): array
    {
        $projects = [];
        $startAt = 0;

        do {
            $page = $this->send('get', '/rest/api/3/project/search', ['startAt' => $startAt, 'maxResults' => 50, 'orderBy' => 'name']);
            $values = $page['values'] ?? [];

            foreach ($values as $project) {
                $projects[] = $this->projectSummary($project);
            }

            $startAt += count($values);
        } while (! ($page['isLast'] ?? true) && $values !== []);

        return $projects;
    }

    /** @return array{id: string, key: string, name: string, type: string, issueTypes: list<string>} */
    public function project(string $key): array
    {
        $project = $this->send('get', '/rest/api/3/project/'.rawurlencode($key));

        return $this->projectSummary($project) + [
            'issueTypes' => collect($project['issueTypes'] ?? [])->pluck('name')->unique()->values()->all(),
        ];
    }

    /**
     * The space's own words, to build ticket rules from.
     *
     * @return array{issueTypes: list<string>, statuses: list<array{name: string, category: ?string}>, components: list<string>}
     */
    public function vocabulary(string $key): array
    {
        $path = '/rest/api/3/project/'.rawurlencode($key);

        return [
            'issueTypes' => $this->project($key)['issueTypes'],
            'statuses' => collect($this->send('get', $path.'/statuses'))
                ->flatMap(fn ($issueType) => $issueType['statuses'] ?? [])
                ->map(fn ($status) => ['name' => (string) $status['name'], 'category' => $status['statusCategory']['key'] ?? null])
                ->unique('name')->sortBy('name')->values()->all(),
            'components' => collect($this->send('get', $path.'/components'))
                ->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * Whether the account holds each permission in the space.
     *
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public function permissions(string $projectKey, array $keys): array
    {
        $answer = $this->send('get', '/rest/api/3/mypermissions', [
            'projectKey' => $projectKey,
            'permissions' => implode(',', $keys),
        ]);

        return collect($keys)
            ->mapWithKeys(fn ($key) => [$key => (bool) ($answer['permissions'][$key]['havePermission'] ?? false)])
            ->all();
    }

    /**
     * One page of a JQL search.
     *
     * @param  list<string>  $fields
     * @return array{issues: list<array>, next: ?string}
     */
    public function search(string $jql, array $fields, ?string $next = null, int $max = 100): array
    {
        $page = $this->send('post', '/rest/api/3/search/jql', array_filter([
            'jql' => $jql,
            'fields' => $fields,
            'maxResults' => $max,
            'nextPageToken' => $next,
        ], fn ($value) => $value !== null));

        return [
            'issues' => $page['issues'] ?? [],
            'next' => ($page['isLast'] ?? true) ? null : ($page['nextPageToken'] ?? null),
        ];
    }

    /** Jira's estimate of how many issues match, or null when it will not say. */
    public function count(string $jql): ?int
    {
        try {
            $answer = $this->send('post', '/rest/api/3/search/approximate-count', ['jql' => $jql]);
        } catch (JiraException) {
            return null;
        }

        return isset($answer['count']) ? (int) $answer['count'] : null;
    }

    private function projectSummary(array $project): array
    {
        return [
            'id' => (string) ($project['id'] ?? ''),
            'key' => (string) ($project['key'] ?? ''),
            'name' => (string) ($project['name'] ?? ''),
            'type' => (string) ($project['projectTypeKey'] ?? 'software'),
        ];
    }

    private function send(string $method, string $path, array $data = []): array
    {
        if (! $this->configured()) {
            throw new JiraException('Jira is not set up: set JIRA_BASE, JIRA_EMAIL and JIRA_TOKEN in shared/.env.');
        }

        try {
            $response = $this->request()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new JiraException('Jira did not answer: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw JiraException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->base)
            ->withBasicAuth((string) $this->email, (string) $this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(3, fn (int $attempt, Throwable $e) => $this->delay($attempt, $e), fn (Throwable $e) => $this->retryable($e), throw: false);
    }

    private function retryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->status() === 429 || $e->response->serverError());
    }

    /** Milliseconds to wait: what Jira asks for when it says, otherwise a little longer each time. */
    private function delay(int $attempt, Throwable $e): int
    {
        $asked = $e instanceof RequestException ? (int) $e->response->header('Retry-After') : 0;

        return $asked > 0 ? min($asked, 30) * 1000 : 250 * $attempt;
    }
}
