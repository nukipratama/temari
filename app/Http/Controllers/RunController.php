<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Services\Run\Story\PastYouMatcher;
use App\Services\Run\Story\Temari;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $runs = Activity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->with(['detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards'])])
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('Runs/Index', ['runs' => $runs]);
    }

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher, Temari $temari): Response
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

        $variations = [];
        if ($storyLine !== null) {
            $hasPr = PersonalRecord::query()->where('activity_id', $activity->id)->exists();
            $variations = $temari->variationsForActivity($detail, $hasPr, $storyLine->mood);
        }

        $pastYou = $matcher->findMatch($activity, $detail);

        return Inertia::render('Runs/Show', [
            'activity' => $activity,
            'detail' => $detail,
            'card' => $activity->runCard,
            'storyLine' => $storyLine,
            'storyVariations' => $variations,
            'pastYou' => $pastYou,
        ]);
    }
}
