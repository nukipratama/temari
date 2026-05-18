<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Narrators\TrendCaptionNarrator;
use Illuminate\Support\Carbon;
use Override;

class AnalyzeTrendCaptionJob extends AnalyzeAbstractJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            throw new UnavailableException("User {$row->subject_id} not found");
        }

        $asOf = $row->discriminator !== null
            ? Carbon::parse($row->discriminator)
            : Carbon::today();

        return app(TrendCaptionNarrator::class)->generate($user, $asOf);
    }
}
