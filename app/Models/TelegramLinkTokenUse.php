<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class TelegramLinkTokenUse extends Model
{
    use MassPrunable;

    #[Override]
    protected $primaryKey = 'token_hash';

    #[Override]
    public $incrementing = false;

    #[Override]
    protected $keyType = 'string';

    #[Override]
    public $timestamps = false;

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
