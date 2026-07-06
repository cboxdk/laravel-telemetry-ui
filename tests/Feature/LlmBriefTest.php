<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;

/**
 * The "Copy for LLM" Markdown brief on the issue page. `llmMarkdown` is a pure
 * function of the (already-fetched) report, so it needs no backend fakes.
 */
function sampleReport(): array
{
    return [
        'occurrences' => [],
        'stats' => [
            'count' => 52,
            'sampled' => false,
            'firstSeen' => '12 hours ago',
            'lastSeen' => '5 hours ago',
            'source' => 'backend',
            'users' => 3,
        ],
        'detail' => [
            'type' => 'RuntimeException',
            'message' => 'Class "Redis" not found',
            'file' => 'app/Services/Cart.php',
            'line' => 80,
            'stacktrace' => "#0 app/Services/Cart.php(80): boom()\n#1 {main}",
            'source' => 'app',
            'environment' => 'production',
            'release' => 'v1.2.3',
            'host' => 'web-01',
        ],
        'request' => [
            'traceId' => 'abc123',
            'origin' => 'GET /',
            'method' => 'POST',
            'route' => 'checkout.store',
            'status' => '500',
            'user' => '42',
        ],
        'suspect' => [
            'label' => 'Deploy v1.2.3',
            'kind' => 'deploy',
            'time' => '05/07 19:09',
            'gap' => '18 minutes',
            'notes' => 'shipped the redis refactor',
            'traceId' => null,
            'color' => '#c084fc',
        ],
        'releases' => [
            ['release' => 'v1.2.3', 'count' => 50],
            ['release' => 'v1.2.2', 'count' => 2],
        ],
    ];
}

it('builds a Markdown brief with every section the page knows', function (): void {
    $md = app(ErrorGroupReport::class)->llmMarkdown('1dea8b7623a5', sampleReport());

    expect($md)
        ->toContain('# RuntimeException: Class "Redis" not found')
        ->toContain('## Overview')
        ->toContain('- **Exception:** `RuntimeException`')
        ->toContain('- **Location:** `app/Services/Cart.php:80`')
        ->toContain('- **Group (fingerprint):** `1dea8b7623a5`')
        ->toContain('- **Occurrences:** 52 in the last 30 days')
        ->toContain('- **Users affected:** 3')
        ->toContain('## Request that hit it')
        ->toContain('- **Endpoint:** POST checkout.store')
        ->toContain('## Suspect change')
        ->toContain('Deploy v1.2.3 (deploy) at 05/07 19:09 — first seen 18 minutes later.')
        ->toContain('shipped the redis refactor')
        ->toContain('## Releases seen')
        ->toContain('- `v1.2.3` — 50')
        ->toContain("## Stacktrace\n```\n#0 app/Services/Cart.php(80)");
});

it('marks sampled counts with a + and omits empty sections', function (): void {
    $report = sampleReport();
    $report['stats']['sampled'] = true;
    $report['stats']['users'] = 0;
    $report['request'] = null;
    $report['suspect'] = null;
    $report['releases'] = [];
    $report['detail']['stacktrace'] = '';

    $md = app(ErrorGroupReport::class)->llmMarkdown('g1', $report);

    expect($md)
        ->toContain('- **Occurrences:** 52+ in the last 30 days')
        ->not->toContain('Users affected')
        ->not->toContain('## Request that hit it')
        ->not->toContain('## Suspect change')
        ->not->toContain('## Releases seen')
        ->not->toContain('## Stacktrace');
});
