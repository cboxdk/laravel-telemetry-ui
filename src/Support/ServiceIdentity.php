<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * Visual identity and classification of the services appearing in a trace,
 * so the infra chain reads at a glance: edge proxies, eBPF-instrumented
 * infra (Beyla) and code-instrumented apps each get their own look, and
 * every service gets a stable color.
 */
final class ServiceIdentity
{
    private const PALETTE = [
        '#34d399', '#60a5fa', '#fbbf24', '#c084fc',
        '#f472b6', '#2dd4bf', '#a3e635', '#fb923c',
    ];

    public static function color(string $service): string
    {
        return self::PALETTE[abs(crc32($service)) % count(self::PALETTE)];
    }

    /**
     * Classify a service from its resource attributes (preferred) or name:
     * "proxy" (traefik/nginx/haproxy/…), "ebpf" (Grafana Beyla) or "app".
     *
     * @param  array<string, mixed>  $resourceAttributes
     */
    public static function kind(string $service, array $resourceAttributes = []): string
    {
        $sdk = strtolower(self::attr($resourceAttributes, 'telemetry.sdk.name'));
        $distro = strtolower(self::attr($resourceAttributes, 'telemetry.distro.name'));

        if (str_contains($sdk, 'beyla') || str_contains($distro, 'beyla')) {
            return 'ebpf';
        }

        $haystack = strtolower($service.' '.$sdk);

        foreach (['traefik', 'nginx', 'haproxy', 'envoy', 'caddy', 'varnish', 'ingress'] as $proxy) {
            if (str_contains($haystack, $proxy)) {
                return 'proxy';
            }
        }

        return 'app';
    }

    public static function kindLabel(string $kind): ?string
    {
        return match ($kind) {
            'proxy' => 'proxy',
            'ebpf' => 'beyla',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function attr(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
