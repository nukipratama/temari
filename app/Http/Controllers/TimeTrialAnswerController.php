<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TimeTrialOutcome;
use App\Http\Requests\AnswerTimeTrialRequest;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\TimeTrialService;
use Illuminate\Http\RedirectResponse;

class TimeTrialAnswerController extends Controller
{
    public function __invoke(AnswerTimeTrialRequest $request, int $plannedSession, TimeTrialService $trials): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = PlannedSession::query()->where('user_id', $user->id)->findOrFail($plannedSession);

        $outcome = $trials->answer($user, $session, $request->boolean('all_out'));

        return back()->with('success', $outcome === TimeTrialOutcome::Confirmed
            ? 'Counted. Your paces now follow this trial.'
            : 'Noted. This run does not count as a trial.');
    }
}
