<?php

declare(strict_types=1);

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Database\Factories\AI\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property AnalysisType $analysis_type
 * @property string|null $discriminator
 * @property AnalysisStatus $status
 * @property string|null $content
 * @property string|null $content_fingerprint
 * @property string|null $error
 * @property Carbon|null $generated_at
 * @property Carbon|null $queued_at
 * @property int $attempts
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'subject_type',
    'subject_id',
    'analysis_type',
    'discriminator',
    'status',
    'content',
    'content_fingerprint',
    'error',
    'generated_at',
    'queued_at',
    'attempts',
])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    /**
     * Max real LLM executions before ai:self-heal gives up on a Failed row and
     * dead-letters it for a manual retry. `attempts` bumps once per job run
     * (markProcessing) and resets to 0 on invalidate, so a manual "Baca ulang"
     * re-arms the budget; capped no-op dispatches never touch it.
     */
    public const int MAX_SELF_HEAL_ATTEMPTS = 3;

    /**
     * How long a Queued/Processing row may sit before it counts as a lost-queue
     * zombie ({@see self::scopeStaleInFlight()}), and how long a Pending/Queued
     * row may sit before /ai-usage surfaces it as "Nyangkut". Well beyond the
     * job's tries + backoff + Retry-After cap, so a genuinely in-flight row is
     * never yanked mid-attempt.
     */
    public const int STALE_IN_FLIGHT_HOURS = 2;

    protected $table = 'ai_analyses';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'analysis_type' => AnalysisType::class,
            'status' => AnalysisStatus::class,
            'generated_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    #[Scope]
    protected function forSubject(
        Builder $query,
        string $subjectType,
        int $subjectId,
        AnalysisType $type,
        ?string $discriminator = null,
    ): Builder {
        return $query
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('analysis_type', $type)
            ->where('discriminator', $discriminator);
    }

    /**
     * Rows ai:self-heal may re-dispatch: still Pending or Failed and under the
     * retry budget. A Pending row is always attempts=0, so the budget only ever
     * excludes a Failed row that has burned its retries.
     *
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    #[Scope]
    protected function stalled(Builder $query): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), [AnalysisStatus::Pending, AnalysisStatus::Failed])
            ->where($this->qualifyColumn('attempts'), '<', self::MAX_SELF_HEAL_ATTEMPTS);
    }

    /**
     * Rows ai:self-heal has given up on: Failed with the retry budget exhausted.
     * These surface on /ai-usage for a manual per-user re-arm.
     *
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    #[Scope]
    protected function deadLettered(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('status'), AnalysisStatus::Failed)
            ->where($this->qualifyColumn('attempts'), '>=', self::MAX_SELF_HEAL_ATTEMPTS);
    }

    /**
     * In-flight zombies: rows stuck Queued/Processing since before $threshold,
     * i.e. their queue job was lost (Redis incident / ill-timed deploy) so they
     * never settled. ai:self-heal reverts these to Pending. Uses queued_at (the
     * enqueue time, untouched by markProcessing), falling back to updated_at for
     * a row that somehow has no queued_at.
     *
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    #[Scope]
    protected function staleInFlight(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), [AnalysisStatus::Queued, AnalysisStatus::Processing])
            ->where(function (Builder $inner) use ($threshold): void {
                $inner
                    ->where($this->qualifyColumn('queued_at'), '<', $threshold)
                    ->orWhere(function (Builder $fallback) use ($threshold): void {
                        $fallback
                            ->whereNull($this->qualifyColumn('queued_at'))
                            ->where($this->qualifyColumn('updated_at'), '<', $threshold);
                    });
            });
    }

    /**
     * Seconds left before this row may be re-triggered, or null if no cooldown
     * applies. Only a Done row can cool; the window is a Redis-backed
     * {@see Cooldown} started at {@see AnalysisService::markDone()}.
     */
    public function cooldownRemaining(): ?int
    {
        if ($this->status !== AnalysisStatus::Done) {
            return null;
        }

        return $this->cooldown()->remaining();
    }

    /**
     * Opens this row's re-trigger cooldown window. Called from
     * {@see AnalysisService::markDone()} so a "Baca ulang" can't re-fire the
     * LLM for the same block until the window elapses.
     */
    public function startCooldown(): void
    {
        $this->cooldown()->start();
    }

    private function cooldown(): Cooldown
    {
        return new Cooldown(self::cooldownKey($this->analysis_type, $this->subject_id, $this->discriminator));
    }

    /**
     * The RateLimiter key for a row's re-trigger cooldown. Keyed by the row's
     * identity (type + subject + discriminator), which is unique per user's
     * resource, so cooldowns never collide across users.
     */
    public static function cooldownKey(AnalysisType $type, int $subjectId, ?string $discriminator): string
    {
        return "ai-cooldown:{$type->value}:{$subjectId}:".($discriminator ?? '');
    }

    /**
     * The user id that owns this row, resolved per subject type. The `*_user_*`
     * string subject types store the user id directly as subject_id. Single
     * source of truth for subject to owner mapping.
     */
    public function ownerId(): ?int
    {
        return match ($this->subject_type) {
            Activity::class => Activity::query()->find($this->subject_id)?->user_id,
            WeeklySnapshot::class => WeeklySnapshot::query()->find($this->subject_id)?->user_id,
            RunCard::class => RunCard::query()->find($this->subject_id)?->activity?->user_id,
            PersonalRecord::class => PersonalRecord::query()->find($this->subject_id)?->user_id,
            default => $this->subject_id,
        };
    }

    /**
     * Batch-resolve owner user ids for many rows, one query per subject_type
     * instead of {@see self::ownerId()}'s per-row find(). Same subject-type to
     * owner mapping, just grouped and batched for the /ai-usage dead-letter panel.
     *
     * @param  Collection<int, Analysis>  $rows
     * @return array<int, int|null>  Keyed by row id.
     */
    public static function ownerIdsForRows(Collection $rows): array
    {
        $ownerIds = [];

        foreach ($rows->groupBy('subject_type') as $subjectType => $group) {
            $subjectIds = $group->pluck('subject_id')->unique()->all();

            $userIdsBySubjectId = match ($subjectType) {
                Activity::class => Activity::query()->whereIn('id', $subjectIds)->pluck('user_id', 'id'),
                WeeklySnapshot::class => WeeklySnapshot::query()->whereIn('id', $subjectIds)->pluck('user_id', 'id'),
                RunCard::class => RunCard::query()->whereIn('id', $subjectIds)->with('activity')->get()
                    ->mapWithKeys(fn (RunCard $card): array => [$card->id => $card->activity?->user_id]),
                PersonalRecord::class => PersonalRecord::query()->whereIn('id', $subjectIds)->pluck('user_id', 'id'),
                default => null,
            };

            foreach ($group as $row) {
                $ownerIds[$row->id] = $userIdsBySubjectId === null
                    ? $row->subject_id
                    : ($userIdsBySubjectId[$row->subject_id] ?? null);
            }
        }

        return $ownerIds;
    }

    /**
     * Batch-resolve the payload for many subjects of one (type, subject_type)
     * in a single query. Every requested id is present in the result; ids with
     * no matching row get the null-row payload (status Pending), matching a
     * per-id {@see self::toPayload()} call.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>  Keyed by subject id.
     */
    public static function payloadsForSubjects(string $subjectType, AnalysisType $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = self::query()
            ->where('subject_type', $subjectType)
            ->where('analysis_type', $type)
            ->whereIn('subject_id', $ids)
            ->get()
            ->keyBy('subject_id');

        $payloads = [];
        foreach ($ids as $id) {
            $payloads[$id] = self::toPayload($rows->get($id), $type, $subjectType, $id);
        }

        return $payloads;
    }

    /**
     * @return array{
     *     id: int|null,
     *     status: string,
     *     content: string|null,
     *     type: string,
     *     is_zone_dependent: bool,
     *     subject_type: string,
     *     subject_id: int,
     *     discriminator: string|null,
     *     attempts: int,
     *     generated_at: string|null,
     *     retry_after_seconds: int|null,
     * }
     */
    public static function toPayload(
        ?self $row,
        AnalysisType $type,
        string $subjectType,
        int $subjectId,
        ?string $discriminator = null,
    ): array {
        return [
            'id' => $row?->id,
            'status' => ($row === null ? AnalysisStatus::Pending : $row->status)->value,
            'content' => $row?->content,
            'type' => $type->value,
            'is_zone_dependent' => $type->isZoneDependent(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'discriminator' => $discriminator,
            'attempts' => $row === null ? 0 : $row->attempts,
            'generated_at' => $row?->generated_at?->toIso8601String(),
            'retry_after_seconds' => $row?->cooldownRemaining(),
        ];
    }

    /**
     * Remaining Telegram-send cooldown for a {@see self::toPayload()} array, or
     * null when there is no row or it is not Done (only a Done row is ever
     * pushed, so only it can cool). Surfaced next to the manual "Kirim ke
     * Telegram" button so it renders a disabled countdown.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function telegramCooldownRemaining(array $payload): ?int
    {
        $id = $payload['id'] ?? null;
        if (! is_int($id) || ($payload['status'] ?? null) !== AnalysisStatus::Done->value) {
            return null;
        }

        return (new Cooldown(Cooldown::telegramKey($id)))->remaining();
    }
}
