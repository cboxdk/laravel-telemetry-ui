<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Builtin\Statamic\ContentByType;
use Cbox\TelemetryUi\Cards\Builtin\Statamic\GlideByPreset;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** A Prometheus instant-vector response. */
function facetVector(array $results): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $results]];
}

it('breaks a single-label counter down by facet, sorted by count with share', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => Http::response(facetVector([
            ['metric' => ['preset' => 'card'], 'value' => [1735689600, '9']],
            ['metric' => ['preset' => 'hero'], 'value' => [1735689600, '15']],
            ['metric' => ['preset' => 'full'], 'value' => [1735689600, '8']],
        ])),
    ]);

    Livewire::test(GlideByPreset::class)
        ->assertSee('Generations by preset')
        ->assertSee('Preset')
        ->assertSee('Images')
        // Descending by count regardless of response order.
        ->assertSeeInOrder(['hero', 'card', 'full'])
        ->assertSee('15')
        ->assertSee('9')
        ->assertSee('8');

    Http::assertSent(function ($request): bool {
        $query = rawurldecode(parse_url($request->url(), PHP_URL_QUERY) ?? '');

        return str_contains($query, 'statamic_glide_generations_total')
            && str_contains($query, 'preset');
    });
});

it('breaks a multi-label counter down into one column per label', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => Http::response(facetVector([
            ['metric' => ['type' => 'entry', 'action' => 'saved'], 'value' => [1735689600, '20']],
            ['metric' => ['type' => 'entry', 'action' => 'deleted'], 'value' => [1735689600, '5']],
        ])),
    ]);

    Livewire::test(ContentByType::class)
        ->assertSee('Changes by type & action')
        ->assertSee('Type')
        ->assertSee('Action')
        ->assertSeeInOrder(['saved', 'deleted'])
        ->assertSee('entry');
});

it('shows an empty state when the counter has no samples in the period', function (): void {
    Http::fake([
        'prometheus.test:9090/api/v1/query?*' => Http::response(facetVector([])),
    ]);

    Livewire::test(GlideByPreset::class)
        ->assertSee('Generations by preset')
        ->assertSee('No data in this period.');
});
