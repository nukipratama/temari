<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Narrators\MonthlyRecapNarrator;
use Override;

class AnalyzeMonthlyRecapJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            throw new UnavailableException("User {$row->subject_id} not found");
        }

        $month = $row->discriminator;
        if ($month === null) {
            throw new UnavailableException('MonthlyRecap requires a discriminator (Y-m).');
        }

        return app(MonthlyRecapNarrator::class)->generate($user, $month);
    }
}
