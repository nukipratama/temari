<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\AI\AnalysisType;

/**
 * Registry of the analysis types that fan out a Telegram notification when they
 * complete, and how each one resolves its user, preference flag, and message.
 * Adding a third notifiable event is a single entry here. See the AI-pipeline
 * note for the markDone hook that consults this.
 */
final class NotifiableAnalysisTypes
{
    /**
     * Map of notifiable type to the emoji leading the title line, a data-less
     * fallback `title` used when the type's dynamic data can't be resolved, and
     * the tap-through CTA appended before the link. The opt-in is not per type:
     * one channel-neutral master switch on NotificationPreference governs every
     * entry here, over both Telegram and web push.
     *
     * @var array<string, array{emoji: string, title: string, cta: string}>
     */
    public const array TYPES = [
        AnalysisType::PostRunSpeech->value => ['emoji' => '🏃', 'title' => 'Lari kamu udah masuk! 🏁', 'cta' => 'Lihat detail lari'],
        AnalysisType::WeeklyRecap->value => ['emoji' => '📊', 'title' => 'Rekap minggu lalu udah siap', 'cta' => 'Lihat riwayat'],
        AnalysisType::MonthlyRecap->value => ['emoji' => '🗓️', 'title' => 'Rekap bulanan udah siap', 'cta' => 'Lihat kalender'],
    ];
}
