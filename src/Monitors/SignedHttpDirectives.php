<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Monitors;

use Cbox\TelemetryUi\Contracts\ManagesDirectives;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Directives in an app elsewhere, through the signed endpoint
 * cboxdk/laravel-telemetry exposes once `TELEMETRY_DIRECTIVES_SECRET` is set:
 *
 *     GET    {url}/telemetry/directives
 *     POST   {url}/telemetry/directives       {"match":"route","value":"checkout.*","minutes":15,…}
 *     DELETE {url}/telemetry/directives/{id}
 *
 *     X-Telemetry-Timestamp: <unix seconds>
 *     X-Telemetry-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
 *
 * The body is signed exactly as sent (empty for GET and DELETE), so it is
 * encoded once and passed through untouched. The app allows five minutes of
 * clock skew.
 */
final class SignedHttpDirectives implements ManagesDirectives
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $service,
        private readonly string $url,
        private readonly string $secret,
        private readonly string $path = 'telemetry/directives',
        private readonly float $timeout = 5.0,
    ) {}

    public function all(): array
    {
        $response = $this->send('GET', '');
        $directives = $response->json('directives');

        return array_values(array_filter(array_map(
            fn (mixed $d): ?Monitor => is_array($d) ? Monitor::fromDirective($this->service, $d) : null,
            is_array($directives) ? $directives : [],
        )));
    }

    public function add(MonitorRequest $request): Monitor
    {
        $body = (string) json_encode($request->payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $directive = $this->send('POST', '', $body)->json('directive');

        $monitor = is_array($directive) ? Monitor::fromDirective($this->service, $directive) : null;

        return $monitor ?? throw new MonitorException("{$this->service} answered without a directive.");
    }

    public function remove(string $id): bool
    {
        try {
            $this->send('DELETE', '/'.rawurlencode($id));
        } catch (MonitorException $e) {
            if ($e->kind === 'not_found') {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function send(string $method, string $suffix, string $body = ''): Response
    {
        $timestamp = (string) time();
        $url = rtrim($this->url, '/').'/'.trim($this->path, '/').$suffix;

        try {
            $pending = $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-Telemetry-Timestamp' => $timestamp,
                    'X-Telemetry-Signature' => self::sign($timestamp, $body, $this->secret),
                ]);

            if ($body !== '') {
                $pending = $pending->withBody($body, 'application/json');
            }

            $response = $pending->send($method, $url);
        } catch (Throwable $e) {
            throw new MonitorException("Could not reach {$this->service}: {$e->getMessage()}", previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $error = $response->json('error');
        $detail = is_string($error) ? ": {$error}" : '.';

        throw match ($response->status()) {
            401 => new MonitorException("{$this->service} refused the signature — check the secret and the clock{$detail}"),
            404 => MonitorException::notFound("{$this->service} has no such directive, or its directives endpoint is off (TELEMETRY_DIRECTIVES_SECRET){$detail}"),
            422 => MonitorException::invalid("{$this->service} refused the monitor{$detail}"),
            default => new MonitorException("{$this->service} answered {$response->status()}{$detail}"),
        };
    }
}
