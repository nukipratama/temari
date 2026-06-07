<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Advances the user's "last seen PR ledger" marker so the dashboard's
 * new-PR celebration stops re-firing. The dashboard GET only DETECTS a
 * fresh PR (read-only); this explicit POST, fired when the celebration
 * UI is dismissed, is the one that moves the marker forward.
 */
class PrLedgerController extends Controller
{
    public function seen(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $latest = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->orderByDesc('set_at')
            ->value('set_at');

        if ($latest !== null) {
            $user->forceFill(['last_seen_pr_ledger_at' => Carbon::parse($latest)])->save();
        }

        return response()->json(['ok' => true]);
    }
}
