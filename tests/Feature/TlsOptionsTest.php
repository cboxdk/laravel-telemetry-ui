<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Illuminate\Http\Client\PendingRequest;

/**
 * D2 — TLS peer verification per connection, for backends behind an internal
 * PKI or a self-signed certificate.
 */

/**
 * The transport options an ApiClient would actually send.
 *
 * @return array<string, mixed>
 */
function transportOptions(ApiClient $client): array
{
    $pending = (new ReflectionMethod(ApiClient::class, 'pending'))->invoke($client);

    expect($pending)->toBeInstanceOf(PendingRequest::class);

    return $pending->getOptions();
}

function clientFor(mixed $verify): ApiClient
{
    $config = ['driver' => 'prometheus', 'url' => 'http://backend:9090'];

    if ($verify !== null) {
        $config['verify'] = $verify;
    }

    return app(ConnectionManager::class)->client($config);
}

it('leaves the transport default alone when verification is on', function (): void {
    // Not "verify => true" — the option must be absent entirely, so the
    // transport keeps its own CA handling rather than us re-asserting a default.
    expect(transportOptions(clientFor(null)))->not->toHaveKey('verify')
        ->and(transportOptions(clientFor(true)))->not->toHaveKey('verify');
});

it('passes a custom CA bundle path to the transport', function (): void {
    expect(transportOptions(clientFor('/etc/ssl/acme-ca.pem'))['verify'] ?? null)
        ->toBe('/etc/ssl/acme-ca.pem');
});

it('disables verification only on an explicit boolean false', function (): void {
    expect(transportOptions(clientFor(false))['verify'] ?? 'absent')->toBeFalse();
});

it('does not accept a stringy "false" as skip-verify', function (): void {
    // An env var yields strings, so TELEMETRY_UI_*_VERIFY=false arrives as the
    // STRING "false". Treating that as "off" would silently downgrade TLS on a
    // config typo — it is a CA path or nothing.
    $verify = new ReflectionMethod(ConnectionManager::class, 'verify');
    $manager = app(ConnectionManager::class);

    expect($verify->invoke($manager, ['verify' => 'false']))->toBe('false')
        ->and($verify->invoke($manager, ['verify' => '0']))->toBe('0')
        ->and($verify->invoke($manager, ['verify' => 0]))->toBeTrue()
        ->and($verify->invoke($manager, ['verify' => null]))->toBeTrue()
        ->and($verify->invoke($manager, ['verify' => '']))->toBeTrue()
        ->and($verify->invoke($manager, []))->toBeTrue();
});
