<?php

declare(strict_types=1);

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property string $kind
 * @property Carbon $created_at
 */
#[Fillable(['kind', 'created_at'])]
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
            'created_at' => 'datetime',
        ];
    }
}
