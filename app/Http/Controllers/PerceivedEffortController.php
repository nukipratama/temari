<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PerceivedEffortController extends Controller
{
    public function update(Request $request, Activity $activity): RedirectResponse
    {
        $detail = $this->ownRun($request, $activity);
        $validated = $request->validate([
            'score' => ['required', 'integer', 'between:1,10'],
        ]);

        $detail->update(['perceived_effort' => (int) $validated['score'], 'perceived_effort_at' => now()]);

        return back();
    }

    public function destroy(Request $request, Activity $activity): RedirectResponse
    {
        $this->ownRun($request, $activity)->update(['perceived_effort' => null, 'perceived_effort_at' => null]);

        return back();
    }

    private function ownRun(Request $request, Activity $activity): ActivityDetail
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        $detail = $activity->detail;
        abort_if($detail === null, 404);

        return $detail;
    }
}
