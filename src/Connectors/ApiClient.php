<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin JSON HTTP client shared by all connectors. Multi-tenant Grafana
 * backends (Mimir, Tempo, Loki) are addressed via the X-Scope-OrgID header.
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
    ) {}

    /**
     * @param  array<string, int|float|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = rtrim($this->url, '/').$path;

        $headers = $this->headers;

        if ($this->tenant !== null) {
            $headers['X-Scope-OrgID'] = $this->tenant;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            throw SourceException::connectionFailed($url, $exception->getMessage());
        }

        if ($response->failed()) {
            throw SourceException::requestFailed($url, $response->status(), $response->body());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw SourceException::unexpectedPayload($url, 'response body is not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
