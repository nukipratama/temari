<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PersonalRecord;
use App\Models\WeeklySnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProgressController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $snapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(26)
            ->get();

        // FE only renders `activity.detail.name` — skip the heavy stream_summary
        // / splits JSON blobs by scoping the eager-load to the columns we use.
        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->with(['activity:id', 'activity.detail:id,activity_id,name'])
            ->get();

        return Inertia::render('Progress', [
            'snapshots' => $snapshots,
            'personalRecords' => $personalRecords,
        ]);
    }
}
