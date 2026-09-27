<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecordStampRequest;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RecordStamp;
use App\Models\User;
use App\Services\Run\Metrics\PrBibResolver;
use Illuminate\Http\Response;

/**
 * Claims the PR bib's one-time animation for a record the authenticated user
 * actually holds. Called by the frontend right after it plays the punch-in,
 * never from the run-detail render itself (see {@see PrBibResolver}).
 * Idempotent: `firstOrCreate` means a replay of the same record_key, from
 * this device or another, is a no-op.
 */
class RecordStampController extends Controller
{
    public function __invoke(StoreRecordStampRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $recordKey = $request->recordKey();

        abort_unless($this->userHolds($user, $recordKey), 422);

        RecordStamp::query()->firstOrCreate(
            ['user_id' => $user->id, 'record_key' => $recordKey],
            ['seen_at' => now()],
        );

        return response()->noContent();
    }

    private function userHolds(User $user, string $recordKey): bool
    {
        if ($recordKey === PrBibResolver::LONGEST_RUN_KEY) {
            return Activity::query()->where('user_id', $user->id)->whereHas('detail')->exists();
        }

        return PersonalRecord::query()->forUser($user->id)->where('category', $recordKey)->exists();
    }
}
