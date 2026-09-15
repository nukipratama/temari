<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Records a runner flagging a plan day or a narration as wrong. There is no
 * admin surface: the rows are read straight out of the DB.
 *
 * One row per subject per runner: the control is gone once a subject is
 * flagged, so a second POST is a stale tab or a double tap rather than a second
 * opinion, and it lands on the row already there. The exception is a flag the
 * narrator has since superseded, which the control comes back for: that row is
 * rewritten in place, the unique index leaving no room for a second one.
 */
class FeedbackController extends Controller
{
    public function __invoke(StoreFeedbackRequest $request): RedirectResponse
    {
        $flag = Feedback::query()->firstOrNew([
            'user_id' => $request->user()?->id,
            'subject_type' => $request->subject(),
            'subject_id' => (int) $request->validated('subject_id'),
        ]);

        if ($flag->exists && $flag->superseded_at === null) {
            return back();
        }

        $flag->fill([
            'reason' => $request->reason(),
            'note' => $request->note(),
            'superseded_at' => null,
        ]);
        $flag->created_at = Carbon::now();
        $flag->save();

        return back();
    }
}
