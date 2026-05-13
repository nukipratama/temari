<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Contracts;

use App\Models\User;
use App\Services\Run\Story\BriefingResult;
use Illuminate\Support\Carbon;

interface BriefingNarrator
{
    public function generate(User $user, ?Carbon $asOf = null): BriefingResult;
}
