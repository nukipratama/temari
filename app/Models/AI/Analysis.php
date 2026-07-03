<?php

declare(strict_types=1);

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Database\Factories\AI\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    'error',
    'generated_at',
    'queued_at',
    'attempts',
])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

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
     * Seconds left before this row may be re-triggered, or null if no cooldown
     * applies. Only a Done row can cool; the window is a Redis-backed
     * {@see Cooldown} started at {@see AnalysisService::markDone()}.
     */
    public function cooldownRemaining(): ?int
    {
        if ($this->status !== AnalysisStatus::Done) {
            return null;
        }

        return (new Cooldown(self::cooldownKey($this->analysis_type, $this->subject_id, $this->discriminator)))
            ->remaining();
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
