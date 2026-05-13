<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Contracts;

use App\Services\Run\Story\VerdictTimelineItem;
use App\Models\User;

interface VerdictNarrator
{
    /**
     * @return list<VerdictTimelineItem>
     */
    public function recent(User $user, int $limit = 8): array;
}
