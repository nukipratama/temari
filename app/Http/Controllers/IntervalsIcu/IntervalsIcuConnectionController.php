<?php

declare(strict_types=1);

namespace App\Http\Controllers\IntervalsIcu;

use App\Enums\IntervalsIcuVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntervalsIcuConnectionRequest;
use App\Jobs\IntervalsIcu\SyncIntervalsIcuWorkoutJob;
use App\Models\User;
use App\Services\IntervalsIcu\IntervalsIcuClient;
use App\Services\IntervalsIcu\IntervalsIcuSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class IntervalsIcuConnectionController extends Controller
{
    public function store(StoreIntervalsIcuConnectionRequest $request, IntervalsIcuClient $client, IntervalsIcuSync $sync): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{athlete_id: string, api_key: string} $validated */
        $validated = $request->validated();

        $verification = $client->verify($validated['athlete_id'], $validated['api_key']);

        if ($verification === IntervalsIcuVerification::Rejected) {
            throw ValidationException::withMessages([
                'api_key' => 'intervals.icu didn\'t accept that ID and key. check both and try again.',
            ]);
        }

        if ($verification === IntervalsIcuVerification::Unreachable) {
            throw ValidationException::withMessages([
                'api_key' => 'couldn\'t reach intervals.icu just now. try again in a minute.',
            ]);
        }

        $existing = $user->intervalsIcuConnection;
        $sameAthlete = $existing?->athlete_id === $validated['athlete_id'];
        if ($existing !== null && ! $sameAthlete) {
            $sync->removeTodayBestEffort($existing, Carbon::today());
        }

        $user->intervalsIcuConnection()->updateOrCreate([], [
            'athlete_id' => $validated['athlete_id'],
            'api_key' => $validated['api_key'],
            'verified_at' => Carbon::now(),
            'broken_at' => null,
            ...($sameAthlete ? [] : ['synced_on' => null, 'sync_status' => null, 'event_id' => null, 'payload_hash' => null]),
        ]);

        SyncIntervalsIcuWorkoutJob::dispatch($user->id);

        return back();
    }

    public function destroy(Request $request, IntervalsIcuSync $sync): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $connection = $user->intervalsIcuConnection;
        if ($connection === null) {
            return back();
        }

        $sync->removeTodayBestEffort($connection, Carbon::today());
        $connection->delete();

        return back();
    }
}
