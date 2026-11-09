<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Contracts;

use App\Services\Run\Story\VerdictTimelineItem;
use App\Models\User;
use Illuminate\Support\Carbon;

interface VerdictNarrator
{
    /**
     * @return list<VerdictTimelineItem>
     */
    public function recent(User $user, int $limit = 8): array;

    /**
     * @return list<VerdictTimelineItem>
     */
    public function between(User $user, Carbon $from, Carbon $to): array;
}
