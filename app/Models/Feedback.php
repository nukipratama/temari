<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * A runner saying "this is wrong" about one plan day or one narration. Read by
 * the owner in tinker; there is no admin surface and nothing reads it at
 * runtime, so the row only ever needs when it was written.
 *
 * @property int $id
 * @property int $user_id
 * @property FeedbackSubject $subject_type
 * @property int $subject_id
 * @property FeedbackReason|null $reason
 * @property string|null $note
 * @property Carbon|null $created_at
 */
#[Fillable([
    'user_id',
    'subject_type',
    'subject_id',
    'reason',
    'note',
])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    /** Longest note the column and the form accept. */
    public const int MAX_NOTE_LENGTH = 280;

    public const UPDATED_AT = null;

    #[Override]
    protected $table = 'feedback';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'subject_type' => FeedbackSubject::class,
            'subject_id' => 'integer',
            'reason' => FeedbackReason::class,
            'created_at' => 'datetime',
        ];
    }
}
