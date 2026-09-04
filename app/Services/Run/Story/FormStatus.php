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
            return 'not read yet';
        }

        // Mirror of resources/js/lib/formStatus.ts LABELS (keep both in sync).
        return match ($load['form_status']) {
            'fresh' => 'feeling fresh',
            'optimal' => 'right on track',
            'fatigued' => 'getting tired',
            'overreaching' => 'overreaching',
            default => 'right on track',
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
