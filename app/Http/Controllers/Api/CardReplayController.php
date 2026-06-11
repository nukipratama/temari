<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardReplayController extends Controller
{
    /**
     * Re-arm the reveal for an owned card so the user can re-watch it.
     */
    public function __invoke(Request $request, RunCard $card): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $card->loadMissing('activity');
        if ($card->activity->user_id !== $user->id) {
            abort(403);
        }

        $user->forceFill(['pending_reveal_card_id' => $card->id])->save();

        return response()->json(['replay' => true]);
    }
}
