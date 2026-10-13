<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis\Snapshot;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Support\DashboardUrl;
use Illuminate\Support\Str;

/**
 * One error group as Markdown ("copy as Markdown" on the issue), to paste into
 * an AI agent, a pull request or a ticket: identity and counts, the request
 * that hit it, the suspect change, the releases it rode in on, the app frames,
 * the source excerpt around the throw and the stacktrace — and a link to the
 * newest occurrence's trace and that trace's own snapshot.
 *
 * Everything comes from the {@see ErrorGroupReport} the issue view already
 * read; nothing is looked up again, and a value the exception record does not
 * carry is left out.
 *
 * @phpstan-import-type Report from ErrorGroupReport
 */
final class ExceptionSnapshot
{
    private const MAX_APP_FRAMES = 8;

    private const SOURCE_BYTES = 1_500;

    /**
     * @param  Report  $report
     */
    public static function build(string $group, array $report, int $maxBytes = MarkdownSnapshot::MAX_BYTES): MarkdownSnapshot
    {
        $detail = $report['detail'];
        $stacktrace = (string) ($detail['stacktrace'] ?? '');

        return MarkdownSnapshot::assemble([
            ['Summary', self::overview($group, $report)],
            ['Request that hit it', self::request($report)],
            ['Suspect change', self::suspect($report)],
            ['App frames', self::appFrames($stacktrace)],
            ['Source', self::source($detail)],
            ['Releases seen', self::releases($report)],
            ['Stacktrace', $stacktrace !== '' ? "## Stacktrace\n\n".MarkdownSnapshot::fence($stacktrace, 'text') : ''],
        ], '_Snapshot from telemetry-ui, built from the stored exception records only._', $maxBytes);
    }

    /**
     * @param  Report  $report
     */
    private static function overview(string $group, array $report): string
    {
        $stats = $report['stats'];
        $detail = $report['detail'];
        $type = $detail['type'] ?? '';
        $message = $detail['message'] ?? '';

        $heading = $type !== '' ? class_basename($type) : 'Error '.$group;
        $lines = ['# '.rtrim(MarkdownSnapshot::text($heading, 120).($message !== '' ? ': '.MarkdownSnapshot::text(Str::limit($message, 100), 120) : ''), ': ')];

        $url = DashboardUrl::page('errors/'.$group);

        $overview = array_filter([
            $type !== '' ? '- **Exception:** '.MarkdownSnapshot::code($type) : null,
            $message !== '' ? '- **Message:** '.MarkdownSnapshot::text($message, 1000) : null,
            ($detail['file'] ?? '') !== '' ? '- **Location:** '.MarkdownSnapshot::code($detail['file'].':'.($detail['line'] ?? 0)) : null,
            '- **Group (fingerprint):** '.MarkdownSnapshot::code($group),
            $stats !== null ? '- **Runtime:** '.$stats['source'] : null,
            $stats !== null ? '- **Occurrences:** '.$stats['count'].($stats['sampled'] ? '+' : '').' in the last '.ErrorGroupReport::LOOKBACK_DAYS.' days' : null,
            $stats !== null ? '- **First seen:** '.$stats['firstSeen'].' · **last seen:** '.$stats['lastSeen'] : null,
            ($stats['users'] ?? 0) > 0 ? '- **Users affected:** '.$stats['users'].($stats['sampled'] ? '+' : '') : null,
            ($detail['environment'] ?? '') !== '' ? '- **Environment:** '.MarkdownSnapshot::text($detail['environment']) : null,
            ($detail['release'] ?? '') !== '' ? '- **Release:** '.MarkdownSnapshot::text($detail['release']) : null,
            ($detail['host'] ?? '') !== '' ? '- **Host:** '.MarkdownSnapshot::text($detail['host']) : null,
            $url !== null ? '- **Open:** '.$url : null,
        ], static fn (?string $line): bool => $line !== null);

        return implode("\n", [...$lines, '', '## Overview', ...$overview]);
    }

    /**
     * @param  Report  $report
     */
    private static function request(array $report): string
    {
        $request = $report['request'];
        $traceId = $request['traceId'] ?? ($report['occurrences'][0]['traceId'] ?? '');

        if ($request === null && $traceId === '') {
            return '';
        }

        $lines = [];

        if ($request !== null) {
            $endpoint = trim(($request['method'] !== '' ? $request['method'].' ' : '')
                .($request['route'] !== '' ? $request['route'] : $request['origin']));
            $lines = array_filter([
                $endpoint !== '' ? '- **Endpoint:** '.MarkdownSnapshot::text($endpoint) : null,
                $request['status'] !== '' ? '- **Response status:** '.$request['status'] : null,
                $request['user'] !== '' ? '- **User:** '.MarkdownSnapshot::text($request['user']) : null,
            ], static fn (?string $line): bool => $line !== null);
        }

        // The newest occurrence's trace: the snapshot of the request as a
        // whole — its queries, its time, what led up to the throw.
        // The id comes from a log record: only a real trace id becomes a link.
        if (preg_match('/^[0-9a-f]{16,32}$/i', $traceId) === 1) {
            $lines[] = '- **Trace:** '.MarkdownSnapshot::code($traceId)
                .(($page = DashboardUrl::page('traces/'.$traceId)) !== null ? ' — '.$page : '');

            if (($markdown = DashboardUrl::traceMarkdown($traceId)) !== null) {
                $lines[] = '- **Trace snapshot (Markdown):** '.$markdown.'?format=text';
            }
        }

        return $lines !== [] ? "## Request that hit it\n\n".implode("\n", $lines) : '';
    }

    /**
     * @param  Report  $report
     */
    private static function suspect(array $report): string
    {
        $suspect = $report['suspect'];

        if ($suspect === null) {
            return '';
        }

        $lines = ['## Suspect change', '', MarkdownSnapshot::text($suspect['label']).' ('.$suspect['kind'].') at '.$suspect['time'].' — first seen '.$suspect['gap'].' later.'];

        if (($suspect['notes'] ?? '') !== '') {
            $lines[] = MarkdownSnapshot::text((string) $suspect['notes'], 600);
        }

        return implode("\n", $lines);
    }

    private static function appFrames(string $stacktrace): string
    {
        if ($stacktrace === '') {
            return '';
        }

        $frames = StackFrames::app($stacktrace, self::MAX_APP_FRAMES);

        if ($frames['frames'] === []) {
            return '';
        }

        return "## App frames\n\n".count($frames['frames']).' of '.$frames['app'].' app frames, '.$frames['total']." in all; framework frames left out.\n\n"
            .MarkdownSnapshot::fence(implode("\n", $frames['frames']), 'text');
    }

    /**
     * @param  array{file: string, line: int, source: string}|null  $detail
     */
    private static function source(?array $detail): string
    {
        if ($detail === null || $detail['source'] === '') {
            return '';
        }

        return '## Source around the throw'.($detail['file'] !== '' ? ' ('.MarkdownSnapshot::code($detail['file'].':'.$detail['line']).')' : '')
            ."\n\nAs recorded in `exception.source`; `>` marks the line that threw.\n\n"
            .MarkdownSnapshot::fence(MarkdownSnapshot::limit($detail['source'], self::SOURCE_BYTES), 'php');
    }

    /**
     * @param  Report  $report
     */
    private static function releases(array $report): string
    {
        if ($report['releases'] === []) {
            return '';
        }

        $lines = ['## Releases seen', ''];

        foreach ($report['releases'] as $release) {
            $lines[] = '- '.MarkdownSnapshot::code($release['release']).' — '.$release['count'];
        }

        return implode("\n", $lines);
    }
}
