<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Dimensions\Dimension;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiCalls;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\TelemetryUiManager;
use DateTimeImmutable;

/**
 * Who uses the models, and what it costs: model calls grouped by a context
 * dimension — the user, a declared dimension such as a team or tenant
 * (anything `Telemetry::context()` adds), the client IP, or any attribute.
 * Unbounded values like user ids do not belong in metric labels, so this
 * reads the usage records, or the spans when there are none.
 *
 * @phpstan-import-type Link from Ui
 */
final class AiUsageByDimension extends Panel
{
    use ReadsAiCalls;
    use ReadsAiUsage;

    private const CUSTOM = 'custom';

    #[Param('ai_by')]
    public string $by = 'user.id';

    #[Param('ai_by_attr')]
    public string $customAttribute = '';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $attribute = $this->attribute();
        $dimension = $attribute !== null ? app(TelemetryUiManager::class)->dimensions()->get($attribute) : null;
        $label = $dimension->label ?? ($attribute ?? 'attribute');
        $currency = $this->aiCurrency();

        $rows = [];
        $error = null;
        $read = null;

        if ($attribute === null) {
            $error = 'Enter a span attribute, e.g. team.id or plan.';
        } else {
            try {
                $read = $this->aiCalls($attribute);

                foreach ($read['calls'] as $call) {
                    $value = self::aiCallValue($call, $attribute);

                    if ($value === '') {
                        continue;
                    }

                    $rows[$value] ??= ['value' => $value, 'calls' => 0, 'input' => 0.0, 'output' => 0.0, 'cost' => null, 'unpriced' => 0, 'errors' => 0, 'last' => 0];
                    $rows[$value]['calls']++;
                    $rows[$value]['input'] += $call['input'];
                    $rows[$value]['output'] += $call['output'];
                    $rows[$value]['errors'] += $call['error'] !== '' ? 1 : 0;
                    $rows[$value]['last'] = max($rows[$value]['last'], $call['ts']);

                    if ($call['cost'] !== null) {
                        $rows[$value]['cost'] = ($rows[$value]['cost'] ?? 0.0) + $call['cost'];
                    } elseif ($call['input'] + $call['output'] > 0) {
                        $rows[$value]['unpriced']++;
                    }
                }
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['cost'] ?? -1.0, $b['calls']] <=> [$a['cost'] ?? -1.0, $a['calls']]);

        $cells = array_map(function (array $row) use ($attribute, $dimension, $currency): array {
            $link = $this->valueLink((string) $attribute, $row['value'], $dimension);
            $errorRate = $row['calls'] > 0 ? $row['errors'] / $row['calls'] : 0.0;
            $today = date('Y-m-d');
            $last = new DateTimeImmutable('@'.intdiv($row['last'], 1000));

            return [
                'value' => Ui::cell($row['value'], [
                    'mono' => true,
                    'link' => $link,
                    ...($dimension !== null ? ['dim' => ['key' => $dimension->key, 'value' => $row['value']]] : []),
                ]),
                'calls' => Ui::cell(Format::count($row['calls']), ['raw' => $row['calls']]),
                'input' => Ui::cell(Format::count($row['input']), ['raw' => $row['input']]),
                'output' => Ui::cell(Format::count($row['output']), ['raw' => $row['output']]),
                'cost' => match (true) {
                    $row['cost'] !== null => Ui::cell(Format::money($row['cost'], $currency), [
                        'raw' => $row['cost'],
                        ...($row['unpriced'] > 0 ? ['sub' => Format::count($row['unpriced']).' unpriced'] : []),
                    ]),
                    $row['unpriced'] > 0 => Ui::cell('no price', ['tone' => 'dim']),
                    default => Ui::cell('—', ['tone' => 'dim']),
                },
                'errors' => Ui::cell(Format::percent($errorRate), ['raw' => $errorRate, 'tone' => $row['errors'] > 0 ? 'danger' : null]),
                'last' => Ui::cell(
                    $last->format($last->format('Y-m-d') === $today ? 'H:i:s' : 'M j H:i'),
                    ['raw' => $row['last'], 'mono' => true],
                ),
                '_link' => $link,
            ];
        }, array_slice($rows, 0, 100));

        return Ui::table('AI usage by '.lcfirst($label), [
            Ui::col('value', $label),
            Ui::num('calls', 'Calls'),
            Ui::num('input', 'Input'),
            Ui::num('output', 'Output'),
            Ui::num('cost', 'Cost ('.$currency.')'),
            Ui::num('errors', 'Errors'),
            Ui::num('last', 'Last call'),
        ], $cells, array_filter([
            'subtitle' => 'Model calls, tokens and cost per user, team, tenant, IP or any context attribute',
            'controls' => $this->controls(),
            'error' => $error,
            'empty' => 'No model calls carrying '.($attribute ?? 'this attribute').' in this period.',
            'note' => $read !== null ? self::aiSourceNote($read) : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * The attribute to group by; null for a custom one not entered yet.
     */
    private function attribute(): ?string
    {
        $key = $this->by === self::CUSTOM ? trim($this->customAttribute) : $this->by;

        return preg_match('/^[a-zA-Z0-9_.\-]+$/', $key) === 1 ? $key : null;
    }

    /**
     * The user, every declared custom dimension (team, tenant, …), the client
     * IP and a free-form attribute.
     *
     * @return list<array<string, mixed>>
     */
    private function controls(): array
    {
        $options = ['user.id' => 'User'];

        foreach (app(TelemetryUiManager::class)->dimensions()->all() as $dimension) {
            if (! $dimension->builtin && $dimension->derived === null) {
                $options[$dimension->key] = $dimension->label;
            }
        }

        $options['client.address'] = 'Client IP';
        $selected = $this->by;

        // A deep link to a declared key that is not in the list still shows.
        if ($selected !== self::CUSTOM && ! isset($options[$selected]) && $this->attribute() !== null) {
            $options[$selected] = $selected;
        }

        $controls = [Ui::select('ai_by', 'By', $selected, [
            ...array_map(
                static fn (string $key, string $label): array => ['value' => $key, 'label' => $label],
                array_keys($options),
                array_values($options),
            ),
            ['value' => self::CUSTOM, 'label' => 'Custom attribute…'],
        ])];

        if ($selected === self::CUSTOM) {
            $controls[] = Ui::search('ai_by_attr', 'Attribute', $this->customAttribute, 'span attribute, e.g. plan');
        }

        return $controls;
    }

    /**
     * A value's own page when the dimension has one (a user, a tenant), its
     * model calls in Explore otherwise.
     *
     * @return Link
     */
    private function valueLink(string $attribute, string $value, ?Dimension $dimension): array
    {
        return $dimension?->entity !== null
            ? Ui::entity($dimension->entitySlug(), $value)
            : Ui::explore('traces', ['gen_ai.operation.name=~chat|embeddings', $attribute.'='.$value]);
    }
}
