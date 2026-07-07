<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A stage in a {@see LogQuery} pipeline (a line filter or a label filter),
 * appended after the stream selector. Marker interface for the union a
 * compiler must handle.
 */
interface LogStage {}
