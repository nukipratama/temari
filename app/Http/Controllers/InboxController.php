<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NotificationKind;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification centre. Rows are flattened here rather than shipped with
 * their raw `payload` blob, so the page never reaches into untyped JSON: each
 * row arrives with the deep link, the replay ids, and nothing else.
 *
 * The list is a growing window rather than a two-way pager: "load older" asks
 * for `?shown=` more rows and the server says whether anything sits behind them
 * (P3 — the prototype's own reveal swaps a hardcoded array).
 *
 * `?item=` is the deep-link target a push tap lands on. The window is widened
 * far enough to contain it, so the row is on screen even when it sits well
 * behind the first page.
 */
final class InboxController extends Controller
{
    /** Rows in the first window, and rows each "load older" press adds. */
    public const int PER_PAGE = 20;

    private const int MAX_SHOWN = 500;

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $focusId = $request->integer('item') ?: null;
        $shown = $this->shownFor($user, $request->integer('shown'), $focusId);

        $rows = $user->inboxNotifications()
            ->reorder()
            ->orderByDesc('id')
            ->limit($shown + 1)
            ->get();

        $hasOlder = $rows->count() > $shown;
        $rows = $rows->take($shown);

        $runStats = $this->runStatsFor($rows);

        return Inertia::render('Inbox', [
            'notifications' => $rows
                ->map(fn (InboxNotification $row): array => $this->present($row, $runStats))
                ->values()
                ->all(),
            'shown' => $shown,
            'hasOlder' => $hasOlder,
            'focusId' => $focusId,
        ]);
    }

    /**
     * @param  array<int, array{distance_m: float|null, moving_time_s: int|null}>  $runStats
     * @return array{id: int, kind: string, title: string, body: string|null, created_at: string|null, read_at: string|null, url: string|null, run_card_id: int|null, rarity: string|null, distance_m: float|null, moving_time_s: int|null}
     */
    private function present(InboxNotification $row, array $runStats): array
    {
        $payload = $row->payload ?? [];
        $activityId = self::activityId($payload);
        $stats = $activityId === null
            ? null
            : ($runStats[$activityId] ?? null);

        return [
            'id' => $row->id,
            'kind' => $row->kind->value,
            'title' => $row->title,
            'body' => $row->body,
            'created_at' => $row->created_at?->toIso8601String(),
            'read_at' => $row->read_at?->toIso8601String(),
            'url' => self::stringOrNull($payload['url'] ?? null),
            'run_card_id' => self::intOrNull($payload['run_card_id'] ?? null),
            'rarity' => $row->kind === NotificationKind::Unlock
                ? self::unlockRarity($payload)
                : self::stringOrNull($payload['rarity'] ?? null),
            'distance_m' => $stats['distance_m'] ?? null,
            'moving_time_s' => $stats['moving_time_s'] ?? null,
        ];
    }

    /**
     * The distance/pace the prototype's post-run rows carry as stat chips, in
     * one query over the whole window rather than a lookup per row.
     *
     * @param  Collection<int, InboxNotification>  $rows
     * @return array<int, array{distance_m: float|null, moving_time_s: int|null}>
     */
    private function runStatsFor(Collection $rows): array
    {
        $activityIds = $rows
            ->filter(fn (InboxNotification $row): bool => $row->kind === NotificationKind::PostRun)
            ->map(fn (InboxNotification $row): ?int => self::activityId($row->payload ?? []))
            ->filter()
            ->values()
            ->all();

        if ($activityIds === []) {
            return [];
        }

        return ActivityDetail::query()
            ->whereIn('activity_id', $activityIds)
            ->get(['activity_id', 'distance', 'moving_time'])
            ->keyBy('activity_id')
            ->map(fn (ActivityDetail $detail): array => [
                'distance_m' => $detail->distance,
                'moving_time_s' => $detail->moving_time,
            ])
            ->all();
    }

    /**
     * How many rows the window holds: the requested size, widened when needed to
     * reach a deep-linked row that sits behind it.
     */
    private function shownFor(User $user, int $requested, ?int $focusId): int
    {
        $shown = $requested > 0
            ? (int) ceil($requested / self::PER_PAGE) * self::PER_PAGE
            : self::PER_PAGE;

        if ($focusId !== null) {
            $newer = $user->inboxNotifications()->where('id', '>', $focusId)->count();
            $shown = max($shown, (intdiv($newer, self::PER_PAGE) + 1) * self::PER_PAGE);
        }

        return min($shown, self::MAX_SHOWN);
    }

    /**
     * The rarity tier of an unlock, read from the catalog by its key so rows
     * recorded before rarity was surfaced still render a badge (P12).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function unlockRarity(array $payload): ?string
    {
        $key = self::stringOrNull($payload['unlock_key'] ?? null);
        if ($key === null) {
            return null;
        }

        $catalog = config('temari_unlocks', []);
        $definition = is_array($catalog) ? ($catalog[$key] ?? null) : null;

        return is_array($definition) ? self::stringOrNull($definition['rarity'] ?? null) : null;
    }

    /** @param  array<string, mixed>  $payload */
    private static function activityId(array $payload): ?int
    {
        return self::intOrNull($payload['activity_id'] ?? null);
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
