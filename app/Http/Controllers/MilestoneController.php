<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clears the cached milestone payload on an activity so the dashboard
 * banner stops surfacing it after the user taps "tutup". The detector's
 * `milestones_detected_at` timestamp stays set so re-running the pipeline
 * won't re-detect them.
 */
class MilestoneController extends Controller
{
    public function dismiss(Request $request, Activity $activity): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($activity->user_id === $user->id, 404);

        $activity->update(['milestone_payload' => null]);

        return response()->json(['ok' => true]);
    }
}
