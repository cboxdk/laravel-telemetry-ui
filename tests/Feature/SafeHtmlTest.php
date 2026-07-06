<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Support\SafeHtml;

it('renders markdown structure (lists, code, links)', function (): void {
    $html = SafeHtml::fromMarkdown("## Changes\n\n- Bump **flatted** from `3.3.1` to 3.4.2\n- See [the release](https://github.com/x/y/releases)\n");

    expect($html)->toContain('<h2>Changes</h2>')
        ->and($html)->toContain('<li>')
        ->and($html)->toContain('<strong>flatted</strong>')
        ->and($html)->toContain('<code>3.3.1</code>')
        ->and($html)->toContain('href="https://github.com/x/y/releases"')
        ->and($html)->toContain('rel="noopener nofollow"');
});

it('keeps dependabot-style embedded html readable', function (): void {
    $html = SafeHtml::fromMarkdown('<details><summary>Release notes</summary><ul><li>block checking out fork pr</li></ul></details>');

    expect($html)->toContain('<details>')
        ->and($html)->toContain('<summary>Release notes</summary>')
        ->and($html)->toContain('<li>block checking out fork pr</li>');
});

it('never lets external content execute', function (): void {
    $html = SafeHtml::fromMarkdown(implode("\n\n", [
        '<script>alert(1)</script>',
        '<img src=x onerror="alert(2)">',
        '<a href="javascript:alert(3)">click</a>',
        '<p onclick="alert(4)" style="position:fixed">styled</p>',
        '<iframe src="https://evil.test"></iframe>',
    ]));

    expect($html)->not->toContain('<script')
        ->and($html)->toContain('&lt;script&gt;')    // script renders as inert text
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('onclick')
        ->and($html)->not->toContain('style=')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->toContain('click');            // link text survives, href dropped
});

it('unwraps unknown tags but keeps their text', function (): void {
    $html = SafeHtml::fromMarkdown('<section><span class="x">plain words</span></section>');

    expect($html)->not->toContain('<section')
        ->and($html)->not->toContain('<span')
        ->and($html)->toContain('plain words');
});
