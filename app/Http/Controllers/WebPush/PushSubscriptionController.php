<?php

declare(strict_types=1);

namespace App\Http\Controllers\WebPush;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyPushSubscriptionRequest;
use App\Http\Requests\SeenPushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $save = fn () => $user->updatePushSubscription(
            (string) $request->input('endpoint'),
            (string) $request->input('keys.p256dh'),
            (string) $request->input('keys.auth'),
        );

        try {
            $subscription = $save();
        } catch (UniqueConstraintViolationException) {
            // A simultaneous request for the same device inserted the row first; this pass updates it.
            $subscription = $save();
        }
        $subscription->forceFill(['last_seen_at' => now()])->save();

        $previousEndpoint = $request->previousEndpoint();
        if ($previousEndpoint !== null) {
            $user->deletePushSubscription($previousEndpoint);
        }

        return response()->noContent();
    }

    public function destroy(DestroyPushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($request->endpoint());

        return response()->noContent();
    }

    /**
     * The installed app reports its endpoint at most once a day; a 404 tells it
     * the server no longer has this device, so it saves the subscription again.
     */
    public function seen(SeenPushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $user->pushSubscriptions()->where('endpoint', $request->endpoint())->first();
        if ($subscription === null) {
            return response()->noContent(Response::HTTP_NOT_FOUND);
        }

        $subscription->forceFill(['last_seen_at' => now()])->save();

        return response()->noContent();
    }
}
