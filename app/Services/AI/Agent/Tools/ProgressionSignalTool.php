<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\PrCategory;
use App\Models\PersonalRecord;
use App\Models\User;
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
        return 'Jarak yang paling banyak dia perbaiki sepanjang riwayatnya, dengan selisih detiknya '
            .'(label + delta_sec). Null kalau belum ada jarak yang punya minimal dua catatan buat '
            .'dibandingkan, jadi jangan mengarang progres.';
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
        $bestDelta = 0;

        foreach (self::CATEGORIES as $category) {
            $record = $records->first(fn (PersonalRecord $row): bool => $row->category === $category);
            if ($record === null) {
                continue;
            }

            $series = $this->progressionSeriesBuilder->buildMany($this->user, [$record], fn () => null);
            $data = $series[$category->value] ?? null;
            if ($data === null || count($data['times_sec']) < 2) {
                continue;
            }

            $delta = (int) (max($data['times_sec']) - min($data['times_sec']));
            if ($delta > $bestDelta) {
                $bestDelta = $delta;
                $best = ['label' => $record->category->label(), 'delta_sec' => $delta];
            }
        }

        return ['progression_signal' => $best];
    }
}
