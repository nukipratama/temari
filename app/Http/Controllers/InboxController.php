<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification centre. Rows are flattened here rather than shipped with
 * their raw `payload` blob, so the page never reaches into untyped JSON: each
 * row arrives with the deep link, the replay ids, and nothing else.
 *
 * `?item=` is the deep-link target a push tap lands on. The page it sits on is
 * resolved from its own position in the list, so the row is on screen even when
 * it has already been paged past.
 */
final class InboxController extends Controller
{
    private const int PER_PAGE = 20;

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $focusId = $request->integer('item') ?: null;

        $notifications = $user->inboxNotifications()
            ->reorder()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: $this->pageFor($user, $focusId))
            ->withQueryString()
            ->through($this->present(...));

        return Inertia::render('Inbox', [
            'notifications' => $notifications,
            'focusId' => $focusId,
        ]);
    }

    /**
     * @return array{id: int, kind: string, title: string, body: string|null, created_at: string|null, read_at: string|null, url: string|null, run_card_id: int|null, rarity: string|null}
     */
    private function present(InboxNotification $row): array
    {
        $payload = $row->payload ?? [];

        return [
            'id' => $row->id,
            'kind' => $row->kind->value,
            'title' => $row->title,
            'body' => $row->body,
            'created_at' => $row->created_at?->toIso8601String(),
            'read_at' => $row->read_at?->toIso8601String(),
            'url' => self::stringOrNull($payload['url'] ?? null),
            'run_card_id' => self::intOrNull($payload['run_card_id'] ?? null),
            'rarity' => self::stringOrNull($payload['rarity'] ?? null),
        ];
    }

    /** Which page the deep-linked row sits on, or null to use the request's own. */
    private function pageFor(User $user, ?int $focusId): ?int
    {
        if ($focusId === null) {
            return null;
        }

        $newer = $user->inboxNotifications()->where('id', '>', $focusId)->count();

        return intdiv($newer, self::PER_PAGE) + 1;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
