<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Override;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

#[WithoutIncrementing]
#[WithoutTimestamps]
class TelegramLinkTokenUse extends Model
{
    use MassPrunable;

    #[Override]
    protected $primaryKey = 'token_hash';

    #[Override]
    protected $keyType = 'string';

    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
