<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Base for reads about a runner as of a moment, rather than about one run.
 *
 * The per-activity narrators use these too, passing the run's own date as the
 * as-of: "the runner's training load on the day of this run" is the same
 * question as "the runner's training load today", asked from a different day.
 */
abstract class UserTool extends NoArgumentTool
{
    public function __construct(
        protected readonly User $user,
        protected readonly Carbon $asOf,
    ) {
    }
}
