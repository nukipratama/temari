<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

    private const int STALE_AFTER_MINUTES = 15;

    /**
     * Claim the delivery before sending. insertOrIgnore is atomic on the unique
     * (analysis_id, channel) pair. A failed or stale row is taken over with a
     * conditional version update, so only one retry receives the new fence.
     */
    public function claim(int $analysisId, string $channel): ?int
    {
        $inserted = NotificationDelivery::query()->insertOrIgnore([
            'analysis_id' => $analysisId,
            'channel' => $channel,
            'status' => NotificationDeliveryStatus::Pending->value,
            'created_at' => now(),
            'claimed_at' => now(),
            'claim_version' => 1,
        ]) !== 0;

        if ($inserted) {
            return 1;
        }

        $row = $this->rowFor($analysisId, $channel)->first();
        if ($row === null) {
            return null;
        }

        $version = $row->claim_version + 1;
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $updated = $this->rowFor($analysisId, $channel)
            ->where('claim_version', $row->claim_version)
            ->where(function (Builder $claimable) use ($channel, $staleBefore): void {
                $claimable
                    ->where('status', NotificationDeliveryStatus::Failed->value)
                    ->orWhere(function (Builder $rearmed): void {
                        $rearmed
                            ->where('status', NotificationDeliveryStatus::Pending->value)
                            ->whereNull('claimed_at');
                    });

                if ($channel === 'webpush') {
                    $claimable->orWhere(function (Builder $stale) use ($staleBefore): void {
                        $stale
                            ->where('status', NotificationDeliveryStatus::Pending->value)
                            ->whereNotNull('claimed_at')
                            ->where('claimed_at', '<', $staleBefore);
                    });
                }
            })
            ->update([
                'status' => NotificationDeliveryStatus::Pending->value,
                'error' => null,
                'created_at' => now(),
                'claimed_at' => now(),
                'claim_version' => $version,
                'settled_at' => null,
            ]);

        return $updated === 0 ? null : $version;
    }

    public function markSent(int $analysisId, string $channel, int $claimVersion): bool
    {
        return $this->rowFor($analysisId, $channel)
            ->where('status', NotificationDeliveryStatus::Pending->value)
            ->where('claim_version', $claimVersion)
            ->update([
                'status' => NotificationDeliveryStatus::Sent->value,
                'error' => null,
                'claimed_at' => null,
                'settled_at' => now(),
            ]) !== 0;
    }

    public function markFailed(int $analysisId, string $channel, int $claimVersion, string $error): bool
    {
        return $this->rowFor($analysisId, $channel)
            ->where('status', NotificationDeliveryStatus::Pending->value)
            ->where('claim_version', $claimVersion)
            ->update([
                'status' => NotificationDeliveryStatus::Failed->value,
                'error' => Str::limit($error, self::ERROR_LIMIT),
                'claimed_at' => null,
                'settled_at' => now(),
            ]) !== 0;
    }

    public function markStaleWebPushSkipped(int $analysisId, int $claimVersion): bool
    {
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);

        return $this->rowFor($analysisId, 'webpush')
            ->where('status', NotificationDeliveryStatus::Pending->value)
            ->where('claim_version', $claimVersion)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', $staleBefore)
            ->update([
                'status' => NotificationDeliveryStatus::Failed->value,
                'error' => 'Retry skipped because current preferences or channel eligibility no longer allow web push.',
                'claimed_at' => null,
                'settled_at' => now(),
            ]) !== 0;
    }

    public function recordForcedSent(int $analysisId, string $channel): bool
    {
        if (NotificationDelivery::query()->insertOrIgnore([
            'analysis_id' => $analysisId,
            'channel' => $channel,
            'status' => NotificationDeliveryStatus::Sent->value,
            'created_at' => now(),
            'claim_version' => 1,
            'settled_at' => now(),
        ]) !== 0) {
            return true;
        }

        return $this->rowFor($analysisId, $channel)
            ->update([
                'status' => NotificationDeliveryStatus::Sent->value,
                'claim_version' => DB::raw('claim_version + 1'),
                'error' => null,
                'claimed_at' => null,
                'settled_at' => now(),
            ]) !== 0;
    }

    public function recordForcedFailed(int $analysisId, string $channel, string $error): bool
    {
        return NotificationDelivery::query()->insertOrIgnore([
            'analysis_id' => $analysisId,
            'channel' => $channel,
            'status' => NotificationDeliveryStatus::Failed->value,
            'error' => Str::limit($error, self::ERROR_LIMIT),
            'created_at' => now(),
            'claim_version' => 1,
            'settled_at' => now(),
        ]) !== 0;
    }

    /** @return array{webpush_retries: list<array{analysis_id: int, claim_version: int}>, telegram_abandoned: int} */
    public function recoverStale(): array
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $recovered = ['webpush_retries' => [], 'telegram_abandoned' => 0];

        NotificationDelivery::query()
            ->where('status', NotificationDeliveryStatus::Pending->value)
            ->whereIn('channel', ['webpush', 'telegram'])
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($cutoff, &$recovered): void {
                foreach ($rows as $row) {
                    if ($row->channel === 'webpush') {
                        $recovered['webpush_retries'][] = [
                            'analysis_id' => $row->analysis_id,
                            'claim_version' => $row->claim_version,
                        ];
                    } elseif ($row->channel === 'telegram') {
                        $updated = $this->rowFor($row->analysis_id, $row->channel)
                            ->whereKey($row->id)
                            ->where('status', NotificationDeliveryStatus::Pending->value)
                            ->where('claim_version', $row->claim_version)
                            ->where('claimed_at', '<', $cutoff)
                            ->update([
                                'status' => NotificationDeliveryStatus::Abandoned->value,
                                'claim_version' => $row->claim_version + 1,
                                'error' => 'Delivery result is unknown after a stale claim; automatic retry was skipped to avoid a duplicate.',
                                'claimed_at' => null,
                                'settled_at' => now(),
                            ]);
                        $recovered['telegram_abandoned'] += (int) ($updated !== 0);
                    }
                }
            });

        return $recovered;
    }

    /** @return Builder<NotificationDelivery> */
    private function rowFor(int $analysisId, string $channel): Builder
    {
        return NotificationDelivery::query()
            ->where('analysis_id', $analysisId)
            ->where('channel', $channel);
    }
}
