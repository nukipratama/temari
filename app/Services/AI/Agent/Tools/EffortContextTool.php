<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\SessionIntent;

final class EffortContextTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly RelativeEffort $relativeEffort,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_effort_context';
    }

    public function description(): string
    {
        return 'Seberapa berat sesi ini dibanding niatnya dan dibanding kebiasaan 28 hari terakhir: '
            .'session_intent (workout/race/easy/unknown, tagged atau inferred), relative_effort band, '
            .'dan decoupling. decoupling null berarti lari ini terlalu pendek untuk mengukurnya, '
            .'jadi jangan dikarang.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'session_intent' => SessionIntent::forDetail($this->detail),
            'relative_effort' => $this->relativeEffort->forRun($this->activity, $this->detail),
            'decoupling_pct' => $this->summary()['decoupling_pct'] ?? null,
        ];
    }
}
