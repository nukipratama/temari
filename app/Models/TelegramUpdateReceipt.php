<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;

class TelegramUpdateReceipt extends Model
{
    use MassPrunable;

    #[Override]
    protected $primaryKey = 'update_id';

    #[Override]
    public $incrementing = false;

    #[Override]
    protected $keyType = 'int';

    #[Override]
    public $timestamps = false;

    public static function record(int $updateId): bool
    {
        return static::query()->insertOrIgnore([
            'update_id' => $updateId,
            'received_at' => now(),
        ]) === 1;
    }

    public static function forget(int $updateId): void
    {
        static::query()->whereKey($updateId)->delete();
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('received_at', '<', now()->subDays(7));
    }
}
