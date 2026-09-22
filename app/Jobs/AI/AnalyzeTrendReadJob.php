<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\Narrators\TrendReadNarrator;
use App\Services\AI\TrendReadFingerprint;

/**
 * Row job for one range of "Temari's read" on the Trends tab. Not chained —
 * each range is always read as of now, never against a specific prior link —
 * so the default no-op afterDone() from AnalyzeRowJob is correct as-is.
 */
class AnalyzeTrendReadJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        $user = User::query()->findOrFail($row->subject_id);

        return app(TrendReadNarrator::class)->generate($user, (string) $row->discriminator);
    }

    /**
     * Stamped so a scheduled re-read can tell whether the range's own numbers
     * moved since this generation — see {@see TrendReadFingerprint::forUser()}.
     */
    protected function fingerprintFor(Analysis $row): ?string
    {
        $user = User::query()->find($row->subject_id);
        if ($user === null) {
            return null;
        }

        return app(TrendReadFingerprint::class)->forUser($user, (string) $row->discriminator);
    }
}
