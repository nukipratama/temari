<?php

declare(strict_types=1);

use App\Notifications\Messages\TelegramMessage;

it('carries the text with text-only defaults', function (): void {
    $message = new TelegramMessage(text: 'Halo');

    expect($message->text)->toBe('Halo')
        ->and($message->photoPng)->toBeNull()
        ->and($message->deliveryKey)->toBeNull();
});

it('carries a photo and delivery key when given', function (): void {
    $message = new TelegramMessage(text: 'Caption', photoPng: 'png-bytes', deliveryKey: 42);

    expect($message->photoPng)->toBe('png-bytes')
        ->and($message->deliveryKey)->toBe(42);
});
