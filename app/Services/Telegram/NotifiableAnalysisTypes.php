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
     * Map of notifiable type to a data-less fallback `title` used when the
     * type's dynamic data can't be resolved, and the tap-through CTA appended
     * before the link. The opt-in is not per type:
     * one channel-neutral master switch on NotificationPreference governs every
     * entry here, over both Telegram and web push.
     *
     * @var array<string, array{title: string, cta: string}>
     */
    public const array TYPES = [
        AnalysisType::PostRunSpeech->value => ['title' => 'Your run is in.', 'cta' => 'View run details'],
        AnalysisType::WeeklyRecap->value => ['title' => 'Your weekly recap is ready', 'cta' => 'View history'],
        AnalysisType::MonthlyRecap->value => ['title' => 'Your monthly recap is ready', 'cta' => 'View calendar'],
    ];
}
