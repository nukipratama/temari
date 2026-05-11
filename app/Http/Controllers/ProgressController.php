<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PersonalRecord;
use App\Models\WeeklySnapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgressController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $snapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(26)
            ->get();

        $personalRecords = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderBy('category')
            ->with('activity.detail')
            ->get();

        return view('progress.index', [
            'snapshots' => $snapshots,
            'personalRecords' => $personalRecords,
        ]);
    }
}
