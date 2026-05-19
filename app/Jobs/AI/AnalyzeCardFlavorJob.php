<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Exceptions\AI\UnavailableException;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Services\AI\Narrators\CardFlavorNarrator;
use Override;

class AnalyzeCardFlavorJob extends AnalyzeRowJob
{
    #[Override]
    protected function generateContent(Analysis $row): string
    {
        $card = RunCard::query()->find($row->subject_id);
        if ($card === null) {
            throw new UnavailableException("RunCard {$row->subject_id} not found");
        }

        return app(CardFlavorNarrator::class)->generate($card);
    }
}
