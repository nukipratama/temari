<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\TokenUsageReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TokenUsageController extends Controller
{
    public function __construct(
        private readonly TokenUsageReport $report,
        private readonly AnalysisService $analysisService,
    ) {
    }

    public function show(Request $request): Response
    {
        $validated = $request->validate([
            'range' => 'sometimes|in:today,7d,30d,month,all,custom',
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d',
            'kind' => 'sometimes|string',
        ]);

        [$range, $from, $to] = $this->resolveRange($validated);
        $kind = $validated['kind'] ?? null;

        $report = $this->report->build($from, $to, $kind, includePrevious: $range !== 'all');

        return Inertia::render('AiUsage', [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kind' => $kind,
            'totals' => $report['totals'],
            'previousTotals' => $report['previousTotals'],
            'byKind' => $report['byKind'],
            'byUser' => $report['byUser'],
            'byDeployment' => $report['byDeployment'],
            'daily' => $report['daily'],
            'availableKinds' => $report['availableKinds'],
            'budget' => $report['budget'],
            'deadLettered' => $this->deadLetteredByUser(),
        ]);
    }

    /**
     * Re-arm and re-dispatch every dead-lettered block for one user (the blocks
     * ai:self-heal gave up on). Resetting attempts to 0 restores the self-heal
     * budget, and invalidate:false re-dispatches without re-billing any Done
     * siblings. Cost-safe even mid-cap: the job-level guard reverts to Pending.
     *
     * Binds by raw id rather than implicit `User` model binding: a hard-deleted
     * user's user-keyed `ai_analyses` rows survive (no FK), so their dead-letter
     * group must stay retryable even when the `users` row is gone. The user
     * model is only needed for the flash message's display name.
     */
    public function retryFailed(int $userId): RedirectResponse
    {
        $rows = Analysis::query()->deadLettered()->get();
        $ownerIds = Analysis::ownerIdsForRows($rows);
        $matching = $rows->filter(fn (Analysis $row): bool => ($ownerIds[$row->id] ?? null) === $userId);

        foreach ($matching as $row) {
            $row->update(['attempts' => 0]);
            $this->analysisService->request(
                subjectOrType: $row->subject_type,
                subjectId: $row->subject_id,
                type: $row->analysis_type,
                discriminator: $row->discriminator,
                invalidate: false,
            );
        }

        $userName = User::query()->find($userId)?->name ?? "User #{$userId}";

        return back()->with('info', "Mencoba ulang {$matching->count()} blok untuk {$userName}.");
    }

    /**
     * Dead-lettered blocks grouped by their owning user, for the "Perlu
     * perhatian" panel. The ops user retries per user, not per block.
     *
     * @return list<array{user_id:int, user_name:string, count:int, blocks:list<array{type:string, error:string|null, failed_at:string}>}>
     */
    private function deadLetteredByUser(): array
    {
        $rows = Analysis::query()->deadLettered()->orderByDesc('updated_at')->get();
        $ownerIds = Analysis::ownerIdsForRows($rows);

        /** @var array<int, list<Analysis>> $byUser */
        $byUser = [];
        foreach ($rows as $row) {
            $userId = $ownerIds[$row->id] ?? null;
            if ($userId !== null) {
                $byUser[$userId][] = $row;
            }
        }

        /** @var array<int, string> $names */
        $names = User::query()->whereIn('id', array_keys($byUser))->pluck('name', 'id')->all();

        $groups = [];
        foreach ($byUser as $userId => $userRows) {
            $groups[] = [
                'user_id' => $userId,
                'user_name' => $names[$userId] ?? "User #{$userId}",
                'count' => count($userRows),
                'blocks' => array_map(fn (Analysis $row): array => [
                    'type' => $row->analysis_type->value,
                    'error' => $row->error,
                    'failed_at' => $row->updated_at->toIso8601String(),
                ], $userRows),
            ];
        }

        return $groups;
    }

    /**
     * Resolve the relative range token to concrete dates on every request, so
     * preset links stay correct as the calendar rolls (a baked-in absolute
     * `to` would silently point at yesterday). Bare requests default to the
     * rolling last 7 days; legacy absolute `from`+`to` links (no `range`) map
     * to a `custom` range for back-compat.
     *
     * @param  array{range?:string, from?:string, to?:string}  $validated
     * @return array{0:string, 1:Carbon, 2:Carbon}
     */
    private function resolveRange(array $validated): array
    {
        $hasCustomDates = isset($validated['from'], $validated['to']);
        $range = $validated['range'] ?? ($hasCustomDates ? 'custom' : '7d');
        if ($range === 'custom' && ! $hasCustomDates) {
            $range = '7d';
        }

        $sevenDaysAgo = Carbon::today()->subDays(6)->startOfDay();

        [$from, $to] = match ($range) {
            'today' => [Carbon::today()->startOfDay(), Carbon::now()],
            '7d' => [$sevenDaysAgo, Carbon::now()],
            '30d' => [Carbon::today()->subDays(29)->startOfDay(), Carbon::now()],
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()],
            'all' => [Carbon::createFromTimestamp(0), Carbon::now()],
            'custom' => [Carbon::parse($validated['from'])->startOfDay(), Carbon::parse($validated['to'])->endOfDay()],
            default => [$sevenDaysAgo, Carbon::now()],
        };

        return [$range, $from, $to];
    }
}
