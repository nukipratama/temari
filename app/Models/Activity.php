<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int $strava_external_id
 * @property Carbon|null $fetched_at
 * @property Carbon|null $analyzed_at
 * @property Carbon|null $milestones_detected_at
 * @property array<int, array<string, mixed>>|null $milestone_payload
 * @property int $detail_fail_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read ActivityDetail|null $detail
 * @property-read ActivityStream|null $stream
 * @property-read RunCard|null $runCard
 * @property-read Collection<int, PersonalRecord> $personalRecords
 * @property-read Collection<int, StoryLine> $storyLines
 */
#[Fillable([
    'user_id',
    'strava_external_id',
    'fetched_at',
    'analyzed_at',
    'milestones_detected_at',
    'milestone_payload',
    'detail_fail_count',
])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<ActivityDetail, $this>
     */
    public function detail(): HasOne
    {
        return $this->hasOne(ActivityDetail::class);
    }

    /**
     * @return HasOne<ActivityStream, $this>
     */
    public function stream(): HasOne
    {
        return $this->hasOne(ActivityStream::class);
    }

    /**
     * @return HasOne<RunCard, $this>
     */
    public function runCard(): HasOne
    {
        return $this->hasOne(RunCard::class);
    }

    /**
     * @return HasMany<PersonalRecord, $this>
     */
    public function personalRecords(): HasMany
    {
        return $this->hasMany(PersonalRecord::class);
    }

    /**
     * @return HasMany<StoryLine, $this>
     */
    public function storyLines(): HasMany
    {
        return $this->hasMany(StoryLine::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'strava_external_id' => 'integer',
            'fetched_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'milestones_detected_at' => 'datetime',
            'milestone_payload' => 'array',
            'detail_fail_count' => 'integer',
        ];
    }
}
