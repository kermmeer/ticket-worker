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
        'none' => 'None',
        'basic' => 'Username and password or token (basic)',
        'bearer' => 'Bearer token',
        'header' => 'A header of its own',
        'query' => 'A query parameter',
    ];

    /** What an answer may be before it is cut: the agent reads it, and it costs tokens. */
    public const MAX_ANSWER = 60_000;

    protected $fillable = ['system_id', 'name', 'base_url', 'auth', 'username', 'field', 'secret', 'allow_post', 'notes'];

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

    /**
     * One call, as the agent asked it. Never throws for what the API answers: a 404 is an
     * answer too.
     *
     * @return array{status: int, type: ?string, body: string, truncated: bool}
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        if (! in_array($method, $this->allow_post ? ['GET', 'POST'] : ['GET'], true)) {
            throw new InvalidArgumentException($this->allow_post ? 'Only GET and POST.' : "Only GET: this API is read-only for you.");
        }

        $url = $this->urlFor($path);
        try {
            $response = match ($method) {
                'GET' => $this->request()->get($url, $query ?: null),
                'POST' => $this->request()->withQueryParameters($query)->post($url, $body ?? []),
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

    /** Whatever an API echoes back, the secret never reaches the agent. */
    public function redact(string $text): string
    {
        $secrets = array_filter([$this->secret, $this->secret ? base64_encode("{$this->username}:{$this->secret}") : null], fn ($value) => is_string($value) && strlen($value) >= 4);

        return $secrets === [] ? $text : str_replace($secrets, '[secret]', $text);
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(30)->connectTimeout(10)->acceptJson()->withoutRedirecting()
            ->withUserAgent('Ticket Worker');

        return match ($this->auth) {
            'basic' => $request->withBasicAuth((string) $this->username, (string) $this->secret),
            'bearer' => $request->withToken((string) $this->secret),
            'header' => $request->withHeaders([(string) $this->field => (string) $this->secret]),
            'query' => $request->withQueryParameters([(string) $this->field => (string) $this->secret]),
            default => $request,
        };
    }
}
