<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;

/**
 * Records a runner flagging a plan day or a narration as wrong. There is no
 * admin surface: the rows are read straight out of the DB.
 */
class FeedbackController extends Controller
{
    public function __invoke(StoreFeedbackRequest $request): RedirectResponse
    {
        Feedback::query()->create([
            'user_id' => $request->user()?->id,
            'subject_type' => $request->subject(),
            'subject_id' => (int) $request->validated('subject_id'),
            'note' => $request->note(),
        ]);

        return back();
    }
}
