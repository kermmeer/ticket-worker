<?php

namespace App\Agent;

use App\Models\ApiConnection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * An API's endpoints in one shape, whatever they came from: an OpenAPI (or Swagger) spec
 * the API serves, a spec file in its system's code, or (failing both) an agent reading the
 * code. The Docs page shows them; the agent looks endpoints up in them instead of guessing.
 *
 * One endpoint: method, path (under the API's base address), summary, params (name, in,
 * type, required, description), body, returns.
 */
class ApiDocs
{
    /** Where an API usually serves its spec, under its base address or at its host. */
    public const SPEC_PATHS = ['/openapi.json', '/openapi.yaml', '/swagger.json', '/v3/api-docs', '/api-docs', '/swagger/v1/swagger.json', '/docs/openapi.json'];

    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * A spec the API serves: the API as it runs, the whole of it. Null when it serves none.
     *
     * @return array{endpoints: list<array>, source: string}|null
     */
    public function served(ApiConnection $api): ?array
    {
        foreach ($this->servedCandidates($api) as $path) {
            try {
                $answer = $api->send('GET', $path);
            } catch (Throwable) {
                continue;
            }
            if ($answer['status'] === 200 && ($spec = self::parse($answer['body'])) !== null) {
                return ['endpoints' => self::endpoints($spec, $api), 'source' => 'served at '.$path];
            }
        }

        return null;
    }

    /**
     * Specs in the system's code for this API. Often they cover one feature only, so the
     * code is read as well; they win where both describe an endpoint.
     *
     * @return array{endpoints: list<array>, files: list<string>}|null
     */
    public function inCode(ApiConnection $api): ?array
    {
        $endpoints = [];
        $files = [];
        foreach ($this->repoCandidates($api) as $file) {
            if (($spec = self::parse((string) @file_get_contents($file))) !== null) {
                $endpoints = self::merge($endpoints, self::endpoints($spec, $api));
                $files[] = Str::after($file, $api->system->path().'/');
            }
        }

        return $endpoints === [] ? null : ['endpoints' => $endpoints, 'files' => $files];
    }

    /** An endpoint's identity: its method and path, whatever its placeholders are called. */
    public static function key(array $endpoint): string
    {
        return strtoupper((string) ($endpoint['method'] ?? '')).' '.strtolower(rtrim(preg_replace('/\{[^}]*\}/', '{}', (string) ($endpoint['path'] ?? '')), '/'));
    }

    /**
     * Endpoints from two sources in one list: the second wins where both have one.
     *
     * @return list<array>
     */
    public static function merge(array $base, array $winning): array
    {
        $all = [];
        foreach ([...$base, ...$winning] as $endpoint) {
            $all[self::key($endpoint)] = $endpoint;
        }

        return array_values($all);
    }

    /** @return list<string> paths to try for a served spec, under the base address (no call ever leaves it) */
    private function servedCandidates(ApiConnection $api): array
    {
        return self::SPEC_PATHS;
    }

    /**
     * Spec files in the system's code that belong to this API: named after it, or with a
     * server at its host.
     *
     * @return list<string>
     */
    private function repoCandidates(ApiConnection $api): array
    {
        $system = $api->system;
        if ($system === null || ! $system->hasCode() || ! is_dir($system->path())) {
            return [];
        }
        $find = new Process(['git', '-c', 'safe.directory=*', 'ls-files', '*.yaml', '*.yml', '*.json'], $system->path());
        $find->run();
        $stem = Str::of($api->name)->lower()->replaceMatches('/[-_]?api$/', '')->value();
        $host = (string) parse_url($api->base_url, PHP_URL_HOST);

        $files = [];
        foreach (preg_split('/\R/', trim($find->getOutput())) as $file) {
            if ($file === '' || str_contains($file, 'node_modules/') || str_contains($file, 'vendor/')) {
                continue;
            }
            $path = $system->path().'/'.$file;
            $head = (string) @file_get_contents($path, false, null, 0, 4000);
            if (! preg_match('/^\s*["\']?(openapi|swagger)["\']?\s*:/m', $head)) {
                continue;
            }
            $named = $stem !== '' && str_contains(strtolower(basename($file)), $stem);
            $hosted = $host !== '' && str_contains((string) @file_get_contents($path), $host);
            if ($named || $hosted) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** A spec from JSON or YAML text; null when it is not one. */
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        try {
            $spec = str_starts_with($text, '{') ? json_decode($text, true, 512, JSON_THROW_ON_ERROR) : Yaml::parse($text);
        } catch (Throwable) {
            return null;
        }

        return is_array($spec) && (isset($spec['openapi']) || isset($spec['swagger'])) && is_array($spec['paths'] ?? null) ? $spec : null;
    }

    /**
     * The spec's operations as endpoints, with paths under the API's base address: a spec
     * that writes /api/orders for a base ending in /api gives /orders.
     *
     * @return list<array>
     */
    public static function endpoints(array $spec, ApiConnection $api): array
    {
        $basePath = rtrim((string) parse_url($api->base_url, PHP_URL_PATH), '/');
        $endpoints = [];
        foreach ($spec['paths'] as $path => $item) {
            if (! is_array($item)) {
                continue;
            }
            $shown = $basePath !== '' && str_starts_with($path, $basePath.'/') ? substr($path, strlen($basePath)) : $path;
            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (! is_array($operation)) {
                    continue;
                }
                $params = [];
                foreach ([...($item['parameters'] ?? []), ...($operation['parameters'] ?? [])] as $param) {
                    if (! is_array($param) || ! isset($param['name'])) {
                        continue;
                    }
                    $params[] = [
                        'name' => (string) $param['name'], 'in' => (string) ($param['in'] ?? 'query'),
                        'type' => (string) ($param['schema']['type'] ?? $param['type'] ?? ''), 'required' => (bool) ($param['required'] ?? false),
                        'description' => Str::limit((string) ($param['description'] ?? ''), 300),
                    ];
                }
                $body = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                $endpoints[] = [
                    'method' => strtoupper($method),
                    'path' => $shown,
                    'summary' => Str::limit(trim((string) ($operation['summary'] ?? $operation['description'] ?? '')), 300),
                    'params' => $params,
                    'body' => $body ? Str::limit(json_encode($body, JSON_UNESCAPED_SLASHES), 1500) : null,
                    'returns' => Str::limit(collect($operation['responses'] ?? [])->map(fn ($response, $code) => "{$code}: ".($response['description'] ?? ''))->implode('; '), 300),
                ];
            }
        }

        return $endpoints;
    }

    /**
     * Endpoints matching a search, for the agent: every word in method, path or summary.
     *
     * @return list<array>
     */
    public static function search(array $endpoints, ?string $search, int $limit = 40): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $search))));

        return array_values(array_slice(array_filter($endpoints, function ($endpoint) use ($words) {
            $text = mb_strtolower($endpoint['method'].' '.$endpoint['path'].' '.$endpoint['summary'].' '.collect($endpoint['params'] ?? [])->pluck('name')->implode(' '));

            return collect($words)->every(fn ($word) => str_contains($text, $word));
        }), 0, $limit));
    }
}
