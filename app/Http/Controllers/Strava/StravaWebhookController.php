<?php

declare(strict_types=1);

namespace App\Http\Controllers\Strava;

use App\Http\Controllers\Controller;
use App\Jobs\Strava\CleanupDeletedActivityJob;
use App\Jobs\Strava\ResyncActivityJob;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Jobs\Strava\VerifyStravaRevocationJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Strava push subscription endpoint.
 *
 * Strava calls it without a session, so both routes carry a secret callback
 * token in the path; a wrong token answers 404. The GET handshake is also gated
 * on the shared verify token, and the POST on the recorded subscription id and
 * the athlete the connection belongs to, so an unknown owner_id is a no-op
 * rather than a leak.
 *
 * @see https://developers.strava.com/docs/webhooks/
 */
class StravaWebhookController extends Controller
{
    /** @var list<string> */
    private const array HEARTBEAT_ASPECTS = ['create', 'update', 'delete'];

    /**
     * Subscription validation handshake. Strava issues a GET with
     * `hub.mode=subscribe`, `hub.verify_token` and `hub.challenge`; we echo the
     * challenge back as JSON only when the token matches our configured secret.
     */
    public function verify(Request $request, string $callbackToken): JsonResponse
    {
        self::abortUnlessCallbackToken($callbackToken);

        $expected = (string) config('services.strava.webhook_verify_token');
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge');

        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token) || ! is_string($challenge)) {
            Log::warning('strava.webhook.verify rejected', ['mode' => $mode]);

            return response()->json(['error' => 'invalid verification request'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['hub.challenge' => $challenge]);
    }

    /**
     * Event delivery. Strava POSTs one event per body; we ack with 200 quickly
     * and push the actual work onto the queue.
     */
    public function handle(Request $request, string $callbackToken): JsonResponse
    {
        self::abortUnlessCallbackToken($callbackToken);
        abort_unless(self::isOurSubscription($request), Response::HTTP_NOT_FOUND);

        $objectType = (string) $request->input('object_type', '');
        $aspectType = (string) $request->input('aspect_type', '');
        $objectId = (int) $request->input('object_id');
        $athleteId = (int) $request->input('owner_id');

        // Heartbeat: a flatline on the /pulse Strava-health card means Strava
        // stopped delivering and we're silently leaning on the hourly poll.
        Pulse::record('strava_webhook', self::heartbeatKey($aspectType))->count();

        $connection = StravaConnection::query()
            ->where('strava_athlete_id', $athleteId)
            ->first();

        if ($connection === null) {
            // Unknown athlete (never connected, or already pruned). Ack so
            // Strava stops retrying; nothing to do locally.
            return $this->ack();
        }

        if ($connection->user->is_demo) {
            return $this->ack();
        }

        if ($objectType === 'athlete') {
            $this->handleAthleteEvent($request, $connection, $aspectType);

            return $this->ack();
        }

        if ($objectType === 'activity' && $objectId > 0) {
            $this->handleActivityEvent($connection, $aspectType, $objectId);
        }

        return $this->ack();
    }

    private function handleActivityEvent(StravaConnection $connection, string $aspectType, int $stravaActivityId): void
    {
        if ($aspectType === 'delete') {
            $this->deleteLocalActivity($connection, $stravaActivityId);

            return;
        }

        if (! in_array($aspectType, ['create', 'update'], strict: true)) {
            return;
        }

        if ($connection->isRevoked()) {
            return;
        }

        // An update to an already-ingested run is a no-op on the create path:
        // SyncOrchestrator skips re-ingesting an analyzed activity. Force a
        // single-activity re-pull so an edit (name/distance/type) reflects
        // within seconds instead of waiting for the hourly poll. Fall back to
        // the full-sync path when there is no local row for it yet (a create, or
        // an update for a run we never ingested).
        if ($aspectType === 'update') {
            $activity = Activity::query()
                ->withStubs()
                ->where('user_id', $connection->user_id)
                ->where('strava_external_id', $stravaActivityId)
                ->first();

            if ($activity !== null) {
                ResyncActivityJob::dispatch($activity->id);

                return;
            }
        }

        // The Strava kill-switch is enforced downstream in SyncOrchestrator;
        // a disabled sync no-ops the job rather than being gated here.
        SyncActivitiesJob::dispatch($connection->user_id, $stravaActivityId);
    }

    private function handleAthleteEvent(Request $request, StravaConnection $connection, string $aspectType): void
    {
        if ($aspectType === 'delete') {
            $this->verifyThenRevoke($connection, 'webhook_athlete_delete');

            return;
        }

        if ($aspectType !== 'update') {
            return;
        }

        // Deauthorization arrives as updates.authorized = "false" (a string).
        $authorized = $request->input('updates.authorized');
        if ($authorized === 'false' || $authorized === false) {
            $this->verifyThenRevoke($connection, 'webhook_deauth');
        }
    }

    private function verifyThenRevoke(StravaConnection $connection, string $source): void
    {
        // The webhook body is unauthenticated and forgeable (owner_id is
        // attacker-supplied). Don't tear the connection down on the raw event:
        // queue a job that confirms the grant is really gone against the Strava
        // API before revoking (see VerifyStravaRevocationJob). Queued so the
        // webhook still acks fast.
        VerifyStravaRevocationJob::dispatch($connection->id, $source, $connection->credential_version);
    }

    private function deleteLocalActivity(StravaConnection $connection, int $stravaActivityId): void
    {
        // Queue the delete + downstream recompute (weekly snapshots, PRs,
        // orphaned narration) so the webhook still acks fast. The job resolves
        // the local row (withStubs, so a not-yet-ingested stub is removed too).
        CleanupDeletedActivityJob::dispatch($connection->user_id, $stravaActivityId);
    }

    public static function callbackUrl(): string
    {
        return route('strava.webhook.verify', ['token' => (string) config('services.strava.webhook_callback_token')]);
    }

    public static function maskWebhookSecrets(string $text): string
    {
        return (string) preg_replace(
            ['~(/strava/webhook/)[^/?#\s"\'),]+~', '~(hub[._]verify_token=)[^&#\s"\'),]+~'],
            '$1****',
            $text,
        );
    }

    private static function abortUnlessCallbackToken(string $token): void
    {
        abort_unless(hash_equals((string) config('services.strava.webhook_callback_token'), $token), Response::HTTP_NOT_FOUND);
    }

    private static function isOurSubscription(Request $request): bool
    {
        $expected = (string) config('services.strava.webhook_subscription_id');
        $given = $request->input('subscription_id');

        return $expected !== '' && is_scalar($given) && (string) $given === $expected;
    }

    private function ack(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    /**
     * The body is attacker-supplied and reaches Pulse before any athlete check,
     * so anything off the known set collapses to a single bucket rather than
     * minting a new Pulse key per request.
     */
    private static function heartbeatKey(string $aspectType): string
    {
        return in_array($aspectType, self::HEARTBEAT_ASPECTS, true) ? $aspectType : 'unknown';
    }
}
