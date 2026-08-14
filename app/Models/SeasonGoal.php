<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SeasonGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One of the 5 goals generated once at {@see Season} creation — a stable
 * checklist for the arc, unlike the day-by-day plan. Same shape as
 * `config/temari_goals.php`'s entries; `current` is never stored, only
 * resolved live by {@see \App\Services\Gamification\SeasonGoalResolver}.
 *
 * @property int $id
 * @property int $season_id
 * @property string $title
 * @property string $metric
 * @property string|null $metric_key
 * @property float $target
 * @property string $unit
 * @property-read Season $season
 */
#[Fillable(['season_id', 'title', 'metric', 'metric_key', 'target', 'unit'])]
class SeasonGoal extends Model
{
    /** @use HasFactory<SeasonGoalFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'season_id' => 'integer',
            'target' => 'float',
        ];
    }
}
