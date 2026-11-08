<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EarlyPassStraddled;
use App\Services\AI\AnalysisService;

final readonly class RenarrateOnEarlyPassStraddled
{
    public function __construct(private AnalysisService $analysis)
    {
    }

    public function handle(EarlyPassStraddled $event): void
    {
        $row = $event->analysis;

        $this->analysis->request(
            $row->subject_type,
            $row->subject_id,
            $row->analysis_type,
            $row->discriminator,
            invalidate: true,
        );
    }
}
