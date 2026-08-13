<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\Models\Activity;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use Database\Factories\AI\RunQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * One "ask about this run" exchange: the question a user asked about a single
 * activity, and the answer that came back.
 *
 * Deliberately not an {@see Analysis} row. That model is keyed
 * `(subject_type, subject_id, analysis_type, discriminator)` and holds exactly
 * one regenerable body of copy per key; a run accumulates many questions, each
 * with its own text, so it needs rows of its own.
 *
 * @property int $id
 * @property int $user_id
 * @property int $activity_id
 * @property string $question
 * @property string|null $answer
 * @property AnalysisStatus $status
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Activity $activity
 */
#[Fillable(['user_id', 'activity_id', 'question', 'answer', 'status', 'error'])]
class RunQuestion extends Model
{
    /** @use HasFactory<RunQuestionFactory> */
    use HasFactory;

    /** Longest question the endpoint accepts, matching the column width. */
    public const int MAX_QUESTION_LENGTH = 300;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * This run's exchanges oldest-first, which is the order a thread reads in.
     *
     * @param  Builder<RunQuestion>  $query
     */
    #[Scope]
    protected function forActivity(Builder $query, int $activityId): void
    {
        $query->where('activity_id', $activityId)->orderBy('id');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'activity_id' => 'integer',
            'status' => AnalysisStatus::class,
        ];
    }
}
