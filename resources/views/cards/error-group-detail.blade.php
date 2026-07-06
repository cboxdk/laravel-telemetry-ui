<x-telemetry-ui::card title="Latest occurrence" subtitle="The newest event's full detail — request, root cause, source context and stacktrace" span="2">
    @include('telemetry-ui::partials.exception-detail', [
        'error' => $error,
        'group' => $group,
        'stats' => $stats,
        'occurrences' => $occurrences,
        'detail' => $detail,
        'request' => $request,
        'suspect' => $suspect,
        'releases' => $releases,
        'canCreate' => $canCreate,
        'draft' => $draft,
        'lookbackDays' => $lookbackDays,
    ])
</x-telemetry-ui::card>
