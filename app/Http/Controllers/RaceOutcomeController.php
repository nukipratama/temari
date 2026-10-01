<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RaceOutcome;
use App\Http\Requests\StoreRaceOutcomeRequest;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\RaceOutcomeService;
use Illuminate\Http\RedirectResponse;

class RaceOutcomeController extends Controller
{
    public function store(StoreRaceOutcomeRequest $request, int $race, RaceOutcomeService $outcomes): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $raceGoal = RaceGoal::query()->where('user_id', $user->id)->findOrFail($race);

        $outcome = $request->enum('outcome', RaceOutcome::class) ?? RaceOutcome::Pending;
        $outcomes->record(
            $user,
            $raceGoal,
            $outcome,
            $request->integer('activity_id') ?: null,
            $request->integer('finish_time_sec') ?: null,
        );

        return back()->with('success', match ($outcome) {
            RaceOutcome::Confirmed => 'Your result is saved.',
            RaceOutcome::DidNotRun => 'Noted. No result is recorded for this race.',
            RaceOutcome::Cancelled => 'Noted. This race is marked as called off.',
            RaceOutcome::Pending => 'This race is open again.',
        });
    }
}
