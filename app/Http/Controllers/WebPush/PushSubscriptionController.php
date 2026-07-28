<?php

declare(strict_types=1);

namespace App\Http\Controllers\WebPush;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stores / removes the signed-in user's browser push subscription. The
 * subscription is always tied to `$request->user()` (never a request-supplied
 * id), so a user can only manage their own devices. Called by fetch from the
 * PWA, so it answers 204 rather than an Inertia redirect. SSRF validation of the
 * endpoint lives in {@see StorePushSubscriptionRequest}.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->updatePushSubscription(
            (string) $request->input('endpoint'),
            (string) $request->input('keys.p256dh'),
            (string) $request->input('keys.auth'),
        );

        SharedPropCacheKey::WebPushSubscribed->forget($user->id);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        SharedPropCacheKey::WebPushSubscribed->forget($user->id);

        return response()->noContent();
    }
}
