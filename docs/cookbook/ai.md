---
title: AI usage and cost
description: The AI page — model calls, tokens, cost, latency, errors and agent runs from laravel/ai
weight: 32
---

# AI usage and cost

`cboxdk/laravel-telemetry` instruments [laravel/ai](https://github.com/laravel/ai)
with the OpenTelemetry GenAI conventions. When those metrics reach your
Prometheus, the **AI** page appears under *Activity*. Until then it stays out
of the navigation: it is detected on `gen_ai_.*`.

![The AI page: what laravel/ai calls cost per model, tokens, latency and errors, with an unpriced model marked as such rather than as free.](../screenshots/ai.png)

## Turn it on (in the emitter)

The instrumentation is on by default in the emitting app
(`TELEMETRY_INSTRUMENT_AI=true`). To see cost as well as tokens, give it your
model prices, per 1M tokens:

```php
// config/telemetry.php
'instrument' => [
    'ai_pricing' => [
        'currency' => env('TELEMETRY_AI_CURRENCY', 'USD'),
        'models' => [
            'anthropic/claude-sonnet-*' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75],
            'openai/text-embedding-3-small' => ['input' => 0.02],
        ],
    ],
],
```

No price catalog ships with either package. A model without a price gets no
cost series, so the dashboard shows it as **no price**, never as free.

The cost counter (`gen_ai_client_cost_total`) needs a laravel-telemetry
release that includes it. Older emitters still fill every other panel on the
page, and the cost columns show the pricing hint.

## The panels

| Panel | Reads | Shows |
| --- | --- | --- |
| AI usage | `gen_ai_client_cost_total`, `gen_ai_client_token_usage_sum`, `gen_ai_client_operation_duration_seconds_count` | Cost per model per hour when prices are set, tokens per model per minute otherwise. Tiles: cost, input and output tokens, model calls, error rate. |
| Models | the same, plus `gen_ai_client_operation_duration_seconds_bucket` | One row per provider and model: calls (with chat / embeddings), input and output tokens, cost or *no price*, p95 latency, error rate. A row opens the model's traces. |
| Chat latency | `gen_ai_client_operation_duration_seconds_bucket{gen_ai_operation_name="chat"}` | p50 and p95 per provider over time. Embeddings are left out: they would flatten the chat lines. |
| AI errors | `gen_ai_client_operation_duration_seconds_count{error_type!=""}` | Failed calls by provider and `error_type`, each with its share of that provider's calls. |
| Recent model calls | Tempo: `chat` and `embeddings` spans | One row per call: model and where it came from, tokens (with reasoning), cache read / write, cost, finish reason or error, duration. Sortable by time, duration or cost. A row opens the trace. |
| Slowest agent runs | Tempo: `invoke_agent` spans | Agent, model, tokens, cost (`gen_ai.usage.cost`), the tools the run called, finish reason or error, and duration. Sortable by time, duration or cost. A row opens the waterfall with its `chat` and `execute_tool` children. |
| Agent tools | Tempo: `execute_tool` spans | Runs, average and max duration per tool. A row opens the slowest run. |

The trace panels are built from a sample of recent traces (a Tempo
search), not totals. Without a reachable traces backend they show the
backend's error, as every trace panel does; the metric panels are unaffected.

## Model and agent pages

A model and an agent are entities, like a route or an outgoing host: click a
row in *Models*, or a model or agent name in a list, and its page opens
(`/entity/ai-model?value=gpt-4o`, `/entity/ai-agent?value=SupportAgent`).
Both appear under *Entities* in Explore as **AI models** and **Agents**.

The *Story* tab is the usual entity story over the spans carrying
`gen_ai.request.model` or `gen_ai.agent.name`. The *Metrics* tab holds the
detail panels:

| Page | Panels |
| --- | --- |
| AI model (`ai-model-detail`, `?model=`) | Header (calls, tokens, cost or *No price*, p95, error rate), *Calls* per minute with failures, *Tokens* per minute with cost, *Latency* p50 / p95 per provider, and the model's *Calls* list from Tempo. |
| Agent (`ai-agent-detail`, `?agent=`) | Header and *Runs* over time (runs, failures, average duration, cost), the tools its runs called, and its run log, newest first. |

Agents have no metric label, so every agent number comes from a sample of
its most recent runs (one Tempo search), and each panel says so.

The *Tools* column in run lists comes from one structural search
(`{run} >> {execute_tool}`). Tempo returns a few matched spans per trace, so a
run that called many tools lists the first few; the waterfall shows all of
them.

## Currency

The cost counter carries no currency label. The dashboard reads
`telemetry.instrument.ai_pricing.currency` from its own config (USD when
unset), which is right when it runs inside the app it watches. A dashboard
watching a different app should set the same `TELEMETRY_AI_CURRENCY`.
