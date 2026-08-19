<?php

declare(strict_types=1);

namespace App\Notifications\Messages;

use App\Enums\NotificationKind;

/**
 * One inbox row, as a notification describes it. `payload` is what a reveal or a
 * celebration needs to be re-experienced later, not a snapshot of how it looked:
 * ids the frontend can re-arm through the existing replay endpoints, plus the
 * handful of fields the list needs without a join.
 *
 * A null `dedupeKey` falls back to the notification's own id in
 * {@see \App\Notifications\Channels\InAppChannel}, which is stable across a
 * queued retry. Supply one to also dedupe across separate dispatches for the
 * same subject.
 */
final readonly class InboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public NotificationKind $kind,
        public string $title,
        public ?string $body = null,
        public array $payload = [],
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $dedupeKey = null,
    ) {
    }
}
