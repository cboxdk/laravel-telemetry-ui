<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

it('formats counts compactly', function (): void {
    expect(Format::count(0))->toBe('0')
        ->and(Format::count(437))->toBe('437')
        ->and(Format::count(51_500))->toBe('51.5K')
        ->and(Format::count(1_900))->toBe('1.9K')
        ->and(Format::count(2_400_000))->toBe('2.4M');
});

it('formats durations with sensible units', function (): void {
    expect(Format::ms(0))->toBe('0ms')
        ->and(Format::ms(0.76))->toBe('760µs')
        ->and(Format::ms(174.45))->toBe('174ms')
        ->and(Format::ms(1_890))->toBe('1.89s')
        ->and(Format::ms(80_000))->toBe('1.33min');
});

it('formats bytes and percentages', function (): void {
    expect(Format::bytes(4_190_000))->toBe('4 MB')
        ->and(Format::bytes(512))->toBe('512 B')
        ->and(Format::percent(0.9871))->toBe('98.7%');
});

it('formats fleet scope helpers on cards', function (): void {
    $card = new class extends Card
    {
        public function render(): View
        {
            return view('telemetry-ui::cards.chart');
        }

        public function probe(): array
        {
            return [
                $this->metric('up'),
                $this->traceScope('status = error'),
                $this->logSelector(),
            ];
        }
    };

    $card->service = 'checkout';
    $card->environment = 'prod';

    expect($card->probe())->toBe([
        'up{service_name="checkout",deployment_environment_name="prod"}',
        'resource.service.name = "checkout" && resource.deployment.environment.name = "prod" && status = error',
        '{service_name="checkout",deployment_environment_name="prod"}',
    ]);

    $card->service = '';
    $card->environment = '';

    expect($card->probe())->toBe(['up', 'status = error', '{service_name=~".+"}']);
});
