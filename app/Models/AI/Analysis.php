<?php

declare(strict_types=1);

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
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
     * applies. A row is cooling when its status is Done, it has a
     * `generated_at`, and that timestamp is within `ai.cooldown_seconds`.
     */
    public function cooldownRemaining(): ?int
    {
        if ($this->status !== AnalysisStatus::Done || $this->generated_at === null) {
            return null;
        }

        $cooldown = (int) config('ai.cooldown_seconds', 300);
        if ($cooldown <= 0) {
            return null;
        }

        $unlocksAt = $this->generated_at->copy()->addSeconds($cooldown);
        $remaining = (int) Carbon::now()->diffInSeconds($unlocksAt, absolute: false);

        return $remaining > 0 ? $remaining : null;
    }

    /**
     * @return array{
     *     id: int|null,
     *     status: string,
     *     content: string|null,
     *     type: string,
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
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'discriminator' => $discriminator,
            'attempts' => $row === null ? 0 : $row->attempts,
            'generated_at' => $row?->generated_at?->toIso8601String(),
            'retry_after_seconds' => $row?->cooldownRemaining(),
        ];
    }
}
