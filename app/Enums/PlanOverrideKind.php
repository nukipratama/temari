<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanOverrideKind: string
{
    case ReadinessEase = 'readiness_ease';
    case WeeklyDrop = 'weekly_drop';
    case BudgetDrop = 'budget_drop';
}
