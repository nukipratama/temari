<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

final class FormStatus
{
    /**
     * @param  array<string, mixed>|null  $load
     */
    public static function label(?array $load): string
    {
        if ($load === null) {
            return 'Not read yet';
        }

        // Mirror of resources/js/lib/formStatus.ts LABELS (keep both in sync).
        return match ($load['form_status']) {
            'fresh' => 'Feeling Fresh',
            'optimal' => 'Right on Track',
            'fatigued' => 'Getting Tired',
            'overreaching' => 'Overreaching',
            default => 'Right on Track',
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
