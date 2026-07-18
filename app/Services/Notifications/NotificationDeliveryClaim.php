<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * The per-(analysis, channel) idempotency claim shared by every notification
 * channel, so a queued retry — or a re-run of markDone for the same analysis —
 * never double-sends on a given channel. Backed by the unique (analysis_id,
 * channel) pair on notification_deliveries.
 */
class NotificationDeliveryClaim
{
    /**
     * Claim the delivery before sending. insertOrIgnore is atomic on the unique
     * (analysis_id, channel) pair, so a racing retry that already claimed it gets
     * 0 rows and the caller bails before re-sending.
     */
    public function claim(int $analysisId, string $channel): bool
    {
        return DB::table('notification_deliveries')->insertOrIgnore([
            'analysis_id' => $analysisId,
            'channel' => $channel,
            'created_at' => now(),
        ]) !== 0;
    }

    /** Release a claim so a failed send's retry can resend. */
    public function release(int $analysisId, string $channel): void
    {
        DB::table('notification_deliveries')
            ->where('analysis_id', $analysisId)
            ->where('channel', $channel)
            ->delete();
    }
}
