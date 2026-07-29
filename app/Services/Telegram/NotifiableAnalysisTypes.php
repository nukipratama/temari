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
     * Map of notifiable type to the NotificationPreference boolean column that
     * gates it (channel-neutral: the same opt-in governs Telegram + web push), the
     * emoji leading the title line, a data-less fallback `title` used when the
     * type's dynamic data can't be resolved, and the tap-through CTA appended
     * before the link.
     *
     * @var array<string, array{pref: string, emoji: string, title: string, cta: string}>
     */
    public const array TYPES = [
        AnalysisType::PostRunSpeech->value => ['pref' => 'post_run', 'emoji' => '🏃', 'title' => 'Lari kamu udah masuk! 🏁', 'cta' => 'Lihat detail lari'],
        AnalysisType::WeeklyRecap->value => ['pref' => 'weekly_recap', 'emoji' => '📊', 'title' => 'Rekap minggu lalu udah siap', 'cta' => 'Lihat riwayat'],
        AnalysisType::MonthlyRecap->value => ['pref' => 'monthly_recap', 'emoji' => '🗓️', 'title' => 'Rekap bulanan udah siap', 'cta' => 'Lihat kalender'],
    ];
}
