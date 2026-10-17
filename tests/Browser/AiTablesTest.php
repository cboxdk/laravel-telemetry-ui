<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;

/**
 * Every AI table stays a table on a laptop and becomes cards on a phone.
 *
 * DataTable turns a table into cards when its columns cannot fit, and the AI
 * tables carry the widest values in the package: a forty-character model
 * name, error badges, token pairs, sub-cent costs. A column too many put
 * the models table, the call lists and the agent runs into cards at 1280px
 * while every other page stayed a table — so this pins it, against the
 * fixture data, which includes the longest model name and error types.
 *
 * @return array<string, bool> panel title => rendered as cards
 */
function aiTableModes(string $url, int $width): array
{
    $page = visit($url)
        ->resize($width, 2400)
        ->assertDontSee('Loading dashboard')
        ->waitForEvent('networkidle')
        ->assertScript('document.querySelectorAll(".t-skeleton").length === 0');

    /** @var array<string, bool> $modes */
    $modes = $page->script(<<<'JS'
        Object.fromEntries(Array.from(document.querySelectorAll('.t-table-host')).map((host) => [
            host.closest('.t-panel')?.querySelector('.t-panel-title')?.textContent ?? '?',
            !!host.querySelector('.is-cards'),
        ]))
    JS);

    return $modes;
}

/** @return array<string, string> */
function aiTablePages(): array
{
    return [
        'ai' => '/telemetry-ui/p/ai',
        'ai by tenant' => '/telemetry-ui/p/ai?ai_by=tenant.id',
        // Other sorts bring other rows (the costliest, the slowest) to the top.
        'ai by cost' => '/telemetry-ui/p/ai?ai_sort=cost&ai_calls_sort=cost',
        'ai by duration' => '/telemetry-ui/p/ai?ai_sort=duration&ai_calls_sort=duration',
        'model' => '/telemetry-ui/entity/ai-model?value=meta-llama%2FLlama-3.3-70B-Instruct-Turbo&tab=metrics',
        'agent' => '/telemetry-ui/entity/ai-agent?value=SupportAgent&tab=metrics',
        'user' => '/telemetry-ui/entity/user?value=u_4812&tab=metrics',
        'tenant' => '/telemetry-ui/entity/tenant?value=acme&tab=metrics',
        'ip' => '/telemetry-ui/entity/ip?value=203.0.113.9&tab=metrics',
    ];
}

beforeEach(function (): void {
    TelemetryUi::dimension('tenant.id', label: 'Tenant', group: 'Billing', entity: 'tenant');
});

it('keeps every AI table a table at a 1280px viewport', function (): void {
    $cards = [];

    foreach (aiTablePages() as $name => $url) {
        $modes = aiTableModes($url, 1280);

        // Every table on the page was measured, not just the first to load.
        expect(count($modes))->toBeGreaterThanOrEqual(str_starts_with($name, 'ai') ? 5 : 1, "tables missing on {$name}: ".json_encode($modes));

        foreach ($modes as $title => $isCards) {
            if ($isCards) {
                $cards[] = "{$name}: {$title}";
            }
        }
    }

    expect($cards)->toBe([], 'AI tables rendered as cards at 1280px: '.implode(', ', $cards));
});

it('turns AI tables into cards on a phone', function (): void {
    foreach (['ai' => '/telemetry-ui/p/ai', 'user' => '/telemetry-ui/entity/user?value=u_4812&tab=metrics'] as $name => $url) {
        $modes = aiTableModes($url, 390);

        expect(array_filter($modes))->not->toBeEmpty("no AI table on {$name} turned into cards at 390px");
    }
});
