<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    /**
     * An activity is only ever visible to, and actionable by, the athlete who
     * owns it. Callers gate with `abort_unless($user->can('view', $activity), 404)`
     * so a non-owner gets a 404 (not 403) and cannot confirm the activity exists.
     */
    public function view(User $user, Activity $activity): bool
    {
        return $activity->user_id === $user->id;
    }
}
