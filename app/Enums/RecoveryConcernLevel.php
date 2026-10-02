<?php

declare(strict_types=1);

namespace App\Enums;

enum RecoveryConcernLevel: string
{
    case None = 'none';
    case Mild = 'mild';
    case Moderate = 'moderate';
    case Severe = 'severe';
}
