<?php

declare(strict_types=1);

use App\Models\TelegramLinkTokenUse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('prunes claims after their token expiry', function (): void {
    $expiredHash = hash('sha256', random_bytes(32));
    $activeHash = hash('sha256', random_bytes(32));
    DB::table('telegram_link_token_uses')->insert([
        'token_hash' => $expiredHash,
        'expires_at' => now()->subMinute(),
    ]);
    DB::table('telegram_link_token_uses')->insert([
        'token_hash' => $activeHash,
        'expires_at' => now()->addMinute(),
    ]);

    expect(new TelegramLinkTokenUse()->prunable()->whereKey($expiredHash)->exists())->toBeTrue()
        ->and(new TelegramLinkTokenUse()->prunable()->whereKey($activeHash)->exists())->toBeFalse();
});
