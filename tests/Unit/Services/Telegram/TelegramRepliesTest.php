<?php

declare(strict_types=1);

use App\Services\Telegram\TelegramReplies;

it('names the account in the welcome message', function (): void {
    expect(TelegramReplies::welcome('Budi'))->toContain('Budi');
});

it('keeps every reply free of em-dashes and en-dashes', function (): void {
    $replies = [
        TelegramReplies::welcome('Budi'),
        TelegramReplies::expired(),
        TelegramReplies::generic(),
        TelegramReplies::disconnected(),
    ];

    foreach ($replies as $reply) {
        expect($reply)->not->toContain('—')
            ->and($reply)->not->toContain('–');
    }
});
