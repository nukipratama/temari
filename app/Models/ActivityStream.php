<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use Database\Factories\ActivityStreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Large blob — never eager-load in list queries.
 *
 * @property int $id
 * @property int $activity_id
 * @property array<string, mixed> $data
 * @property-read Activity $activity
 */
#[Fillable(['activity_id', 'data'])]
class ActivityStream extends Model
{
    /** @use HasFactory<ActivityStreamFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * `data` column is longText (not JSON) — cast handles encode/decode.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activity_id' => 'integer',
            'data' => 'array',
        ];
    }
}
