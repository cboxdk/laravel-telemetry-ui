<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of an agent's detail page: the agent, the models it ran on,
 * and its headline numbers from a sample of its recent runs.
 */
final class AiAgentHeader extends Panel
{
    use ReadsAgentRuns;
    use ReadsAiUsage;
    use ScopesToAiAgent {
        ScopesToAiAgent::scopedAgent insteadof ReadsAiUsage;
    }

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $runs = [];
        $error = null;

        if ($this->agent !== '') {
            try {
                $runs = $this->agentRuns();
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $count = count($runs);
        $durations = array_column($runs, 'durationMs');
        sort($durations);
        $priced = array_filter(array_column($runs, 'cost'), static fn (?float $cost): bool => $cost !== null);
        $failed = count(array_filter($runs, static fn (array $run): bool => $run['failed']));
        $models = array_values(array_unique(array_filter(array_column($runs, 'model'))));
        $traces = Ui::explore('traces', ['gen_ai.agent.name='.$this->agent]);

        return Ui::header(
            $this->agent === '' ? '(all agents)' : $this->agent,
            implode(' · ', array_filter(['Agent', implode(', ', array_slice($models, 0, 3))])),
            [
                $this->stat('Runs', Format::count($count), null, $traces),
                $this->stat('AVG', $count > 0 ? Format::ms(array_sum($durations) / $count) : '—', $count > 0 ? null : 'dim'),
                $this->stat('P95', $count > 0 ? Format::ms($durations[(int) min($count - 1, floor($count * 0.95))]) : '—', $count > 0 ? 'warn' : 'dim'),
                $this->stat('Tokens in / out', Format::count(array_sum(array_column($runs, 'input'))).' / '.Format::count(array_sum(array_column($runs, 'output')))),
                $priced !== []
                    ? $this->stat('Cost', Format::money(array_sum($priced), $this->aiCurrency()))
                    : $this->stat('Cost', '—', 'dim'),
                $this->stat('Failed', Format::count($failed), $failed > 0 ? 'danger' : 'dim', Ui::explore('traces', ['gen_ai.agent.name='.$this->agent, 'status=error'])),
            ],
            array_filter([
                'back' => Ui::page('ai'),
                'backLabel' => '← AI',
                'error' => $error,
                'note' => $count > 0 ? 'From the '.Format::count($count).' most recent runs in this period (Tempo search), not a total.' : null,
            ], static fn ($v): bool => $v !== null),
        );
    }
}
