<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\LineFilter;
use Cbox\TelemetryUi\Queries\Ir\LineOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use DateTimeImmutable;

/**
 * Who changed what, when: one row per Statamic content change — entries,
 * terms, assets, collections, blueprints, fieldsets, navigations, globals,
 * users, forms, revisions — with the transition (created / updated /
 * published / unpublished / deleted / uploaded …), the fields an update
 * touched, the editor and a link into the CP.
 *
 * Read from the `statamic.content.change` records cboxdk/statamic-telemetry
 * (3.1+) writes to the logs backend. The counter panels beside it say how
 * much; this says what.
 */
final class ContentAuditLog extends Panel
{
    public const EVENT = 'statamic.content.change';

    private const LIMIT = 1000;

    #[Param('audit_type')]
    public string $type = '';

    #[Param('audit_action')]
    public string $action = '';

    #[Param('audit_q')]
    public string $query = '';

    /** Transition → badge tone. */
    private const TONES = [
        'created' => 'ok',
        'published' => 'ok',
        'uploaded' => 'ok',
        'publish' => 'ok',
        'updated' => 'info',
        'saved' => 'dim',
        'working' => 'dim',
        'revision' => 'dim',
        'unpublished' => 'warn',
        'unpublish' => 'warn',
        'replaced' => 'warn',
        'reuploaded' => 'warn',
        'restore' => 'warn',
        'deleted' => 'danger',
    ];

    public static function span(): int
    {
        return 3;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        try {
            $entries = $this->logs()->query(
                $this->logSelector()->pipe(new LineFilter(LineOp::Contains, self::EVENT)),
                $start,
                $end,
                limit: self::LIMIT,
            );
        } catch (SourceException $exception) {
            return $this->table([], [], [], $exception->getMessage());
        }

        $all = [];

        foreach ($entries as $entry) {
            if (trim($entry->line) === self::EVENT) {
                $all[] = self::row($entry);
            }
        }

        usort($all, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);

        $types = array_values(array_unique(array_column($all, 'type')));
        $actions = array_values(array_unique(array_column($all, 'action')));
        sort($types);
        sort($actions);

        $needle = mb_strtolower(trim($this->query));
        $shown = array_values(array_filter($all, fn (array $row): bool => ($this->type === '' || $row['type'] === $this->type)
            && ($this->action === '' || $row['action'] === $this->action)
            && ($needle === '' || str_contains(mb_strtolower($row['haystack']), $needle))));

        return $this->table($shown, $types, $actions, null, count($all) >= self::LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $types
     * @param  list<string>  $actions
     * @return array<string, mixed>
     */
    private function table(array $rows, array $types, array $actions, ?string $error, bool $capped = false): array
    {
        $table = array_map(fn (array $row): array => $this->cells($row), $rows);

        return Ui::table('Content audit log', [
            Ui::col('when', 'When', width: '128px'),
            Ui::col('action', 'Action', width: '104px'),
            Ui::col('item', 'Item', width: 'minmax(220px, 3fr)'),
            // Field handles run long (seo_description, price_table): room
            // for two or three before the ellipsis.
            Ui::col('changed', 'Changed', width: 'minmax(160px, 2fr)'),
            Ui::col('who', 'Who', width: 'minmax(120px, 1fr)'),
            Ui::col('open', '', width: '56px'),
        ], $table, array_filter([
            'subtitle' => 'Every change to content and structure — who made it, what it touched, and what it did.',
            'span' => 3,
            'error' => $error,
            'controls' => [
                Ui::select('audit_type', 'Type', $this->type, Ui::options(array_values(array_unique([...$types, ...($this->type !== '' ? [$this->type] : [])])))),
                Ui::select('audit_action', 'Action', $this->action, Ui::options(array_values(array_unique([...$actions, ...($this->action !== '' ? [$this->action] : [])])))),
                Ui::search('audit_q', 'Search', $this->query, 'Title, id, editor, field…'),
            ],
            'note' => $capped ? 'The newest '.self::LIMIT.' changes in this window — narrow the window to see further back.' : null,
            'empty' => $this->type !== '' || $this->action !== '' || $this->query !== ''
                ? 'No changes match these filters in this period.'
                : 'No content changes in this period. The audit log needs cboxdk/statamic-telemetry 3.1+ writing `statamic.content.change` events to the logs backend.',
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function cells(array $row): array
    {
        $at = (new DateTimeImmutable)->setTimestamp(intdiv((int) $row['ts'], 1000));
        $context = implode(' · ', array_filter([$row['type'], $row['scope'], $row['site']], static fn (?string $v): bool => $v !== null && $v !== ''));

        $cells = [
            'when' => Ui::cell($at->format('d/m H:i:s'), ['raw' => $row['ts'], 'mono' => true]),
            'action' => Ui::cell($row['action'], ['badge' => self::TONES[$row['action']] ?? 'info']),
            'item' => Ui::cell($row['title'] ?? $row['handle'] ?? $row['id'] ?? '—', array_filter([
                'sub' => $context.($row['message'] !== null ? ' — “'.$row['message'].'”' : ''),
                'link' => $row['edit'] !== null ? Ui::url($row['edit']) : null,
            ])),
            'changed' => $row['changed'] !== null ? Ui::cell($row['changed'], ['tone' => 'dim']) : Ui::cell('—', ['tone' => 'dim']),
            'who' => Ui::cell($row['who'] ?? $row['origin'] ?? '—', array_filter([
                'sub' => $row['who'] !== null ? $row['origin'] : $row['command'],
                'dim' => $row['userId'] !== null && $row['who'] === $row['userId'] ? ['key' => 'user.id', 'value' => $row['userId']] : null,
            ])),
            'open' => $row['trace'] !== null
                ? Ui::cell('trace', ['link' => Ui::trace($row['trace'], $at), 'tone' => 'info'])
                : Ui::cell(''),
        ];

        if ($row['trace'] !== null) {
            $cells['_link'] = Ui::trace($row['trace'], $at);
        }

        return $cells;
    }

    /**
     * @return array{ts: int, type: string, action: string, id: string|null, title: string|null, handle: string|null, scope: string|null, site: string|null, changed: string|null, message: string|null, edit: string|null, who: string|null, userId: string|null, origin: string|null, command: string|null, trace: string|null, haystack: string}
     */
    private static function row(LogEntry $entry): array
    {
        $l = $entry->labels;
        $get = static function (string $key) use ($l): ?string {
            $value = $l[$key] ?? $l[str_replace('.', '_', $key)] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        $userId = $get('user.id');
        $who = $get('user.name') ?? $userId;
        $edit = $get('statamic.content.edit_url');

        $row = [
            'ts' => intdiv($entry->timestampNano, 1_000_000),
            'type' => $get('statamic.content.type') ?? 'unknown',
            'action' => $get('statamic.content.action') ?? 'changed',
            'id' => $get('statamic.content.id'),
            'title' => $get('statamic.content.title'),
            'handle' => $get('statamic.content.handle'),
            'scope' => $get('statamic.collection') ?? $get('statamic.taxonomy') ?? $get('statamic.asset.container') ?? $get('statamic.form') ?? $get('statamic.blueprint'),
            'site' => $get('statamic.site'),
            'changed' => $get('statamic.content.changed'),
            'message' => $get('statamic.revision.message'),
            // Only a web link: this came from the logs backend, not from us.
            'edit' => $edit !== null && preg_match('#^https?://#i', $edit) === 1 ? $edit : null,
            'who' => $who,
            'userId' => $userId,
            'origin' => $get('statamic.content.origin'),
            'command' => $get('process.command'),
            'trace' => $get('trace_id') ?? $get('traceId'),
        ];

        $row['haystack'] = implode(' ', array_filter([
            $row['title'], $row['id'], $row['handle'], $row['scope'], $row['who'], $row['changed'], $row['message'], $get('statamic.content.url'), $get('statamic.content.path'),
        ]));

        return $row;
    }
}
