<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;

/**
 * Scopes a card to one laravel/ai agent (the `?agent=` on the
 * ai-agent-detail page). Agents exist only on spans (`gen_ai.agent.name` on
 * `invoke_agent`), so this narrows trace searches and leaves metrics alone.
 */
trait ScopesToAiAgent
{
    #[Param('agent')]
    public string $agent = '';

    protected function scopedAgent(): string
    {
        return $this->agent;
    }
}
