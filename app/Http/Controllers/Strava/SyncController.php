<?php

declare(strict_types=1);

namespace App\Http\Controllers\Strava;

use App\Http\Controllers\Controller;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * User-initiated "Sync now" pull. Queues a full-sync for the signed-in athlete;
 * the orchestrator's per-user lock makes a double-tap harmless, and the walk
 * stops at the first already-known activity so a re-pull is cheap.
 */
class SyncController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        SyncActivitiesJob::dispatch($user->id);

        return back()->with('success', 'Lagi narik lari terbaru dari Strava ya, sebentar.');
    }
}
