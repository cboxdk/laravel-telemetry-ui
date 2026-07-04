<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\TraceSearch;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('scopes trace search to browser spans when the source is frontend', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    Livewire::test(TraceSearch::class)->set('source', 'frontend');

    // Browser/RUM spans are tagged span.browser=true by the ingest proxy.
    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'span.browser = true'));
});

it('excludes browser spans when the source is backend', function (): void {
    Http::fake(['tempo.test:3200/api/search*' => Http::response(['traces' => []])]);

    Livewire::test(TraceSearch::class)->set('source', 'backend');

    Http::assertSent(fn ($r): bool => str_contains(rawurldecode($r->url()), 'span.browser != true'));
});
