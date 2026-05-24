<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardSeenController extends Controller
{
    /**
     * Mark the user's pending reveal card as seen. Idempotent: posting
     * for any card other than the one currently flagged is a no-op so
     * stale clients can't accidentally clear newer reveals.
     */
    public function __invoke(Request $request, RunCard $card): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The card must belong to the user.
        $card->loadMissing('activity');
        if ($card->activity->user_id !== $user->id) {
            abort(403);
        }

        if ($user->pending_reveal_card_id === $card->id) {
            $user->forceFill(['pending_reveal_card_id' => null])->save();
        }

        return response()->json(['seen' => true]);
    }
}
