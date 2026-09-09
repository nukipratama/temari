<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;

/**
 * Records a runner flagging a plan day or a narration as wrong. There is no
 * admin surface: the rows are read straight out of the DB.
 *
 * One row per subject per runner: the control goes inert once a subject is
 * flagged, so a second POST is a stale tab or a double tap rather than a second
 * opinion, and it lands on the row already there.
 */
class FeedbackController extends Controller
{
    public function __invoke(StoreFeedbackRequest $request): RedirectResponse
    {
        Feedback::query()->firstOrCreate([
            'user_id' => $request->user()?->id,
            'subject_type' => $request->subject(),
            'subject_id' => (int) $request->validated('subject_id'),
        ], [
            'reason' => $request->reason(),
            'note' => $request->note(),
        ]);

        return back();
    }
}
