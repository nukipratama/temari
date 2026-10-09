<?php

declare(strict_types=1);

use App\Notifications\Messages\TelegramMessage;

it('carries the text with text-only defaults', function (): void {
    $message = new TelegramMessage(text: 'Halo');

    expect($message->text)->toBe('Halo')
        ->and($message->deliveryKey)->toBeNull();
});

it('carries a delivery key when given', function (): void {
    $message = new TelegramMessage(text: 'Halo', deliveryKey: 42);

    expect($message->deliveryKey)->toBe(42);
});
