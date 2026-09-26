<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery as DeliveryRow;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Notification delivery outcomes on the /pulse dashboard. Until the outcome
 * columns landed, a claim row proved only that a send was attempted, so a
 * Telegram push that never arrived was indistinguishable from one nobody read.
 *
 * Not lazy: one grouped count over a small table, so deferring buys nothing.
 */
class NotificationDeliveryHealth extends Card
{
    private const int RECENT_ISSUES = 20;

    public function render(): Renderable
    {
        $channels = $this->perChannelOutcomes();

        $recentIssues = DeliveryRow::query()
            ->whereIn('status', [NotificationDeliveryStatus::Failed->value, NotificationDeliveryStatus::Abandoned->value])
            ->orderByDesc('settled_at')
            ->limit(self::RECENT_ISSUES)
            ->get(['analysis_id', 'channel', 'status', 'error', 'settled_at']);

        $failed = (int) collect($channels)->sum('failed');
        $abandoned = (int) collect($channels)->sum('abandoned');
        $hasIssues = $failed + $abandoned > 0;

        return View::make('livewire.pulse.notification-delivery-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'statusBoxes' => [
                ['label' => 'sent', 'count' => (int) collect($channels)->sum('sent'), 'tone' => 'neutral'],
                ['label' => 'in flight', 'count' => (int) collect($channels)->sum('pending'), 'tone' => 'neutral'],
                ['label' => 'failed', 'count' => $failed, 'tone' => $failed > 0 ? 'alert' : 'neutral'],
                ['label' => 'abandoned', 'count' => $abandoned, 'tone' => $abandoned > 0 ? 'alert' : 'neutral'],
            ],
            'channels' => $channels,
            'recentIssues' => $recentIssues,
            'severity' => $hasIssues ? 'alert' : 'ok',
        ]);
    }

    /**
     * @return list<array{channel: string, sent: int, pending: int, failed: int, abandoned: int}>
     */
    private function perChannelOutcomes(): array
    {
        $rows = DeliveryRow::query()
            ->select('channel')
            ->selectRaw('SUM(status = ?) AS sent', [NotificationDeliveryStatus::Sent->value])
            ->selectRaw('SUM(status = ?) AS pending', [NotificationDeliveryStatus::Pending->value])
            ->selectRaw('SUM(status = ?) AS failed', [NotificationDeliveryStatus::Failed->value])
            ->selectRaw('SUM(status = ?) AS abandoned', [NotificationDeliveryStatus::Abandoned->value])
            ->groupBy('channel')
            ->orderBy('channel')
            ->get();

        return array_values($rows->map(fn (DeliveryRow $row): array => [
            'channel' => $row->channel,
            'sent' => (int) $row->getAttribute('sent'),
            'pending' => (int) $row->getAttribute('pending'),
            'failed' => (int) $row->getAttribute('failed'),
            'abandoned' => (int) $row->getAttribute('abandoned'),
        ])->all());
    }
}
