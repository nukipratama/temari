<?php

declare(strict_types=1);

namespace App\Models\Analytics;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * One operator action taken from /devtools. Write through
 * {@see \App\Services\Devtools\DevtoolsActionRecorder}, which resolves the actor
 * from the current request.
 *
 * @property int $id
 * @property string $actor
 * @property string $action
 * @property int|null $user_id  The athlete acted on, when the action names one.
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 */
#[Fillable(['actor', 'action', 'user_id', 'payload', 'created_at'])]
class DevtoolsAction extends Model
{
    #[Override]
    public $timestamps = false;

    #[Override]
    protected $connection = 'analytics';

    #[Override]
    protected $table = 'devtools_actions';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
