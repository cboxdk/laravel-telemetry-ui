<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Open issues/PRs from the configured tracker (GitHub, Sentry, Linear),
 * surfaced next to the telemetry so a spike and its ticket live together.
 */
final class IssuesList extends Card
{
    #[Url(as: 'issue_state')]
    public string $state = 'open';

    #[Url(as: 'issue_search')]
    public string $search = '';

    public function render(): View
    {
        $issues = [];
        $error = null;
        $label = '';
        $url = '';

        if (! app(ConnectionManager::class)->hasIssues()) {
            $error = 'No issue tracker configured. Set TELEMETRY_UI_ISSUES_DRIVER (github, …) and its token.';
        } else {
            try {
                $source = $this->issues();
                $label = $source->label();
                $url = $source->url();
                $state = in_array($this->state, ['open', 'closed', 'all'], true) ? $this->state : 'open';
                $issues = $source->issues($state, $this->search !== '' ? $this->search : null, limit: 50);
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.issues-list';

        return view($view, [
            'issues' => $issues,
            'error' => $error,
            'label' => $label,
            'url' => $url,
        ]);
    }
}
