<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Panels\Builtin\AiAgentRuns;
use Cbox\TelemetryUi\Panels\Builtin\AiCalls;
use Cbox\TelemetryUi\Panels\Builtin\AiErrors;
use Cbox\TelemetryUi\Panels\Builtin\AiLatency;
use Cbox\TelemetryUi\Panels\Builtin\AiModels;
use Cbox\TelemetryUi\Panels\Builtin\AiOverview;
use Cbox\TelemetryUi\Panels\Builtin\AiTools;
use Cbox\TelemetryUi\Panels\Builtin\AiUsageByDimension;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiAgentActivity;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiAgentHeader;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiAgentRunLog;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiAgentTools;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiModelCalls;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiModelHeader;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiModelLatency;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiModelTokens;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiUsageCalls;
use Cbox\TelemetryUi\Panels\Builtin\Detail\AiUsageSummary;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A Prometheus fake for the GenAI metrics: each instant query is answered by
 * the first entry whose needles all appear in the PromQL, range queries by
 * `$range` the same way. Anything else is an empty result, as Prometheus
 * answers for a metric that was never emitted.
 *
 * @param  list<array{0: list<string>, 1: list<array{0: array<string, string>, 1: float|int}>|int}>  $instant  needles → series, or an HTTP status to fail with
 * @param  list<array{0: list<string>, 1: list<array{0: array<string, string>, 1: list<float>}>}>  $range
 */
function aiBackend(array $instant, array $range = []): void
{
    $match = static function (string $query, array $entries): mixed {
        foreach ($entries as [$needles, $answer]) {
            if (array_filter($needles, static fn (string $needle): bool => ! str_contains($query, $needle)) === []) {
                return $answer;
            }
        }

        return [];
    };

    Http::fake([
        'prometheus.test:9090/api/v1/query_range*' => function (Request $request) use ($range, $match) {
            $series = $match((string) (requestQuery($request)['query'] ?? ''), $range);

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => array_map(
                static fn (array $s): array => ['metric' => $s[0], 'values' => array_map(
                    static fn (int $i, float $v): array => [1735689600 + $i * 60, (string) $v],
                    array_keys($s[1]),
                    $s[1],
                )],
                $series,
            )]]);
        },
        'prometheus.test:9090/api/v1/query*' => function (Request $request) use ($instant, $match) {
            $answer = $match((string) (requestQuery($request)['query'] ?? ''), $instant);

            return is_int($answer)
                ? Http::response(['status' => 'error', 'errorType' => 'internal', 'error' => 'query failed'], $answer)
                : Http::response(labelledVector($answer));
        },
    ]);
}

/** The PromQL of every range query the test sent. */
function aiRangeQueries(): array
{
    $out = [];

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), 'prometheus.test') && str_contains($request->url(), 'query_range')) {
            $out[] = (string) (requestQuery($request)['query'] ?? '');
        }
    }

    return $out;
}

$gpt = ['gen_ai_provider_name' => 'openai', 'gen_ai_request_model' => 'gpt-4o'];
$claude = ['gen_ai_provider_name' => 'anthropic', 'gen_ai_request_model' => 'claude-sonnet'];

it('charts cost per model and headlines cost, tokens, calls and error rate when prices are configured', function (): void {
    config()->set('telemetry.instrument.ai_pricing.currency', 'eur');

    aiBackend([
        [['gen_ai_client_cost_total'], [[[], 12.5]]],
        [['gen_ai_client_token_usage_sum', 'gen_ai_token_type'], [
            [['gen_ai_token_type' => 'input'], 120_000],
            [['gen_ai_token_type' => 'output'], 30_000],
        ]],
        [['_count', 'error_type!=""'], [[[], 5]]],
        [['_count'], [[[], 200]]],
    ], [
        [['gen_ai_client_cost_total'], [[['gen_ai_request_model' => 'gpt-4o'], [0.5, 1.5]]]],
    ]);

    $this->getJson(panelUrl(AiOverview::id()))
        ->assertOk()
        ->assertJsonPath('kind', 'chart')
        ->assertJsonPath('series.0.name', 'gpt-4o')
        ->assertJsonPath('unit', 'EUR/h')
        ->assertJsonPath('note', null)
        ->assertJsonPath('stats.0.label', 'Cost')
        ->assertJsonPath('stats.0.value', '€12.50')
        ->assertJsonPath('stats.1.value', '120K')
        ->assertJsonPath('stats.2.value', '30K')
        ->assertJsonPath('stats.3.value', '200')
        ->assertJsonPath('stats.4.value', '2.5%')
        ->assertJsonPath('stats.4.tone', 'danger');

    expect(aiRangeQueries())->toHaveCount(1)
        ->and(aiRangeQueries()[0])->toContain('gen_ai_client_cost_total');
});

it('falls back to tokens and hints at the pricing config when no cost series exists', function (): void {
    aiBackend([
        [['gen_ai_client_token_usage_sum'], [[['gen_ai_token_type' => 'input'], 900]]],
        [['_count'], [[[], 10]]],
    ], [
        [['gen_ai_client_token_usage_sum'], [[['gen_ai_request_model' => 'gpt-4o'], [10, 20]]]],
    ]);

    $this->getJson(panelUrl(AiOverview::id()))
        ->assertOk()
        ->assertJsonPath('unit', 'tokens/min')
        ->assertJsonPath('series.0.name', 'gpt-4o')
        ->assertJsonPath('stats.0.value', 'No prices')
        ->assertJsonPath('note', 'No model prices configured. Set telemetry.instrument.ai_pricing.models in the app to see what calls cost.')
        ->assertJsonPath('error', null);

    expect(aiRangeQueries()[0])->toContain('gen_ai_client_token_usage_sum')
        ->and(aiRangeQueries()[0])->not->toContain('gen_ai_client_cost_total');
});

it('shows a dash, not a zero, for the output tokens of an embeddings-only model', function (): void {
    $embed = ['gen_ai_provider_name' => 'openai', 'gen_ai_request_model' => 'text-embedding-3-small'];

    aiBackend([
        [['_count'], [[[...$embed, 'gen_ai_operation_name' => 'embeddings'], 40]]],
        [['gen_ai_client_token_usage_sum'], [[[...$embed, 'gen_ai_token_type' => 'input'], 9_000]]],
    ]);

    $this->getJson(panelUrl(AiModels::id()))
        ->assertOk()
        ->assertJsonPath('rows.0.input.v', '9K')
        ->assertJsonPath('rows.0.output.v', '—');
});

it('shows an empty chart and no error when nothing emits gen_ai metrics', function (): void {
    aiBackend([]);

    $this->getJson(panelUrl(AiOverview::id()))
        ->assertOk()
        ->assertJsonPath('series', [])
        ->assertJsonPath('error', null)
        ->assertJsonPath('empty', 'No AI model calls recorded in this period.')
        ->assertJsonPath('stats.3.value', '0')
        ->assertJsonPath('stats.4.value', '0%');
});

it('lists models with calls, tokens, cost, p95 and error rate, marking an unpriced model rather than calling it free', function () use ($gpt, $claude): void {
    aiBackend([
        [['gen_ai_client_cost_total'], [[$gpt, 4.2]]],
        [['_bucket'], [[$gpt, 1.2], [$claude, 3.4]]],
        [['_count', 'error_type!=""'], [[$claude, 3]]],
        [['_count'], [
            [[...$gpt, 'gen_ai_operation_name' => 'chat'], 90],
            [[...$gpt, 'gen_ai_operation_name' => 'embeddings'], 10],
            [[...$claude, 'gen_ai_operation_name' => 'chat'], 30],
        ]],
        [['gen_ai_client_token_usage_sum'], [
            [[...$gpt, 'gen_ai_token_type' => 'input'], 50_000],
            [[...$gpt, 'gen_ai_token_type' => 'output'], 8_000],
            [[...$claude, 'gen_ai_token_type' => 'input'], 20_000],
            [[...$claude, 'gen_ai_token_type' => 'output'], 4_000],
        ]],
    ]);

    $this->getJson(panelUrl(AiModels::id()))
        ->assertOk()
        // Priced (and costliest) first.
        ->assertJsonPath('rows.0.model.v', 'gpt-4o')
        ->assertJsonPath('rows.0.model.sub', 'chat, embeddings')
        ->assertJsonPath('rows.0.provider.v', 'openai')
        ->assertJsonPath('rows.0.calls.v', '100')
        ->assertJsonPath('rows.0.input.v', '50K')
        ->assertJsonPath('rows.0.output.v', '8K')
        ->assertJsonPath('rows.0.cost.v', '$4.20')
        ->assertJsonPath('rows.0.p95.v', '1.2s')
        ->assertJsonPath('rows.0.errors.v', '0%')
        ->assertJsonPath('rows.1.model.v', 'claude-sonnet')
        ->assertJsonPath('rows.1.cost.v', 'no price')
        ->assertJsonPath('rows.1.errors.v', '10%')
        ->assertJsonPath('rows.1.errors.tone', 'danger')
        // A row opens the model's own page.
        ->assertJsonPath('rows.1._link', ['to' => 'entity', 'type' => 'ai-model', 'value' => 'claude-sonnet'])
        ->assertJsonPath('rows.1.model.dim', ['key' => 'gen_ai.request.model', 'value' => 'claude-sonnet'])
        ->assertJsonPath('columns.5.label', 'Cost (USD)')
        ->assertJsonPath('note', 'Models marked "no price" used tokens but have no entry in telemetry.instrument.ai_pricing.models, so their cost is unknown, not zero.');
});

it('hints at the pricing config when no model is priced', function () use ($gpt): void {
    aiBackend([
        [['_count'], [[[...$gpt, 'gen_ai_operation_name' => 'chat'], 4]]],
        [['gen_ai_client_token_usage_sum'], [[[...$gpt, 'gen_ai_token_type' => 'input'], 400]]],
    ]);

    $this->getJson(panelUrl(AiModels::id()))
        ->assertOk()
        ->assertJsonPath('rows.0.cost.v', 'no price')
        ->assertJsonPath('note', 'No model prices configured. Set telemetry.instrument.ai_pricing.models in the app to see what calls cost.');
});

it('does not call a model unpriced when the cost counter could not be read', function () use ($gpt): void {
    aiBackend([
        [['gen_ai_client_cost_total'], 500],
        [['_count'], [[[...$gpt, 'gen_ai_operation_name' => 'chat'], 4]]],
        [['gen_ai_client_token_usage_sum'], [[[...$gpt, 'gen_ai_token_type' => 'input'], 400]]],
    ]);

    $response = $this->getJson(panelUrl(AiModels::id()))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('rows.0.calls.v', '4')
        ->assertJsonPath('rows.0.cost.v', '—');

    expect($response->json('note'))->toStartWith('Part of this table is missing');
});

it('says so when no models were called', function (): void {
    aiBackend([]);

    $this->getJson(panelUrl(AiModels::id()))
        ->assertOk()
        ->assertJsonPath('rows', [])
        ->assertJsonMissingPath('note')
        ->assertJsonPath('empty', 'No AI model calls recorded in this period.');
});

it('returns the error instead of throwing when prometheus fails', function (): void {
    aiBackend([[['_count'], 500]]);

    expect($this->getJson(panelUrl(AiModels::id()))->assertOk()->json('error'))->toBeString();
    expect($this->getJson(panelUrl(AiErrors::id()))->assertOk()->json('error'))->toBeString();
    expect($this->getJson(panelUrl(AiOverview::id()))->assertOk()->json('error'))->toBeString();
});

it('charts chat p50 and p95 per provider', function (): void {
    aiBackend([
        [['histogram_quantile(0.5'], [[[], 0.8]]],
        [['histogram_quantile(0.95'], [[[], 2.5]]],
    ], [
        [['histogram_quantile(0.5'], [[['gen_ai_provider_name' => 'openai'], [800, 900]]]],
        [['histogram_quantile(0.95'], [[['gen_ai_provider_name' => 'openai'], [2000, 2600]]]],
    ]);

    $this->getJson(panelUrl(AiLatency::id()))
        ->assertOk()
        ->assertJsonPath('series.0.name', 'openai p50')
        ->assertJsonPath('series.1.name', 'openai p95')
        ->assertJsonPath('stats.0.value', '800ms')
        ->assertJsonPath('stats.1.value', '2.5s');

    foreach (aiRangeQueries() as $query) {
        expect($query)->toContain('gen_ai_operation_name="chat"');
    }
});

it('breaks errors down by provider and error type with their share of calls', function (): void {
    aiBackend([
        [['error_type!=""'], [
            [['gen_ai_provider_name' => 'openai', 'error_type' => 'RateLimited'], 8],
            [['gen_ai_provider_name' => 'anthropic', 'error_type' => 'Timeout'], 1],
        ]],
        [['_count'], [
            [['gen_ai_provider_name' => 'openai'], 80],
            [['gen_ai_provider_name' => 'anthropic'], 50],
        ]],
    ]);

    $this->getJson(panelUrl(AiErrors::id()))
        ->assertOk()
        ->assertJsonPath('kind', 'bars')
        ->assertJsonPath('items.0.label', 'openai · RateLimited')
        ->assertJsonPath('items.0.display', '8')
        ->assertJsonPath('items.0.sub', '10% of 80 calls')
        ->assertJsonPath('items.1.label', 'anthropic · Timeout');
});

/**
 * A Tempo fake that answers the structural tools search (`{run} >> {tool}`)
 * with `$tools`, and every other search with `$traces`.
 *
 * @param  list<array<string, mixed>>  $traces
 * @param  list<array<string, mixed>>  $tools
 */
function aiTempo(array $traces, array $tools = []): void
{
    Http::fake([
        'tempo.test:3200/api/search*' => fn (Request $request) => Http::response([
            'traces' => str_contains((string) (requestQuery($request)['q'] ?? ''), '>>') ? $tools : $traces,
        ]),
        // No usage records: the panels that prefer them fall back to spans.
        'loki.test:3100/*' => Http::response(lokiStreams([])),
    ]);
}

it('lists agent runs from invoke_agent spans, sortable by cost, each opening its trace', function (): void {
    aiTempo([
        tempoHit('aaaa1111aaaa1111aaaa1111aaaa1111', 'invoke_agent SupportAgent', 1735689600, 4200, [
            'gen_ai.agent.name' => 'SupportAgent',
            'gen_ai.request.model' => 'gpt-4o',
            'gen_ai.provider.name' => 'openai',
            'gen_ai.usage.input_tokens' => 1200,
            'gen_ai.usage.output_tokens' => 300,
            'gen_ai.usage.cost' => 0.0042,
            'gen_ai.response.finish_reasons' => '["stop"]',
        ]),
        tempoHit('bbbb2222bbbb2222bbbb2222bbbb2222', 'invoke_agent Researcher', 1735689660, 9100, [
            'gen_ai.agent.name' => 'Researcher',
            'gen_ai.request.model' => 'claude-sonnet',
            'gen_ai.usage.input_tokens' => 5000,
            'gen_ai.usage.output_tokens' => 900,
            'gen_ai.response.finish_reasons' => '["length"]',
        ]),
    ], [
        tempoHit('aaaa1111aaaa1111aaaa1111aaaa1111', 'execute_tool lookup_order', 1735689601, 40, ['gen_ai.tool.name' => 'lookup_order']),
    ]);

    $this->getJson(panelUrl(AiAgentRuns::id()))
        ->assertOk()
        ->assertJsonPath('title', 'Slowest agent runs')
        ->assertJsonPath('rows.0.agent.v', 'Researcher')
        ->assertJsonPath('rows.0.agent.link', ['to' => 'entity', 'type' => 'ai-agent', 'value' => 'Researcher'])
        ->assertJsonPath('rows.0.cost.v', '—')
        ->assertJsonPath('rows.0.tools.v', 'none')
        ->assertJsonPath('rows.0.status.v', 'length')
        ->assertJsonPath('rows.0.status.tone', 'warn')
        ->assertJsonPath('rows.0.duration.v', '9.1s')
        ->assertJsonPath('rows.1.agent.v', 'SupportAgent');

    $this->getJson(panelUrl(AiAgentRuns::id(), ['ai_sort' => 'cost']))
        ->assertOk()
        ->assertJsonPath('title', 'Most expensive agent runs')
        ->assertJsonPath('rows.0.agent.v', 'SupportAgent')
        ->assertJsonPath('rows.0.model.v', 'gpt-4o')
        ->assertJsonPath('rows.0.agent.sub', gmdate('H:i:s', 1735689600))
        ->assertJsonPath('rows.0.tokens.v', '1.2K / 300')
        ->assertJsonPath('rows.0.cost.v', '$0.0042')
        ->assertJsonPath('rows.0.status.v', 'stop')
        ->assertJsonPath('rows.0.tools.v', 'lookup_order')
        ->assertJsonPath('rows.0.model.link', ['to' => 'entity', 'type' => 'ai-model', 'value' => 'gpt-4o'])
        ->assertJsonPath('rows.0._link.to', 'trace')
        ->assertJsonPath('rows.0._link.id', 'aaaa1111aaaa1111aaaa1111aaaa1111');

    expect(sentTraceql()[0])->toContain('span.gen_ai.operation.name = "invoke_agent"');
});

it('shows the traces backend error, not an exception, when tempo is unreachable', function (): void {
    Http::fake(['tempo.test:3200/*' => Http::response('unavailable', 503)]);

    foreach ([AiAgentRuns::id(), AiTools::id()] as $panel) {
        $data = $this->getJson(panelUrl($panel))->assertOk()->json();

        expect($data['kind'])->toBe('table')
            ->and($data['rows'])->toBe([])
            ->and($data['error'])->toBeString();
    }
});

it('ranks agent tools by runs from sampled execute_tool spans', function (): void {
    Http::fake([
        'tempo.test:3200/api/search*' => Http::response(['traces' => [
            tempoHit('cccc1111cccc1111cccc1111cccc1111', 'execute_tool search_docs', 1735689600, 120, ['gen_ai.tool.name' => 'search_docs']),
            tempoHit('cccc2222cccc2222cccc2222cccc2222', 'execute_tool search_docs', 1735689660, 480, ['gen_ai.tool.name' => 'search_docs']),
            tempoHit('cccc3333cccc3333cccc3333cccc3333', 'execute_tool send_email', 1735689720, 900, []),
        ]]),
    ]);

    $this->getJson(panelUrl(AiTools::id()))
        ->assertOk()
        ->assertJsonPath('rows.0.tool.v', 'search_docs')
        ->assertJsonPath('rows.0.runs.v', '2')
        ->assertJsonPath('rows.0.avg.v', '300ms')
        ->assertJsonPath('rows.0.max.v', '480ms')
        // A row opens the slowest run.
        ->assertJsonPath('rows.0._link.id', 'cccc2222cccc2222cccc2222cccc2222')
        // Falls back to the span name when the attribute is missing.
        ->assertJsonPath('rows.1.tool.v', 'send_email');

    expect(sentTraceql()[0])->toContain('span.gen_ai.operation.name = "execute_tool"');
});

it('shows the AI page only when gen_ai metrics exist', function (bool $emitted): void {
    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response([
            'status' => 'success',
            'data' => $emitted ? ['gen_ai_client_token_usage_sum'] : ['http_server_request_duration_seconds_count'],
        ]),
        'prometheus.test:9090/*' => Http::response(labelledVector([])),
    ]);

    $response = $this->getJson(apiUrl('pages/ai'));

    $emitted
        ? $response->assertOk()->assertJsonPath('panels.0.id', 'ai-overview')
        : $response->assertNotFound();
})->with(['emitted' => true, 'absent' => false]);

it('lists individual model calls with tokens, cache, cost and how each ended', function (): void {
    aiTempo([
        tempoHit('dddd1111dddd1111dddd1111dddd1111', 'chat gpt-4o', 1735689600, 1800, [
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'gpt-4o',
            'gen_ai.provider.name' => 'openai',
            'gen_ai.usage.input_tokens' => 2400,
            'gen_ai.usage.output_tokens' => 310,
            'gen_ai.usage.cache_read.input_tokens' => 1200,
            'gen_ai.usage.cache_creation.input_tokens' => 0,
            'gen_ai.usage.reasoning.output_tokens' => 128,
            'gen_ai.usage.cost' => 0.0004,
            'gen_ai.response.finish_reasons' => '["stop"]',
        ]),
        tempoHit('eeee2222eeee2222eeee2222eeee2222', 'chat claude-sonnet', 1735689660, 30_500, [
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'claude-sonnet',
            'error.type' => 'RateLimitExceededException',
        ]),
    ]);

    $this->getJson(panelUrl(AiCalls::id()))
        ->assertOk()
        ->assertJsonPath('title', 'Recent model calls')
        // Newest first.
        ->assertJsonPath('rows.0.call.v', 'claude-sonnet')
        ->assertJsonPath('rows.0.call.link', ['to' => 'entity', 'type' => 'ai-model', 'value' => 'claude-sonnet'])
        // Short enough for the badge; the full type is in the trace.
        ->assertJsonPath('rows.0.status.v', 'RateLimitExceeded')
        ->assertJsonPath('rows.0.status.tone', 'danger')
        ->assertJsonPath('rows.0.cache.v', '—')
        ->assertJsonPath('rows.0.duration.tone', 'warn')
        // Chat is not named; the column is gone.
        ->assertJsonMissingPath('rows.1.operation')
        ->assertJsonPath('rows.1.tokens.v', '2.4K / 310')
        ->assertJsonPath('rows.1.tokens.sub', '128 reasoning')
        ->assertJsonPath('rows.1.cache.v', '1.2K / 0')
        ->assertJsonPath('rows.1.cost.v', '$0.0004')
        ->assertJsonPath('rows.1.status.v', 'stop')
        ->assertJsonPath('rows.1._link', ['to' => 'trace', 'id' => 'dddd1111dddd1111dddd1111dddd1111', 'at' => 1735689600000]);

    expect(sentTraceql()[0])->toContain('span.gen_ai.operation.name =~ "chat|embeddings"');
});

it('scopes a model detail page to that model across metrics and traces', function () use ($gpt): void {
    aiBackend([
        [['gen_ai_client_cost_total'], [[[], 3.5]]],
        [['_bucket'], [[[], 1.5]]],
        [['_count', 'error_type!=""'], [[[], 2]]],
        [['_count', 'gen_ai_operation_name'], [[[...$gpt, 'gen_ai_operation_name' => 'chat'], 40]]],
        [['_count'], [[[], 40]]],
        [['gen_ai_client_token_usage_sum'], [
            [['gen_ai_token_type' => 'input'], 9_000],
            [['gen_ai_token_type' => 'output'], 1_500],
        ]],
    ]);

    $this->getJson(panelUrl(AiModelHeader::id(), ['model' => 'gpt-4o']))
        ->assertOk()
        ->assertJsonPath('kind', 'header')
        ->assertJsonPath('title', 'gpt-4o')
        ->assertJsonPath('subtitle', 'AI model · openai · chat')
        ->assertJsonPath('back', ['to' => 'page', 'page' => 'ai'])
        ->assertJsonPath('stats.0.value', '40')
        ->assertJsonPath('stats.3.value', '$3.50')
        ->assertJsonPath('stats.4.value', '1.5s')
        ->assertJsonPath('stats.5.value', '5%');

    $this->getJson(panelUrl(AiModelTokens::id(), ['model' => 'gpt-4o']))->assertOk()->assertJsonPath('stats.2.value', '$3.50');
    $this->getJson(panelUrl(AiModelLatency::id(), ['model' => 'gpt-4o']))->assertOk()->assertJsonPath('title', 'Latency');

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), 'prometheus.test')) {
            expect((string) (requestQuery($request)['query'] ?? ''))->toContain('gen_ai_request_model="gpt-4o"');
        }
    }

    aiTempo([]);

    $this->getJson(panelUrl(AiModelCalls::id(), ['model' => 'gpt-4o']))
        ->assertOk()
        ->assertJsonPath('title', 'Calls')
        ->assertJsonPath('columns.1.label', 'Origin')
        ->assertJsonPath('empty', 'No calls to this model traced in this period.');

    expect(sentTraceql()[0])->toContain('span.gen_ai.request.model = "gpt-4o"');
});

it('says an unpriced model has no price on its own page, not that it was free', function () use ($gpt): void {
    aiBackend([
        [['_count'], [[[...$gpt, 'gen_ai_operation_name' => 'chat'], 4]]],
        [['gen_ai_client_token_usage_sum'], [[['gen_ai_token_type' => 'input'], 400]]],
    ]);

    $this->getJson(panelUrl(AiModelHeader::id(), ['model' => 'gpt-4o']))
        ->assertOk()
        ->assertJsonPath('stats.3.value', 'No price')
        ->assertJsonPath('stats.3.tone', 'dim')
        ->assertJsonPath('note', 'This model has no price in telemetry.instrument.ai_pricing.models, so its cost is unknown, not zero.');

    $this->getJson(panelUrl(AiModelTokens::id(), ['model' => 'gpt-4o']))
        ->assertOk()
        ->assertJsonPath('stats.2.value', 'No price');
});

it('summarises an agent from a sample of its runs, and lists them newest first', function (): void {
    $run = fn (string $id, int $at, float $ms, array $extra = []): array => tempoHit($id, 'invoke_agent SupportAgent', $at, $ms, [
        'gen_ai.agent.name' => 'SupportAgent',
        'gen_ai.request.model' => 'gpt-4o',
        'gen_ai.usage.input_tokens' => 1000,
        'gen_ai.usage.output_tokens' => 200,
        ...$extra,
    ]);

    aiTempo([
        $run('aaaa0000aaaa0000aaaa0000aaaa0001', time() - 600, 2000, ['gen_ai.usage.cost' => 0.01]),
        $run('aaaa0000aaaa0000aaaa0000aaaa0002', time() - 300, 4000, ['gen_ai.usage.cost' => 0.02, 'error.type' => 'ConnectionException']),
    ], [
        tempoHit('aaaa0000aaaa0000aaaa0000aaaa0002', 'execute_tool search_docs', time() - 299, 80, ['gen_ai.tool.name' => 'search_docs']),
    ]);

    $this->getJson(panelUrl(AiAgentHeader::id(), ['agent' => 'SupportAgent']))
        ->assertOk()
        ->assertJsonPath('title', 'SupportAgent')
        ->assertJsonPath('subtitle', 'Agent · gpt-4o')
        ->assertJsonPath('back', ['to' => 'page', 'page' => 'ai'])
        ->assertJsonPath('stats.0.value', '2')
        ->assertJsonPath('stats.1.value', '3s')
        ->assertJsonPath('stats.3.value', '2K / 400')
        ->assertJsonPath('stats.4.value', '$0.03')
        ->assertJsonPath('stats.5.value', '1')
        ->assertJsonPath('stats.5.tone', 'danger');

    $activity = $this->getJson(panelUrl(AiAgentActivity::id(), ['agent' => 'SupportAgent']))->assertOk()->json();
    expect(array_sum(array_column($activity['series'][0]['data'], 1)))->toEqual(2)
        ->and(array_sum(array_column($activity['series'][1]['data'], 1)))->toEqual(1);

    $this->getJson(panelUrl(AiAgentRunLog::id(), ['agent' => 'SupportAgent']))
        ->assertOk()
        ->assertJsonPath('title', 'Runs')
        ->assertJsonPath('columns.0.label', 'Started')
        ->assertJsonPath('rows.0.status.v', 'Connection')
        ->assertJsonPath('rows.0.tools.v', 'search_docs')
        ->assertJsonMissingPath('rows.0.when');

    $this->getJson(panelUrl(AiAgentTools::id(), ['agent' => 'SupportAgent']))
        ->assertOk()
        ->assertJsonPath('rows.0.tool.v', 'search_docs');

    // The agent's tools are the tool spans under its runs.
    expect(collect(sentTraceql())->contains(fn (string $q): bool => str_contains($q, 'span.gen_ai.agent.name = "SupportAgent"') && str_contains($q, '>>')))->toBeTrue();
});

it('drills a model and an agent into their entity pages with the detail panels attached', function (string $type, string $value, string $param, string $header): void {
    aiBackend([]);
    Http::fake(['tempo.test:3200/*' => Http::response(['traces' => []])]);

    $story = $this->getJson(apiUrl('entities/'.$type.'/story', ['value' => $value]))->assertOk()->json();

    expect($story['entity']['type'])->toBe($type)
        ->and($story['panels'][0]['id'])->toBe($header)
        ->and($story['panels'][0]['params'][$param])->toBe($value);
})->with([
    'model' => ['ai-model', 'gpt-4o', 'model', 'ai-model-header'],
    'agent' => ['ai-agent', 'SupportAgent', 'agent', 'ai-agent-header'],
]);

/**
 * One laravel-telemetry usage record (`gen_ai.client.inference.operation.details`)
 * as Loki returns it: the body is the event, attributes are labels.
 *
 * @param  array<string, string>  $labels
 * @return array{stream: array<string, string>, values: list<array{0: string, 1: string}>}
 */
function aiUsageRecord(int $at, array $labels): array
{
    return [
        'stream' => ['service_name' => 'shop', 'trace_id' => substr(md5((string) $at), 0, 32), 'span_id' => substr(md5('s'.$at), 0, 16), ...$labels],
        'values' => [[(string) ($at * 1_000_000_000), 'gen_ai.client.inference.operation.details']],
    ];
}

it('breaks AI usage down by user from the usage records, exactly, linking each user to their page', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([
            aiUsageRecord(1735689600, ['user_id' => '42', 'gen_ai_request_model' => 'gpt-4o', 'gen_ai_usage_input_tokens' => '1000', 'gen_ai_usage_output_tokens' => '200', 'gen_ai_usage_cost' => '0.004']),
            aiUsageRecord(1735689660, ['user_id' => '42', 'gen_ai_request_model' => 'gpt-4o', 'gen_ai_usage_input_tokens' => '3000', 'gen_ai_usage_output_tokens' => '100', 'gen_ai_usage_cost' => '0.01', 'error_type' => 'RateLimitExceededException']),
            aiUsageRecord(1735689720, ['user_id' => '7', 'gen_ai_request_model' => 'llama', 'gen_ai_usage_input_tokens' => '500', 'gen_ai_usage_output_tokens' => '50']),
        ])),
    ]);

    $this->getJson(panelUrl(AiUsageByDimension::id()))
        ->assertOk()
        ->assertJsonPath('title', 'AI usage by user')
        ->assertJsonPath('columns.0.label', 'User')
        ->assertJsonPath('rows.0.value.v', '42')
        ->assertJsonPath('rows.0.value.link', ['to' => 'entity', 'type' => 'user', 'value' => '42'])
        ->assertJsonPath('rows.0.value.dim', ['key' => 'user.id', 'value' => '42'])
        ->assertJsonPath('rows.0.calls.v', '2')
        ->assertJsonPath('rows.0.input.v', '4K')
        ->assertJsonPath('rows.0.output.v', '300')
        ->assertJsonPath('rows.0.cost.v', '$0.01')
        ->assertJsonPath('rows.0.errors.v', '50%')
        ->assertJsonPath('rows.0.errors.tone', 'danger')
        // Tokens and no cost series: unpriced, never free.
        ->assertJsonPath('rows.1.value.v', '7')
        ->assertJsonPath('rows.1.cost.v', 'no price')
        ->assertJsonPath('rows.1.cost.tone', 'dim')
        ->assertJsonPath('note', 'Exact: every model call in this period, from the usage records (instrument.ai_usage_events).');

    expect(sentLogql()[0])->toContain('gen_ai.client.inference.operation.details');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tempo.test'));
});

it('falls back to spans for usage by dimension when there are no usage records, and says it is a sample', function (): void {
    aiTempo([
        tempoHit('ffff1111ffff1111ffff1111ffff1111', 'chat gpt-4o', 1735689600, 900, [
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'gpt-4o',
            'gen_ai.usage.input_tokens' => 800,
            'gen_ai.usage.output_tokens' => 90,
            'gen_ai.usage.cost' => 0.002,
            'user.id' => '42',
        ]),
    ]);

    $response = $this->getJson(panelUrl(AiUsageByDimension::id()))
        ->assertOk()
        ->assertJsonPath('rows.0.value.v', '42')
        ->assertJsonPath('rows.0.cost.v', '$0.002');

    expect($response->json('note'))->toStartWith('From the most recent 100 traces with model calls')
        ->and(sentTraceql()[0])->toContain('span.user.id != nil')
        ->and(sentTraceql()[0])->toContain('select(');
});

it('offers declared context dimensions and a free-form attribute', function (): void {
    TelemetryUi::dimension('tenant.id', label: 'Tenant', group: 'Billing', entity: 'tenant');

    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([
            aiUsageRecord(1735689600, ['tenant_id' => 'acme', 'plan' => 'pro', 'gen_ai_usage_input_tokens' => '10']),
        ])),
    ]);

    $options = collect($this->getJson(panelUrl(AiUsageByDimension::id()))->json('controls.0.options'))->pluck('label', 'value')->all();

    expect($options)->toMatchArray(['user.id' => 'User', 'tenant.id' => 'Tenant', 'client.address' => 'Client IP', 'custom' => 'Custom attribute…']);

    $this->getJson(panelUrl(AiUsageByDimension::id(), ['ai_by' => 'tenant.id']))
        ->assertOk()
        ->assertJsonPath('title', 'AI usage by tenant')
        ->assertJsonPath('rows.0.value.link', ['to' => 'entity', 'type' => 'tenant', 'value' => 'acme']);

    $this->getJson(panelUrl(AiUsageByDimension::id(), ['ai_by' => 'custom', 'ai_by_attr' => 'plan']))
        ->assertOk()
        ->assertJsonPath('controls.1.param', 'ai_by_attr')
        ->assertJsonPath('rows.0.value.v', 'pro')
        ->assertJsonPath('rows.0.value.link', ['to' => 'explore', 'signal' => 'traces', 'where' => ['gen_ai.operation.name=~chat|embeddings', 'plan=pro']]);

    $this->getJson(panelUrl(AiUsageByDimension::id(), ['ai_by' => 'custom']))
        ->assertOk()
        ->assertJsonPath('error', 'Enter a span attribute, e.g. team.id or plan.');
});

it('reads usage by client IP from the request root span above each call', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([])),
        'tempo.test:3200/api/search*' => fn (Request $request) => Http::response(['traces' => str_contains((string) (requestQuery($request)['q'] ?? ''), '<<')
            ? [tempoHit('abab1111abab1111abab1111abab1111', 'GET /chat', 1735689600, 1200, ['client.address' => '203.0.113.9'])]
            : [tempoHit('abab1111abab1111abab1111abab1111', 'chat gpt-4o', 1735689600, 900, ['gen_ai.usage.input_tokens' => 100]),
                tempoHit('cdcd2222cdcd2222cdcd2222cdcd2222', 'chat gpt-4o', 1735689600, 900, ['gen_ai.usage.input_tokens' => 50])],
        ]),
    ]);

    $this->getJson(panelUrl(AiUsageByDimension::id(), ['ai_by' => 'client.address']))
        ->assertOk()
        ->assertJsonPath('columns.0.label', 'Client IP')
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.value.v', '203.0.113.9')
        ->assertJsonPath('rows.0.value.link', ['to' => 'entity', 'type' => 'ip', 'value' => '203.0.113.9'])
        ->assertJsonPath('rows.0.input.v', '100');

    // Never the usage records: they carry no client address.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'loki.test'));
    expect(collect(sentTraceql())->contains(fn (string $q): bool => str_contains($q, '<<') && str_contains($q, 'span.client.address != nil')))->toBeTrue();
});

it('summarises one user\'s AI usage from their usage records on their page', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response(lokiStreams([
            aiUsageRecord(time() - 120, ['user_id' => '42', 'gen_ai_usage_input_tokens' => '1000', 'gen_ai_usage_output_tokens' => '200', 'gen_ai_usage_cost' => '0.25']),
            aiUsageRecord(time() - 60, ['user_id' => '42', 'gen_ai_usage_input_tokens' => '500', 'gen_ai_usage_output_tokens' => '100']),
        ])),
    ]);

    $data = $this->getJson(panelUrl(AiUsageSummary::id(), ['_dim' => 'user.id', 'ai_value' => '42']))
        ->assertOk()
        ->assertJsonPath('title', 'AI usage')
        ->assertJsonPath('stats.0.value', '2')
        ->assertJsonPath('stats.1.value', '1.5K')
        ->assertJsonPath('stats.2.value', '300')
        ->assertJsonPath('stats.3.value', '$0.25')
        ->json();

    expect($data['note'])->toStartWith('Exact:')->toContain('1 calls had no price')
        ->and(array_sum(array_column($data['series'][0]['data'], 1)))->toEqual(2)
        ->and(sentLogql()[0])->toContain('user_id="42"');
});

it('lists one user\'s model calls from their spans, and an IP\'s through its request root', function (): void {
    aiTempo([]);

    $this->getJson(panelUrl(AiUsageCalls::id(), ['_dim' => 'user.id', 'ai_value' => '42']))
        ->assertOk()
        ->assertJsonPath('title', 'Model calls')
        ->assertJsonPath('empty', 'No model calls traced for user.id = 42 in this period.');

    $this->getJson(panelUrl(AiUsageCalls::id(), ['_dim' => 'client.address', 'ai_value' => '203.0.113.9']))->assertOk();

    $sent = sentTraceql();
    expect($sent[0])->toContain('span.user.id = "42"')
        ->and($sent[1])->toContain('span.client.address = "203.0.113.9" } >> {');
});

it('attaches AI usage to user, IP and declared dimension pages only when the app emits GenAI metrics', function (bool $emits): void {
    TelemetryUi::dimension('tenant.id', label: 'Tenant', entity: 'tenant');

    Http::fake([
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => $emits ? ['gen_ai_client_token_usage_sum'] : []]),
        'prometheus.test:9090/*' => Http::response(labelledVector([])),
        'tempo.test:3200/*' => Http::response(['traces' => []]),
        'loki.test:3100/*' => Http::response(lokiStreams([])),
    ]);

    foreach (['user' => '42', 'ip' => '203.0.113.9', 'tenant' => 'acme'] as $type => $value) {
        $panels = $this->getJson(apiUrl('entities/'.$type.'/story', ['value' => $value]))->assertOk()->json('panels');

        if ($emits) {
            expect(array_column($panels, 'id'))->toBe(['ai-usage-summary', 'ai-usage-calls'])
                ->and($panels[0]['params']['ai_value'])->toBe($value);
        } else {
            expect($panels)->toBe([]);
        }
    }

    // An entity with its own detail page keeps it.
    $route = $this->getJson(apiUrl('entities/route/story', ['value' => '/orders']))->assertOk()->json('panels');
    expect($route[0]['id'])->toBe('request-detail-header');
})->with(['emits' => true, 'does not emit' => false]);
