<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('caches GET responses for the configured ttl', function (): void {
    Http::fake(['api.test/*' => Http::response(['n' => 1])]);

    $client = new ApiClient('http://api.test', cacheTtl: 30);

    expect($client->get('/x', ['q' => 'a']))->toBe(['n' => 1])
        ->and($client->get('/x', ['q' => 'a']))->toBe(['n' => 1]);

    // Second identical GET is served from cache — the backend is hit once.
    Http::assertSentCount(1);
});

it('varies the cache key by path and query', function (): void {
    Http::fake([
        'api.test/x?q=a' => Http::response(['n' => 1]),
        'api.test/x?q=b' => Http::response(['n' => 2]),
        'api.test/y*' => Http::response(['n' => 3]),
    ]);

    $client = new ApiClient('http://api.test', cacheTtl: 30);

    expect($client->get('/x', ['q' => 'a'])['n'])->toBe(1)
        ->and($client->get('/x', ['q' => 'b'])['n'])->toBe(2)
        ->and($client->get('/y')['n'])->toBe(3);

    Http::assertSentCount(3);
});

it('does not cache when ttl is zero', function (): void {
    Http::fake(['api.test/*' => Http::response(['n' => 1])]);

    $client = new ApiClient('http://api.test', cacheTtl: 0);

    $client->get('/x');
    $client->get('/x');

    Http::assertSentCount(2);
});

it('never caches an error response', function (): void {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response('boom', 500)
            : Http::response(['ok' => true]);
    });

    $client = new ApiClient('http://api.test', cacheTtl: 30, retries: 0);

    // The first call errors and must NOT be cached; the second is fetched
    // fresh and succeeds.
    expect(fn () => $client->get('/x'))->toThrow(SourceException::class);
    expect($client->get('/x'))->toBe(['ok' => true]);
});

it('retries a transient connection failure then succeeds', function (): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw new ConnectionException('reset');
        }

        return Http::response(['ok' => true]);
    });

    $client = new ApiClient('http://api.test', retries: 2);

    expect($client->get('/x'))->toBe(['ok' => true])
        ->and($attempts)->toBe(2);
});

it('gives up after exhausting retries', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    $client = new ApiClient('http://api.test', retries: 1);

    expect(fn () => $client->get('/x'))->toThrow(SourceException::class, 'Could not reach');
});
