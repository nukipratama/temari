<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\Services\AI\AnalysisOrigin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $kind
 * @property AnalysisOrigin $origin  What started the call, as opposed to which narrator answered it.
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property int $cached_tokens  Subset of prompt_tokens served from the prompt cache.
 * @property int $reasoning_tokens  Subset of completion_tokens spent thinking, billed as output.
 * @property int $steps  Model turns in the run; above 1 whenever the agent loop called tools.
 * @property string|null $model
 * @property string|null $user_name  Captured when the user is deleted; null while they exist.
 * @property int|null $strava_athlete_id  Captured when the user is deleted; null while they exist.
 * @property int|null $latency_ms
 * @property bool $truncated
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'user_name', 'strava_athlete_id', 'kind', 'origin', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens', 'steps', 'model', 'latency_ms', 'truncated', 'created_at'])]
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
            'user_id' => 'integer',
            'origin' => AnalysisOrigin::class,
            'strava_athlete_id' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'steps' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
            'truncated' => 'boolean',
        ];
    }
}
