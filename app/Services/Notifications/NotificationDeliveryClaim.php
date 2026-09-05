<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The per-(analysis, channel) idempotency claim shared by every notification
 * channel, so a queued retry — or a re-run of markDone for the same analysis —
 * never double-sends on a given channel. Backed by the unique (analysis_id,
 * channel) pair on notification_deliveries, which also carries the outcome of
 * the send the claim guarded.
 */
class NotificationDeliveryClaim
{
    private const int ERROR_LIMIT = 1000;

    /**
     * Claim the delivery before sending. insertOrIgnore is atomic on the unique
     * (analysis_id, channel) pair, so a racing retry that already claimed it gets
     * 0 rows. A row left `failed` by an earlier attempt is taken over instead —
     * the conditional update is equally atomic — so a retry genuinely resends
     * rather than being deduped against a send that never landed.
     */
    public function claim(int $analysisId, string $channel): bool
    {
        $inserted = NotificationDelivery::query()->insertOrIgnore([
            'analysis_id' => $analysisId,
            'channel' => $channel,
            'status' => NotificationDeliveryStatus::Pending->value,
            'created_at' => now(),
        ]) !== 0;

        if ($inserted) {
            return true;
        }

        return $this->rowFor($analysisId, $channel)
            ->where('status', NotificationDeliveryStatus::Failed)
            ->update([
                'status' => NotificationDeliveryStatus::Pending,
                'error' => null,
                'created_at' => now(),
                'settled_at' => null,
            ]) !== 0;
    }

    /** Settle a claim as delivered, creating the row for a forced send that never claimed one. */
    public function markSent(int $analysisId, string $channel): void
    {
        $updated = $this->rowFor($analysisId, $channel)->update([
            'status' => NotificationDeliveryStatus::Sent,
            'error' => null,
            'settled_at' => now(),
        ]);

        if ($updated === 0) {
            NotificationDelivery::query()->insertOrIgnore([
                'analysis_id' => $analysisId,
                'channel' => $channel,
                'status' => NotificationDeliveryStatus::Sent->value,
                'created_at' => now(),
                'settled_at' => now(),
            ]);
        }
    }

    /**
     * Settle a claim as failed, which also releases it: claim() takes a failed row
     * over, so the next attempt resends. Only a pending row is settled — a forced
     * send that fails must not overwrite an earlier successful delivery — and a
     * forced send with no row of its own records one so the failure is still
     * visible.
     */
    public function markFailed(int $analysisId, string $channel, string $error): void
    {
        $message = Str::limit($error, self::ERROR_LIMIT);

        $updated = $this->rowFor($analysisId, $channel)
            ->where('status', NotificationDeliveryStatus::Pending)
            ->update([
                'status' => NotificationDeliveryStatus::Failed,
                'error' => $message,
                'settled_at' => now(),
            ]);

        if ($updated === 0) {
            NotificationDelivery::query()->insertOrIgnore([
                'analysis_id' => $analysisId,
                'channel' => $channel,
                'status' => NotificationDeliveryStatus::Failed->value,
                'error' => $message,
                'created_at' => now(),
                'settled_at' => now(),
            ]);
        }
    }

    /** @return Builder<NotificationDelivery> */
    private function rowFor(int $analysisId, string $channel): Builder
    {
        return NotificationDelivery::query()
            ->where('analysis_id', $analysisId)
            ->where('channel', $channel);
    }
}
