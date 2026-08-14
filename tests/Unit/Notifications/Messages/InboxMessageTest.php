<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Enums\NotificationKind;
use App\Notifications\Messages\InboxMessage;

it('defaults to a subject-less, payload-less, self-keyed message', function (): void {
    $message = new InboxMessage(kind: NotificationKind::Test, title: 'Test notification');

    expect($message->body)->toBeNull()
        ->and($message->payload)->toBe([])
        ->and($message->subjectType)->toBeNull()
        ->and($message->subjectId)->toBeNull()
        ->and($message->dedupeKey)->toBeNull();
});

it('carries the replay payload and subject it was built with', function (): void {
    $message = new InboxMessage(
        kind: NotificationKind::PostRun,
        title: 'Your run is in.',
        body: 'steady one.',
        payload: ['run_card_id' => 9],
        subjectType: Activity::class,
        subjectId: 42,
        dedupeKey: 'analysis:7',
    );

    expect($message->kind)->toBe(NotificationKind::PostRun)
        ->and($message->payload)->toBe(['run_card_id' => 9])
        ->and($message->subjectId)->toBe(42)
        ->and($message->dedupeKey)->toBe('analysis:7');
});
