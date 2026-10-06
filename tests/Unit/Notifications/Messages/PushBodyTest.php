<?php

declare(strict_types=1);

use App\Notifications\Messages\PushBody;
use Minishlink\WebPush\Encryption;
use NotificationChannels\WebPush\WebPushMessage;

it('keeps a short narration whole, trimmed', function (): void {
    expect(PushBody::excerpt("  easy 5k, nothing clever.  \n"))->toBe('easy 5k, nothing clever.');
});

it('cuts at the last sentence boundary within the cap', function (): void {
    $first = str_repeat('a', 100) . '.';
    $second = ' ' . str_repeat('b', 60) . '!';
    $third = ' ' . str_repeat('c', 60) . '?';

    expect(PushBody::excerpt($first . $second . $third))->toBe($first . $second);
});

it('takes a sentence that ends exactly on the cap', function (): void {
    $sentence = str_repeat('a', PushBody::MAX_CHARS - 1) . '.';

    expect(PushBody::excerpt($sentence . ' more after it.'))->toBe($sentence);
});

it('does not treat a decimal point as a sentence boundary', function (): void {
    $narration = 'you ran 10.5 km ' . str_repeat('steady ', 30) . 'and that was it.';

    expect(PushBody::excerpt($narration))->toEndWith('…')
        ->not->toBe('you ran 10.');
});

it('cuts a first sentence longer than the cap at a word boundary and appends an ellipsis', function (): void {
    $narration = str_repeat('steady, ', 40) . 'done.';

    $excerpt = PushBody::excerpt($narration);

    expect($excerpt)->toEndWith('steady…')
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(PushBody::MAX_CHARS);
});

it('cuts a single unbroken word at the cap', function (): void {
    $excerpt = PushBody::excerpt(str_repeat('🔥', 300));

    expect($excerpt)->toBe(str_repeat('🔥', PushBody::MAX_CHARS - 1) . '…');
});

it('counts characters, not bytes, so emoji never split', function (): void {
    $excerpt = PushBody::excerpt(str_repeat('🔥 ', 200));

    expect(mb_check_encoding($excerpt, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(PushBody::MAX_CHARS);
});

it('sets the excerpt as the message body', function (): void {
    $message = PushBody::attach(new WebPushMessage()->title('Your run is in'), 'easy 5k, nothing clever.');

    expect($message->toArray()['body'])->toBe('easy 5k, nothing clever.');
});

it('shortens the body further until the encoded payload fits', function (): void {
    $message = new WebPushMessage()
        ->title('Your run is in')
        ->data(['url' => 'https://temari.test/' . str_repeat('x', 3800)]);

    PushBody::attach($message, str_repeat('🔥✨ ', 200));
    $payload = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

    expect(strlen($payload))->toBeLessThanOrEqual(Encryption::MAX_PAYLOAD_LENGTH)
        ->and($message->toArray()['body'])->not->toBe('');
});
