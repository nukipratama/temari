<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

/**
 * Single source of truth for `form_status` → label/tone mapping. Both the
 * rule-based Briefing and the LlmBriefingNarrator reach for this so the
 * recovery chip is consistent regardless of which narrator generated the
 * briefing.
 */
final class FormStatus
{
    /**
     * @param  array<string, mixed>|null  $load
     */
    public static function label(?array $load): string
    {
        if ($load === null) {
            return 'Form belum kebaca';
        }

        return match ($load['form_status']) {
            'fresh' => 'Form Fresh',
            'optimal' => 'Form Optimal',
            'fatigued' => 'Lelah',
            'overreaching' => 'Overreaching',
            default => 'Form Optimal',
        };
    }

    /**
     * @param  array<string, mixed>|null  $load
     */
    public static function tone(?array $load): string
    {
        if ($load === null) {
            return 'neutral';
        }

        return match ($load['form_status']) {
            'fresh' => 'positive',
            'fatigued' => 'warning',
            'overreaching' => 'alert',
            default => 'neutral',
        };
    }
}
