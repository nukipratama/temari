<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Services\Run\Metrics\LoadBalance;
use App\Services\Run\Metrics\TrainingFormStatus;

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

        return self::balance($load)->value;
    }

    /**
     * @param  array<string, mixed>|null  $load
     */
    public static function tone(?array $load): string
    {
        if ($load === null) {
            return 'neutral';
        }

        return match (self::balance($load)) {
            LoadBalance::Fresh => 'positive',
            LoadBalance::Steady => 'neutral',
            LoadBalance::Heavy => 'warning',
        };
    }

    /**
     * @param  array<string, mixed>  $load
     */
    private static function balance(array $load): LoadBalance
    {
        $status = is_string($load['form_status'] ?? null) ? TrainingFormStatus::tryFrom($load['form_status']) : null;

        return $status?->loadBalance() ?? LoadBalance::Steady;
    }
}
