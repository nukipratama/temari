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
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property string|null $model
 * @property Carbon $created_at
 */
#[Fillable(['kind', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'model', 'created_at'])]
class TokenUsage extends Model
{
    public $timestamps = false;

    protected $table = 'ai_token_usages';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
