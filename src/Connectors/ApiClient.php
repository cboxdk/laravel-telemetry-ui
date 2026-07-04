<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin JSON HTTP client shared by all connectors. Multi-tenant Grafana
 * backends (Mimir, Tempo, Loki) are addressed via the X-Scope-OrgID header.
 *
 * GET responses are cached for `$cacheTtl` seconds when positive: the busy
 * dashboard renders many cards on every load and auto-refresh tick, and each
 * card would otherwise hit the backend live. Only the decoded array (plain
 * primitives) is cached — never a DTO — so it is safe across any cache store,
 * and drivers re-parse it cheaply. Transient network blips are retried.
 */
final readonly class ApiClient
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private string $url,
        private array $headers = [],
        private ?string $tenant = null,
        private float $timeout = 10.0,
        private int $cacheTtl = 0,
        private int $retries = 2,
    ) {}

    /**
     * @param  array<string, int|float|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = rtrim($this->url, '/').$path;

        if ($this->cacheTtl <= 0) {
            return $this->fetch($url, $query);
        }

        // Errors are never cached: SourceException thrown inside the closure
        // propagates without remember() storing anything.
        return Cache::remember(
            $this->cacheKey($url, $query),
            $this->cacheTtl,
            fn (): array => $this->fetch($url, $query),
        );
    }

    /**
     * POST a JSON body (e.g. a GraphQL query for Linear). Never cached —
     * POSTs may mutate, and read-via-POST (Linear) is the rare exception.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        $url = rtrim($this->url, '/').$path;

        try {
            $response = $this->pending()
                ->asJson()
                ->post($url, $body);
        } catch (ConnectionException $exception) {
            $this->fail(SourceException::connectionFailed($url, $exception->getMessage()));
        }

        if ($response->failed()) {
            $this->fail(SourceException::requestFailed($url, $response->status(), $response->body()));
        }

        return $this->decode($response, $url);
    }

    /**
     * @param  array<string, int|float|string>  $query
     * @return array<string, mixed>
     */
    private function fetch(string $url, array $query): array
    {
        try {
            $response = $this->pending()->get($url, $query);
        } catch (ConnectionException $exception) {
            $this->fail(SourceException::connectionFailed($url, $exception->getMessage()));
        }

        if ($response->failed()) {
            $this->fail(SourceException::requestFailed($url, $response->status(), $response->body()));
        }

        return $this->decode($response, $url);
    }

    private function pending(): PendingRequest
    {
        $headers = $this->headers;

        if ($this->tenant !== null) {
            $headers['X-Scope-OrgID'] = $this->tenant;
        }

        $request = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->acceptJson();

        // Retry only transient connection failures; a 4xx/5xx response is left
        // for the caller to surface. throw:false keeps our own error handling.
        if ($this->retries > 0) {
            $request = $request->retry($this->retries + 1, 150, throw: false);
        }

        return $request;
    }

    /**
     * @param  array<string, int|float|string>  $query
     */
    private function cacheKey(string $url, array $query): string
    {
        ksort($query);

        return 'telemetry-ui:http:'.hash('xxh128', implode('|', [
            $url,
            json_encode($query),
            $this->tenant ?? '',
            $this->headers['Authorization'] ?? '',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $url): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            $this->fail(SourceException::unexpectedPayload($url, 'response body is not a JSON object'));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Log the full, potentially-sensitive backend detail (URL, query, response
     * body) server-side, then throw. The exception the dashboard sees carries
     * only a generic message — see {@see SourceException}.
     */
    private function fail(SourceException $exception): never
    {
        Log::warning('telemetry-ui backend request failed', ['detail' => $exception->detail]);

        throw $exception;
    }
}
