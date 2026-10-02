<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\ProgressionSeriesBuilder;
use Illuminate\Support\Carbon;

/**
 * The distance the runner has improved most at, and by how much.
 */
final class ProgressionSignalTool extends UserTool
{
    private const array CATEGORIES = [
        PrCategory::Km5,
        PrCategory::Km10,
        PrCategory::HalfMarathon,
        PrCategory::Marathon,
    ];

    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly ProgressionSeriesBuilder $progressionSeriesBuilder,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_progression_signal';
    }

    public function description(): string
    {
        return "The distance they've improved the most over the last six months (label), "
            .'comparing their best time in the first four weeks against their best in the last '
            .'four. relation says which way it moved: faster, slower, or flat. delta_formatted '
            .'(mm:ss) is how far it moved and the only form to quote -- the app\'s own progression '
            .'card shows the same figure; delta_sec is the same size in raw seconds, for judging '
            .'how big the move is, never for quoting. Neither number carries the direction, only '
            .'relation does. If progression_signal is missing, no distance has a time in both '
            .'windows to compare, so don\'t make up progress.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $records = PersonalRecord::query()
            ->where('user_id', $this->user->id)
            ->whereIn('category', self::CATEGORIES)
            ->orderBy('category')
            ->get();

        if ($records->isEmpty()) {
            return ['progression_signal' => null];
        }

        $best = null;
        $bestImprovement = null;

        // One build for every record: buildMany ORs the distance bands into a
        // single scan, so calling it per category was four full ActivityDetail
        // scans to answer one question.
        $series = $this->progressionSeriesBuilder->buildMany($this->user, array_values($records->all()), fn () => null);

        foreach (self::CATEGORIES as $category) {
            $progress = $series[$category->value]['progress'] ?? null;
            if ($progress === null) {
                continue;
            }

            $improvement = ($progress['from_sec'] - $progress['to_sec']) / $progress['from_sec'];
            if ($bestImprovement === null || $improvement > $bestImprovement) {
                $bestImprovement = $improvement;
                $best = [
                    'label' => $category->label(),
                    'relation' => $progress['relation'],
                    'delta_sec' => $progress['delta_sec'],
                    'delta_formatted' => DurationFormatter::hms($progress['delta_sec']),
                ];
            }
        }

        return ['progression_signal' => $best];
    }
}
