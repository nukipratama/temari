<?php

declare(strict_types=1);

namespace App\Http\Controllers\Strava;

use App\Http\Controllers\Controller;
use App\Jobs\Strava\ResyncActivityJob;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * User-initiated "Resync from Strava" on a single run's detail page. Queues a
 * re-pull of that activity; the throttled route + the job's rate-limit handling
 * keep a double-tap from hammering Strava.
 */
class ResyncActivityController extends Controller
{
    public function __invoke(Request $request, Activity $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        // A manual resync re-narrates the latest run; the webhook path does not.
        ResyncActivityJob::dispatch($activity->id, renarrate: true);

        return back()->with('success', 'Lagi narik ulang lari ini dari Strava ya, sebentar.');
    }
}
