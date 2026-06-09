<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Models\Scopes\AnalyzedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
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
 * @property-read StoryLine|null $postRunStoryLine
 */
#[ScopedBy([AnalyzedScope::class])]
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
     * Opt out of {@see AnalyzedScope} to include un-ingested stubs. Only the
     * Strava sync/ingest pipeline (which creates, drains and processes stubs)
     * should use this; everything user-facing must keep the default scope.
     *
     * @param  Builder<Activity>  $query
     */
    #[Scope]
    protected function withStubs(Builder $query): void
    {
        $query->withoutGlobalScope(AnalyzedScope::class);
    }

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
     * The single post-run story line, which carries this run's Temari mood. The
     * card surfaces read mood from here rather than recomputing it.
     *
     * @return HasOne<StoryLine, $this>
     */
    public function postRunStoryLine(): HasOne
    {
        return $this->hasOne(StoryLine::class)->where('kind', StoryLine::KIND_POST_RUN);
    }

    /**
     * Server-side only — keeps a per-row JSON blob (sometimes hundreds of bytes)
     * out of every Inertia payload that serializes an Activity collection.
     *
     * @var list<string>
     */
    protected $hidden = ['milestone_payload'];

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
