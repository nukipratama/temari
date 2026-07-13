<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use App\Services\AI\TokenUsageReport;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
            'failedUnderBudget' => $this->failedUnderBudgetByUser(),
            'nyangkut' => $this->nyangkutByUser(),
        ]);
    }

    /**
     * One-shot post-outage recovery: re-arm every dead-lettered block across users
     * and run the full self-heal sweep immediately, instead of an N-click,
     * up-to-60-min-cadence scavenger hunt. Admin-gated by the route.
     */
    public function recover(): RedirectResponse
    {
        Artisan::call('ai:recover');

        return back()->with('info', 'Pemulihan dijalankan: blok dead-letter di-coba ulang dan self-heal langsung disapu.');
    }

    /**
     * Re-arm and re-dispatch every Failed block for one user, whether dead-lettered
     * or still under budget. Resetting attempts to 0 restores the self-heal budget,
     * and invalidate:false re-dispatches without re-billing any Done siblings.
     * Cost-safe even mid-cap: the job-level guard reverts to Pending. Powers the
     * re-arm button on both the "Perlu perhatian" (dead-letter) and "Failed, belum
     * menyerah" panels.
     *
     * Binds by raw id rather than implicit `User` model binding: a hard-deleted
     * user's user-keyed `ai_analyses` rows survive (no FK), so their group must
     * stay retryable even when the `users` row is gone. The user model is only
     * needed for the flash message's display name.
     */
    public function retryFailed(int $userId): RedirectResponse
    {
        $rows = Analysis::query()->where('status', AnalysisStatus::Failed)->get();
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
        return $this->groupByUser(Analysis::query()->deadLettered()->orderByDesc('updated_at')->get());
    }

    /**
     * Failed blocks still under the retry budget (self-heal will keep trying), for
     * the "Failed, belum menyerah" panel. Visible before a user complains, with the
     * same per-user re-arm button to force a resume now instead of waiting.
     *
     * @return list<array{user_id:int, user_name:string, count:int, blocks:list<array{type:string, error:string|null, failed_at:string}>}>
     */
    private function failedUnderBudgetByUser(): array
    {
        $rows = Analysis::query()
            ->where('status', AnalysisStatus::Failed)
            ->where('attempts', '<', Analysis::MAX_SELF_HEAL_ATTEMPTS)
            ->orderByDesc('updated_at')
            ->get();

        return $this->groupByUser($rows);
    }

    /**
     * "Nyangkut": Pending/Queued rows stuck past {@see Analysis::STALE_IN_FLIGHT_HOURS}
     * (a Pending that never dispatched, or a Queued whose job was lost), grouped per
     * user. Window-gated recap rows for the still-open week/month are excluded: their
     * Pending is inert by design (dispatch is deferred until the period closes), so
     * they are a "recap incoming" signal, not a stuck block.
     *
     * @return list<array{user_id:int, user_name:string, count:int, blocks:list<array{type:string, error:string|null, failed_at:string}>}>
     */
    private function nyangkutByUser(): array
    {
        $threshold = Carbon::now()->subHours(Analysis::STALE_IN_FLIGHT_HOURS);

        $rows = Analysis::query()
            ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued])
            ->where(function ($query) use ($threshold): void {
                $query
                    ->where('queued_at', '<', $threshold)
                    ->orWhere(function ($fallback) use ($threshold): void {
                        $fallback->whereNull('queued_at')->where('created_at', '<', $threshold);
                    });
            })
            ->orderBy('created_at')
            ->get();

        return $this->groupByUser($this->rejectOpenPeriodRecaps($rows));
    }

    /**
     * Drop recap rows whose period is still open (weekly for the current week,
     * monthly for the current month), so the inert deferred-recap Pending never
     * reads as "stuck". Small candidate set (already age-filtered), so the weekly
     * snapshot lookup is a single batched query.
     *
     * @param  EloquentCollection<int, Analysis>  $rows
     * @return EloquentCollection<int, Analysis>
     */
    private function rejectOpenPeriodRecaps(EloquentCollection $rows): EloquentCollection
    {
        $lastClosedMonth = RecapPeriod::lastClosedMonth();
        $lastClosedWeekEnding = RecapPeriod::lastClosedWeekEnding();

        $weeklySubjectIds = $rows
            ->where('analysis_type', AnalysisType::WeeklyRecap)
            ->pluck('subject_id')
            ->unique()
            ->all();

        /** @var array<int, string> $weekEndings */
        $weekEndings = $weeklySubjectIds === []
            ? []
            : WeeklySnapshot::query()
                ->whereIn('id', $weeklySubjectIds)
                ->pluck('week_ending', 'id')
                ->map(fn (Carbon $weekEnding): string => $weekEnding->toDateString())
                ->all();

        return $rows->reject(function (Analysis $row) use ($lastClosedMonth, $lastClosedWeekEnding, $weekEndings): bool {
            if ($row->analysis_type === AnalysisType::MonthlyRecap) {
                return (string) $row->discriminator > $lastClosedMonth;
            }

            if ($row->analysis_type === AnalysisType::WeeklyRecap) {
                $weekEnding = $weekEndings[$row->subject_id] ?? null;

                return $weekEnding !== null && $weekEnding > $lastClosedWeekEnding;
            }

            return false;
        });
    }

    /**
     * Group Analysis rows by their owning user into the per-user panel shape shared
     * by the dead-letter, failed-under-budget and nyangkut buckets.
     *
     * @param  EloquentCollection<int, Analysis>  $rows
     * @return list<array{user_id:int, user_name:string, count:int, blocks:list<array{type:string, error:string|null, failed_at:string}>}>
     */
    private function groupByUser(EloquentCollection $rows): array
    {
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
