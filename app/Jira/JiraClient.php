<?php

namespace App\Jira;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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
        private readonly ?string $openUrl = null,
    ) {}

    public function configured(): bool
    {
        return filled($this->base) && filled($this->email) && filled($this->token);
    }

    /**
     * The first name of the account the app reads Jira with: replies are signed with it
     * ({first_name} in the reply rules). Asked once a day; null when Jira is not set up
     * or does not answer, and then asked again next time.
     */
    public function firstName(): ?string
    {
        if (filled($name = Cache::get('jira.first_name'))) {
            return $name;
        }
        if (! $this->configured()) {
            return null;
        }

        try {
            $name = strtok(trim((string) ($this->myself()['displayName'] ?? '')), ' ') ?: null;
        } catch (Throwable) {
            return null;
        }

        if ($name !== null) {
            Cache::put('jira.first_name', $name, now()->addDay());
        }

        return $name;
    }

    public function base(): string
    {
        return $this->base;
    }

    /** Where clicking a ticket goes: JIRA_OPEN_URL with {key} filled in, or Jira itself. */
    public function browseUrl(string $key): string
    {
        return filled($this->openUrl)
            ? str_replace('{key}', rawurlencode($key), $this->openUrl)
            : $this->base.'/browse/'.$key;
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

    /**
     * One ticket in full, for the agent and the ticket page. API v2, so the description and
     * comments come as text (wiki markup) rather than Atlassian's document format.
     *
     * @return array{key: string, summary: string, description: string, status: ?string, priority: ?string,
     *     type: ?string, reporter: ?string, assignee: ?string, created: ?string, updated: ?string,
     *     comments: list<array{author: ?string, created: ?string, body: string, public: bool}>,
     *     attachments: list<array{id: string, filename: string, size: int, mime: ?string, url: string, created: ?string}>}
     */
    public function issue(string $key): array
    {
        $fields = $this->send('get', '/rest/api/2/issue/'.rawurlencode($key), [
            'fields' => 'summary,description,status,priority,issuetype,reporter,assignee,created,updated,attachment',
        ])['fields'] ?? [];

        $comments = [];
        $startAt = 0;
        do {
            $page = $this->send('get', '/rest/api/2/issue/'.rawurlencode($key).'/comment', [
                'startAt' => $startAt, 'maxResults' => 100, 'orderBy' => 'created', 'expand' => 'properties',
            ]);
            foreach ($page['comments'] ?? [] as $comment) {
                $internal = collect($comment['properties'] ?? [])->firstWhere('key', 'sd.public.comment')['value']['internal'] ?? false;
                $comments[] = [
                    'author' => $comment['author']['displayName'] ?? null,
                    'created' => $comment['created'] ?? null,
                    'body' => (string) ($comment['body'] ?? ''),
                    'public' => ! $internal,
                ];
            }
            $startAt += count($page['comments'] ?? []);
        } while ($startAt < ($page['total'] ?? 0) && ($page['comments'] ?? []) !== []);

        return [
            'key' => $key,
            'summary' => (string) ($fields['summary'] ?? ''),
            'description' => (string) ($fields['description'] ?? ''),
            'status' => $fields['status']['name'] ?? null,
            'priority' => $fields['priority']['name'] ?? null,
            'type' => $fields['issuetype']['name'] ?? null,
            'reporter' => $fields['reporter']['displayName'] ?? null,
            'assignee' => $fields['assignee']['displayName'] ?? null,
            'created' => $fields['created'] ?? null,
            'updated' => $fields['updated'] ?? null,
            'comments' => $comments,
            'attachments' => array_map(fn (array $file) => [
                'id' => (string) $file['id'],
                'filename' => (string) $file['filename'],
                'size' => (int) ($file['size'] ?? 0),
                'mime' => $file['mimeType'] ?? null,
                'url' => (string) $file['content'],
                'created' => $file['created'] ?? null,
            ], $fields['attachment'] ?? []),
        ];
    }

    /** Download an attachment to a file. Returns false when Jira refuses or it is too big. */
    public function download(string $url, string $path, int $maxBytes): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $response = Http::withBasicAuth((string) $this->email, (string) $this->token)->timeout(60)->sink($path)->get($url);

        if ($response->failed() || filesize($path) > $maxBytes) {
            @unlink($path);

            return false;
        }

        return true;
    }

    /**
     * The site's SLA fields (Jira Service Management): field id => name. They are custom
     * fields like any other, so they are found by type; the list changes rarely.
     *
     * @return array<string, string>
     */
    public function slaFields(): array
    {
        return Cache::remember('jira.sla-fields.'.md5($this->base), 3600, fn () => collect($this->send('get', '/rest/api/3/field'))
            ->filter(fn ($field) => ($field['schema']['custom'] ?? null) === 'com.atlassian.servicedesk:sd-sla-field')
            ->mapWithKeys(fn ($field) => [$field['id'] => $field['name']])
            ->all());
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
            throw new JiraException('Jira is not set up: set JIRA_BASE, JIRA_EMAIL and JIRA_TOKEN in .env.');
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
