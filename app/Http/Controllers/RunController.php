<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Activity;
use App\Models\StoryLine;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RunController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        // Project only the columns the list row renders — skipping the heavy
        // stream_summary / splits_metric JSON columns shaves significant
        // bytes per page when paginating.
        $runs = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->with(['detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards'])])
            ->orderByDesc('id')
            ->paginate(20);

        return view('runs.index', ['runs' => $runs]);
    }

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($activity->user_id === $user->id, 404);

        $activity->loadMissing(['detail', 'runCard']);

        $detail = $activity->detail;
        abort_if($detail === null, 404, 'Activity not yet analyzed.');

        $storyLine = StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();

        $pastYou = $matcher->findMatch($activity, $detail);

        return view('runs.show', [
            'activity' => $activity,
            'detail' => $detail,
            'card' => $activity->runCard,
            'storyLine' => $storyLine,
            'pastYou' => $pastYou,
        ]);
    }
}
