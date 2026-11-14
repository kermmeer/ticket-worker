<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * An API the agent may ask things of, for one system (CONCEPT.md §11, data tools). The
 * tool makes every call: the secret stays here, encrypted, and the agent only names the
 * connection and a path under its base address.
 */
class ApiConnection extends Model
{
    /** How the secret goes along. */
    public const AUTH = [
        'system' => 'As the system (its sign-in for all its APIs)',
        'none' => 'None',
        'basic' => 'Username and password or token (basic)',
        'bearer' => 'Bearer token',
        'header' => 'A header of its own',
        'query' => 'A query parameter',
    ];

    /** What the agent may do with it. */
    public const ACCESS = [
        'read' => 'Read: GET only',
        'query' => 'Read and query: GET, and POST for searches',
        'write' => 'Read, and change after approval: GET runs; POST, PUT, PATCH and DELETE wait for a person to run them',
    ];

    /** Methods that change something, on an API that allows changes: never run without a person. */
    public const CHANGES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** What an answer may be before it is cut: the agent reads it, and it costs tokens. */
    public const MAX_ANSWER = 60_000;

    protected $fillable = ['system_id', 'name', 'base_url', 'auth', 'username', 'field', 'secret', 'allow_post', 'access', 'notes'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'allow_post' => 'boolean'];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /** The address a path resolves to, only ever under the base address. */
    public function urlFor(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^[a-z][a-z0-9+.-]*:|^//|\\\\#i', $path) || preg_match('#(^|/)\.\.?(/|$)#', explode('?', $path)[0])) {
            throw new InvalidArgumentException('Give a path under the base address, like /orders/123: not a full address, and no ../.');
        }

        return rtrim($this->base_url, '/').'/'.ltrim($path, '/');
    }

    /** A call of this method waits for a person: a change, on an API that allows changes after approval. */
    public function needsApproval(string $method): bool
    {
        return $this->access === 'write' && in_array(strtoupper($method), self::CHANGES, true);
    }

    /** @return list<string> what the agent may call it with (a change only as a proposal) */
    public function methods(): array
    {
        return match ($this->access) {
            'write' => ['GET', ...self::CHANGES],
            'query' => ['GET', 'POST'],
            default => ['GET'],
        };
    }

    /**
     * One call, as the agent asked it: only what its access allows to run at once. Never
     * throws for what the API answers: a 404 is an answer too.
     *
     * @return array{status: int, type: ?string, body: string, truncated: bool}
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        if (! in_array($method, $this->methods(), true)) {
            throw new InvalidArgumentException($this->access === 'query' ? 'Only GET and POST.' : 'Only GET: this API is read-only for you.');
        }
        if ($this->needsApproval($method)) {
            throw new InvalidArgumentException("A {$method} changes something: it waits for a person to run it.");
        }

        return $this->send($method, $path, $query, $body);
    }

    /**
     * Send it. For calls its access allows, and for a change a person approved: the one
     * caller that may change data is ApiCallController, after that approval.
     *
     * @return array{status: int, type: ?string, body: string, truncated: bool}
     */
    public function send(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        $url = $this->urlFor($path);
        try {
            $response = match ($method) {
                'GET' => $this->request()->get($url, $query ?: null),
                'DELETE' => $this->request()->withQueryParameters($query)->delete($url, $body ?? []),
                'POST', 'PUT', 'PATCH' => $this->request()->withQueryParameters($query)->{strtolower($method)}($url, $body ?? []),
                default => throw new InvalidArgumentException("No method {$method}."),
            };
        } catch (ConnectionException $e) {
            return ['status' => 0, 'type' => null, 'body' => $this->redact('No answer: '.$e->getMessage()), 'truncated' => false];
        }

        $text = (string) $response->body();
        $json = json_decode($text, true);
        if (is_array($json)) {
            $text = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return [
            'status' => $response->status(),
            'type' => $response->header('Content-Type') ?: null,
            'body' => $this->redact(mb_strcut($text, 0, self::MAX_ANSWER)),
            'truncated' => strlen($text) > self::MAX_ANSWER,
        ];
    }

    /**
     * The sign-in it uses: its own, or its system's when it says "as the system".
     *
     * @return array{auth: string, username: ?string, field: ?string, secret: ?string}
     */
    public function signIn(): array
    {
        if ($this->auth === 'system') {
            $system = $this->system;

            return ['auth' => $system?->api_auth ?: 'none', 'username' => $system?->api_username, 'field' => $system?->api_field, 'secret' => $system?->api_secret];
        }

        return ['auth' => (string) $this->auth, 'username' => $this->username, 'field' => $this->field, 'secret' => $this->secret];
    }

    /** Whatever an API echoes back, the secret never reaches the agent. */
    public function redact(string $text): string
    {
        $in = $this->signIn();
        $secrets = array_filter([$in['secret'], $in['secret'] ? base64_encode("{$in['username']}:{$in['secret']}") : null], fn ($value) => is_string($value) && strlen($value) >= 4);

        return $secrets === [] ? $text : str_replace($secrets, '[secret]', $text);
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(30)->connectTimeout(10)->acceptJson()->withoutRedirecting()
            ->withUserAgent('Ticket Worker');
        $in = $this->signIn();

        return match ($in['auth']) {
            'basic' => $request->withBasicAuth((string) $in['username'], (string) $in['secret']),
            'bearer' => $request->withToken((string) $in['secret']),
            'header' => $request->withHeaders([(string) $in['field'] => (string) $in['secret']]),
            'query' => $request->withQueryParameters([(string) $in['field'] => (string) $in['secret']]),
            default => $request,
        };
    }
}
