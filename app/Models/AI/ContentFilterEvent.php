<?php

declare(strict_types=1);

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int|null $user_id  The athlete whose narration tripped the filter; a bare integer, since `users` lives on the default connection.
 * @property string $kind
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'kind', 'created_at'])]
class ContentFilterEvent extends Model
{
    #[Override]
    public $timestamps = false;

    #[Override]
    protected $connection = 'analytics';

    #[Override]
    protected $table = 'ai_content_filter_events';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
