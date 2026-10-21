<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels;

/**
 * A panel whose rows are individual things that happened — a request, a job
 * run, a mail, a log line — each one openable, rather than counts of them.
 *
 * Every page carries at least one (enforced by a test): an aggregate says
 * something is wrong, and only a row says which one, when, and for whom.
 */
interface ListsRows {}
