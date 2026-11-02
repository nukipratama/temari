<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\QualityOverrideService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "i'm ready" and "ease it again" on a planned day, through {@see QualityOverrideService}.
 */
class PlanOverrideController extends Controller
{
    public function store(Request $request, int $plannedSession, QualityOverrideService $overrides): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = PlannedSession::query()->where('user_id', $user->id)->findOrFail($plannedSession);

        try {
            $overrides->take($user, $session, Carbon::today());
        } catch (LockTimeoutException) {
            return back()->with('info', 'The plan is updating right now. Reload and try your edit again.');
        }

        return back();
    }

    public function destroy(Request $request, int $plannedSession, QualityOverrideService $overrides): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = PlannedSession::query()->where('user_id', $user->id)->findOrFail($plannedSession);

        try {
            $overrides->undo($user, $session, Carbon::today());
        } catch (LockTimeoutException) {
            return back()->with('info', 'The plan is updating right now. Reload and try your edit again.');
        }

        return back();
    }
}
