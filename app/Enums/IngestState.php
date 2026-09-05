<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How complete an {@see \App\Models\Activity}'s ingest is.
 *
 * `Summary` rows carry only what `/athlete/activities` returns (distance,
 * moving time, average speed, elevation, average HR). `Detailed` rows have been
 * through the full pipeline: detail endpoint, streams, stream summary, TRIMP,
 * weather and the story layer.
 */
enum IngestState: string
{
    case Summary = 'summary';
    case Detailed = 'detailed';
}
