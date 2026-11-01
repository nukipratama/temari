<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use App\Services\Run\Plan\RecommendationHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

class RecommendationViewController extends Controller
{
    public function __invoke(Request $request, RecommendationHistory $history): Response
    {
        $validated = $request->validate(['observation_id' => ['required', 'uuid'], 'token' => ['required', 'string', 'max:30000']]);
        try {
            $advice = $history->advice($validated['token']);
        } catch (DecryptException|JsonException) {
            abort(422, 'Invalid recommendation.');
        }
        /** @var User $user */
        $user = $request->user();
        abort_unless($advice['user_id'] === $user->id, 403);
        $revision = $history->record($user->id, $advice['date'], $advice['original'], $advice['effective'], $advice['policy_version']);
        $history->shown($revision, $validated['observation_id']);

        return response()->noContent();
    }
}
