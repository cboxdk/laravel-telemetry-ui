<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Panels\Ui;

/**
 * A panel action is an offer a package puts in a payload; the endpoint it
 * names decides whether the viewer may actually take it. The contract that
 * matters here is that the payload cannot point the browser anywhere but
 * this dashboard's own API.
 */
it('builds an action a panel can offer on a row', function (): void {
    $action = Ui::action('Resolve', 'insights/issues/aaaa0001', ['action' => 'resolve'], confirm: 'Sure?', tone: 'danger');

    expect($action)->toBe([
        'label' => 'Resolve',
        'endpoint' => 'insights/issues/aaaa0001',
        'body' => ['action' => 'resolve'],
        'confirm' => 'Sure?',
        'tone' => 'danger',
    ]);
});

it('leaves out what was not given, rather than shipping nulls to the client', function (): void {
    expect(Ui::action('Reopen', 'insights/issues/a'))->toBe(['label' => 'Reopen', 'endpoint' => 'insights/issues/a']);
});

it('normalises a leading slash so both spellings hit the same path', function (): void {
    expect(Ui::action('Go', '/insights/issues/a')['endpoint'])->toBe('insights/issues/a');
});

it('refuses an absolute URL — a payload must not post to another host', function (): void {
    Ui::action('Exfiltrate', 'https://evil.test/collect');
})->throws(InvalidArgumentException::class, 'relative path');

it('refuses a protocol-relative URL too', function (): void {
    Ui::action('Exfiltrate', '//evil.test/collect');
})->throws(InvalidArgumentException::class);

it('rides along on a cell, so a table row can carry it', function (): void {
    $cell = Ui::cell('Open', ['actions' => [Ui::action('Resolve', 'insights/issues/a', ['action' => 'resolve'])]]);

    expect($cell['v'])->toBe('Open')
        ->and($cell['actions'][0]['label'])->toBe('Resolve');
});
