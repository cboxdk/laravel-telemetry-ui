<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\Channel;

it('classifies the referrer into a marketing channel', function (string $referrer, string $expected): void {
    expect(Channel::classify($referrer))->toBe($expected);
})->with([
    'direct' => ['', 'Direct'],
    'organic google' => ['google.com', 'Organic search'],
    'organic subdomain' => ['news.google.com', 'Organic search'],
    'organic ddg' => ['duckduckgo.com', 'Organic search'],
    'social facebook' => ['facebook.com', 'Social'],
    'social x/t.co' => ['t.co', 'Social'],
    'social linkedin sub' => ['www.linkedin.com', 'Social'],
    'email webmail' => ['mail.google.com', 'Email'],
    'plain referral' => ['news.ycombinator.com', 'Referral'],
    'lookalike is not social' => ['xenon.com', 'Referral'], // "x" must not swallow "xenon"
]);

it('reads paid from a paid utm medium or a click-id, regardless of referrer', function (): void {
    expect(Channel::classify('google.com', 'cpc'))->toBe('Paid')
        ->and(Channel::classify('', '', paidClick: true))->toBe('Paid')
        ->and(Channel::classify('facebook.com', 'ppc'))->toBe('Paid');
});

it('reads email and organic from the utm medium even without a referrer', function (): void {
    expect(Channel::classify('', 'email'))->toBe('Email')
        ->and(Channel::classify('', 'organic'))->toBe('Organic search');
});

it('classifies a self-referral as Internal when the host is configured', function (): void {
    $internal = ['cbox.dk'];

    expect(Channel::classify('cbox.dk', internalHosts: $internal))->toBe('Internal')
        ->and(Channel::classify('app.cbox.dk', internalHosts: $internal))->toBe('Internal') // subdomain counts
        ->and(Channel::classify('cbox.dk'))->toBe('Referral')                                // no config → Referral
        ->and(Channel::classify('other.com', internalHosts: $internal))->toBe('Referral');   // external stays Referral
});
