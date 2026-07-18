<?php

declare(strict_types=1);

use App\Notifications\Messages\TelegramMessage;

it('carries the text with text-only defaults', function (): void {
    $message = new TelegramMessage(text: 'Halo');

    expect($message->text)->toBe('Halo')
        ->and($message->photoPng)->toBeNull()
        ->and($message->deliveryKey)->toBeNull()
        ->and($message->force)->toBeFalse();
});

it('carries a photo, delivery key, and force flag when given', function (): void {
    $message = new TelegramMessage(text: 'Caption', photoPng: 'png-bytes', deliveryKey: 42, force: true);

    expect($message->photoPng)->toBe('png-bytes')
        ->and($message->deliveryKey)->toBe(42)
        ->and($message->force)->toBeTrue();
});
