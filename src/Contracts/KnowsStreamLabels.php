<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\StreamLabels;

/**
 * A {@see LogsSource} that can say which labels it indexes as stream labels.
 *
 * The environment is scoped as a pipeline filter by default, because some
 * stores (otel-lgtm) keep it as structured metadata, where a stream matcher
 * on it matches nothing. A pipeline filter is right in both cases but costs
 * a read of every stream in the window: on a store that does index the
 * environment, that is every other environment's logs, and every machine's
 * journal, read only to be dropped. A driver that declares this capability
 * lets the scope put the environment in the stream selector whenever the
 * store indexes it. {@see StreamLabels} feature-detects it and caches the
 * answer; drivers without it keep the pipeline filter.
 *
 * @api Implement alongside LogsSource.
 */
interface KnowsStreamLabels
{
    /**
     * The names of the labels the store indexes as stream labels (not
     * structured metadata, not parsed fields).
     *
     * @return list<string>
     *
     * @throws SourceException when the store cannot answer
     */
    public function streamLabels(): array;
}
