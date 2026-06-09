<?php

declare(strict_types=1);

namespace App\Http\Controllers\Strava;

use App\Http\Controllers\Controller;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\Activity;
use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Strava push subscription endpoint.
 *
 * Unauthenticated by design — Strava calls it without a session. The GET
 * handshake is gated on the shared verify token; the POST event channel is
 * scoped to the athlete the connection belongs to, so an unknown owner_id is
 * a no-op rather than a leak.
 *
 * @see https://developers.strava.com/docs/webhooks/
 */
class StravaWebhookController extends Controller
{
    /**
     * Subscription validation handshake. Strava issues a GET with
     * `hub.mode=subscribe`, `hub.verify_token` and `hub.challenge`; we echo the
     * challenge back as JSON only when the token matches our configured secret.
     */
    public function verify(Request $request): JsonResponse
    {
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
    public function handle(Request $request): JsonResponse
    {
        $objectType = (string) $request->input('object_type', '');
        $aspectType = (string) $request->input('aspect_type', '');
        $objectId = (int) $request->input('object_id');
        $athleteId = (int) $request->input('owner_id');

        // Heartbeat: a flatline on the /pulse Strava-health card means Strava
        // stopped delivering and we're silently leaning on the hourly poll.
        Pulse::record('strava_webhook', $aspectType !== '' ? $aspectType : 'unknown')->count();

        $connection = StravaConnection::query()
            ->where('strava_athlete_id', $athleteId)
            ->first();

        if ($connection === null) {
            // Unknown athlete (never connected, or already pruned). Ack so
            // Strava stops retrying; nothing to do locally.
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
        if (in_array($aspectType, ['create', 'update'], strict: true)) {
            if ($connection->isRevoked()) {
                return;
            }

            // The Strava kill-switch is enforced downstream in SyncOrchestrator;
            // a disabled sync no-ops the job rather than being gated here.
            SyncActivitiesJob::dispatch($connection->user_id, $stravaActivityId);

            return;
        }

        if ($aspectType === 'delete') {
            $this->deleteLocalActivity($connection, $stravaActivityId);
        }
    }

    private function handleAthleteEvent(Request $request, StravaConnection $connection, string $aspectType): void
    {
        if ($aspectType === 'delete') {
            $this->revokeAndLog($connection, 'webhook_athlete_delete');

            return;
        }

        if ($aspectType !== 'update') {
            return;
        }

        // Deauthorization arrives as updates.authorized = "false" (a string).
        $authorized = $request->input('updates.authorized');
        if ($authorized === 'false' || $authorized === false) {
            $this->revokeAndLog($connection, 'webhook_deauth');
        }
    }

    private function revokeAndLog(StravaConnection $connection, string $source): void
    {
        $wasAlreadyRevoked = $connection->isRevoked();
        $connection->markRevoked();

        Pulse::record('strava_revoked', $source)->count();
        Log::info("strava.webhook {$source} — connection revoked", [
            'strava_athlete_id' => $connection->strava_athlete_id,
        ]);

        if (! $wasAlreadyRevoked) {
            StravaSyncLog::log(
                $connection->user_id,
                'revoked',
                error: "Connection revoked via {$source}",
            );
        }
    }

    private function deleteLocalActivity(StravaConnection $connection, int $stravaActivityId): void
    {
        // Cascades to detail / stream / card rows via FK on delete. withStubs()
        // so a delete webhook for a not-yet-ingested activity still removes it
        // (the AnalyzedScope would otherwise hide the stub from this query).
        $deleted = Activity::query()
            ->withStubs()
            ->where('user_id', $connection->user_id)
            ->where('strava_external_id', $stravaActivityId)
            ->delete();

        if ($deleted > 0) {
            Log::info('strava.webhook removed deleted activity', [
                'user_id' => $connection->user_id,
                'strava_external_id' => $stravaActivityId,
            ]);
        }
    }

    private function ack(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
