---
title: Issue trackers (GitHub / Sentry / Linear)
description: Surface open issues next to your telemetry via an IssuesSource connection
weight: 3
---

# Issue trackers

Issue trackers are a fourth signal alongside metrics, traces and logs — the
same connection + driver pattern, behind an `IssuesSource` contract. Configure
one and an **Issues** page appears in the sidebar, listing open issues/PRs next
to the telemetry so a spike and its ticket live together.

Set the driver on the `issues` connection (nothing shows until you do):

## GitHub

```dotenv
TELEMETRY_UI_ISSUES_DRIVER=github
TELEMETRY_UI_GITHUB_REPO=cboxdk/laravel-telemetry-ui
TELEMETRY_UI_ISSUES_TOKEN=ghp_…        # repo scope; a fine-grained read-only token is enough
```

Lists issues **and** pull requests for the repo (PRs are badged separately).

## Sentry

```php
'issues' => [
    'driver' => 'sentry',
    'org' => 'cbox',
    'project' => 'web',
    'url' => 'https://sentry.io',        // or your self-hosted URL
    'token' => env('TELEMETRY_UI_ISSUES_TOKEN'),   // auth token, project:read
],
```

The closest fit to the built-in Exceptions view — issue groups with event
counts and first/last seen.

## Linear

```php
'issues' => [
    'driver' => 'linear',
    'token' => env('TELEMETRY_UI_ISSUES_TOKEN'),   // Linear API key (sent as-is, not Bearer)
    'team' => 'CBOX',                               // optional team key filter
],
```

Queried over Linear's GraphQL API.

## Adding your own tracker

Implement `Cbox\TelemetryUi\Contracts\IssuesSource` (list issues with a
state/search filter, plus `label()` and `url()`), then teach the manager the
driver:

```php
$this->callAfterResolving(ConnectionManager::class, function ($manager) {
    $manager->extend('jira', fn (array $config) => new JiraSource(...));
});
```

Config `driver => 'jira'` and the Issues page uses it. The action side —
creating a ticket from an exception, posting to Slack — is a deliberate future
layer on top of this read side.

## Creating tickets from exceptions

Trackers that can write (GitHub, Linear — not Sentry) implement the optional
`Cbox\TelemetryUi\Contracts\CreatesIssues` capability. When one is configured,
the Exceptions table shows a **+ ticket** action per class: it opens a compose
form in the drawer, prefilled with the analysis (exception class, occurrence
count, period, scope and a deep-link to the error traces). Edit and submit, and
the drawer lands on the freshly created ticket — never leaving the dashboard.

- **GitHub** needs a token with issue write (repo scope, or a fine-grained
  token with Issues: read & write).
- **Linear** needs the team's UUID to create:

  ```php
  'issues' => [
      'driver' => 'linear',
      'token' => env('TELEMETRY_UI_ISSUES_TOKEN'),
      'team' => 'CBOX',                              // team key, for filtering
      'team_id' => env('TELEMETRY_UI_LINEAR_TEAM_ID'),   // team UUID, for creating
  ],
  ```

## Relations

Issues aren't a dead end — they cross-link with the telemetry:

- **Issue → trace.** Clicking an issue opens it in the slide-in drawer (title,
  labels, author, full body) without leaving the list. Any 32-hex trace id in
  the title/body becomes a link that opens the trace waterfall in the same
  drawer.
- **Exception → issue.** The Exceptions table shows a "⧉ issues" link per
  class (when a tracker is configured) that jumps to the Issues page
  pre-searched for that exception, so a spike lands on its ticket.
- **Labels/tags.** Click a label on any issue to filter the list by it, or use
  the label dropdown; the filter is URL-backed so the view is shareable.
