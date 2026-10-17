<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;

/**
 * Scopes a card to one model (the `?model=` on the ai-model-detail page):
 * the `gen_ai_request_model` label on the GenAI metrics, the
 * `gen_ai.request.model` attribute on spans.
 */
trait ScopesToAiModel
{
    #[Param('model')]
    public string $model = '';

    protected function scopeMatchers(): string
    {
        return $this->model === '' ? '' : 'gen_ai_request_model="'.addcslashes($this->model, '"\\').'"';
    }

    protected function scopedModel(): string
    {
        return $this->model;
    }
}
