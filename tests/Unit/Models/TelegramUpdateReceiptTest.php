<?php

declare(strict_types=1);

use App\Models\TelegramUpdateReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('records each update once and prunes receipts older than seven days', function (): void {
    $expiredId = random_int(10_000_000, 900_000_000);
    $currentId = $expiredId + 1;
    DB::table('telegram_update_receipts')->insert([
        'update_id' => $expiredId,
        'received_at' => now()->subDays(8),
    ]);

    expect(TelegramUpdateReceipt::record($currentId))->toBeTrue()
        ->and(TelegramUpdateReceipt::record($currentId))->toBeFalse();

    expect(new TelegramUpdateReceipt()->prunable()->whereKey($expiredId)->exists())->toBeTrue()
        ->and(new TelegramUpdateReceipt()->prunable()->whereKey($currentId)->exists())->toBeFalse();
});
