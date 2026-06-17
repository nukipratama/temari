<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Models\AI\Analysis;
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
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     * Attempts the ingest pipeline makes to fetch an activity's detail before
     * giving up. A stub below this count is still retryable (pending); at or
     * above it the row is stranded. Single source of truth for the threshold.
     */
    public const int MAX_DETAIL_FETCH_ATTEMPTS = 5;

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
     * Stubs still awaiting ingest and below the give-up threshold — the set the
     * `strava:ingest` drain works through, oldest-first.
     *
     * @param  Builder<Activity>  $query
     */
    #[Scope]
    protected function pendingIngest(Builder $query): void
    {
        $query->withStubs()
            ->whereNull('analyzed_at')
            ->where('detail_fail_count', '<', self::MAX_DETAIL_FETCH_ATTEMPTS);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The id of the user's latest run by `start_date_local` (the head of the
     * per-activity narration chain), or null when the user has no dated run.
     * Single source of truth for "latest run", shared by the run-detail page
     * and the chain-aware analysis trigger.
     */
    public static function latestIdForUser(int $userId): ?int
    {
        $id = self::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->orderByDesc('activity_details.start_date_local')
            ->value('activities.id');

        return $id === null ? null : (int) $id;
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
     * The per-activity narration rows (post_run speech + run insights), keyed by
     * the polymorphic subject. Lets the connected + chained activity pipeline
     * query a run's group state (e.g. earliest Pending link of a user's chain).
     *
     * @return MorphMany<Analysis, $this>
     */
    public function analyses(): MorphMany
    {
        return $this->morphMany(Analysis::class, 'subject');
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
