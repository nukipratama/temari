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
 * One row per subject per runner: a second POST lands on the row already there,
 * and a flag the narrator has since superseded is rewritten in place.
 */
class FeedbackController extends Controller
{
    public function __invoke(StoreFeedbackRequest $request): RedirectResponse
    {
        $flag = Feedback::query()->firstOrNew([
            'user_id' => $request->user()?->id,
            'subject_type' => $request->subject(),
            'subject_id' => $request->safe()->integer('subject_id'),
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
