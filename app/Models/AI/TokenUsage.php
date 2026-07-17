<?php

declare(strict_types=1);

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $kind
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property string|null $model
 * @property int|null $latency_ms
 * @property bool $truncated
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'kind', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'model', 'latency_ms', 'truncated', 'created_at'])]
class TokenUsage extends Model
{
    #[Override]
    public $timestamps = false;

    // Lives in the dedicated analytics schema so `migrate:fresh` of the app DB
    // can't wipe cost history. See config/database.php `analytics` connection.
    #[Override]
    protected $connection = 'analytics';

    #[Override]
    protected $table = 'ai_token_usages';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'truncated' => 'boolean',
        ];
    }
}
