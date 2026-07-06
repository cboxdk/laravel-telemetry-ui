<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issue/PR bodies are Markdown with embedded HTML (dependabot's <details>
 * blocks, GitHub's task lists) and come from an EXTERNAL system — render
 * them readable, never executable. Markdown is converted with raw HTML
 * allowed, then the result is walked and reduced to a strict allowlist of
 * structural tags; every attribute is dropped except a validated http(s)
 * href. Anything unexpected renders as its text content.
 */
final class SafeHtml
{
    private const ALLOWED = [
        'p', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'em', 'i',
        'strong', 'b', 'br', 'hr', 'details', 'summary', 'del', 's',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    public static function fromMarkdown(string $markdown): string
    {
        try {
            $html = (string) Str::markdown($markdown, [
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 20,
            ]);

            return self::sanitize($html);
        } catch (Throwable) {
            // Fail safe: plain, escaped text with line breaks.
            return nl2br(e($markdown), false);
        }
    }

    private static function sanitize(string $html): string
    {
        $document = new DOMDocument;

        // Suppress warnings from the tag soup real issue bodies contain.
        if (! @$document->loadHTML(
            '<?xml encoding="utf-8"?><div id="root">'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        )) {
            return e(strip_tags($html));
        }

        $root = $document->getElementById('root');

        if ($root === null) {
            return e(strip_tags($html));
        }

        self::clean($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    private static function clean(DOMNode $node): void
    {
        // Snapshot: the list mutates while we replace/remove children.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue; // text/comments: text stays, comments are inert.
            }

            $tag = strtolower($child->tagName);

            if (! in_array($tag, self::ALLOWED, true)) {
                // Unwrap: keep the (cleaned) children, drop the tag itself —
                // scripts/styles lose their payload entirely.
                if ($tag === 'script' || $tag === 'style' || $tag === 'iframe') {
                    $node->removeChild($child);

                    continue;
                }

                self::clean($child);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            // Allowed tag: drop every attribute except a validated href.
            $href = $tag === 'a' ? (string) $child->getAttribute('href') : '';

            while (($attribute = $child->attributes->item(0)) !== null) {
                $child->removeAttributeNode($attribute);
            }

            if ($tag === 'a' && preg_match('#^https?://#i', $href) === 1) {
                $child->setAttribute('href', $href);
                $child->setAttribute('rel', 'noopener nofollow');
                $child->setAttribute('target', '_blank');
            }

            self::clean($child);
        }
    }
}
