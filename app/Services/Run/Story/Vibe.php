<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use Illuminate\Database\Eloquent\Collection;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * Resolves the runner's current vibe state by gathering multi-signal context
 * for `VibeMatrix` to score.
 *
 * Code-side enum keys stay English for greppability (`Vibe::BOUNCY`); display
 * labels live in `LABELS` and are Indonesian per the project voice convention.
 */
class Vibe
{
    public const BOUNCY = 'bouncy';

    public const STEADY = 'steady';

    public const WORN_DOWN = 'worn_down';

    public const COOKED = 'cooked';

    public const FRESH = 'fresh';

    public const STRETCHED_THIN = 'stretched_thin';

    public const PUMPED = 'pumped';

    public const HIBERNATING = 'hibernating';

    /** Display labels (Indonesian). */
    public const array LABELS = [
        self::BOUNCY => 'Lincah',
        self::STEADY => 'Stabil',
        self::WORN_DOWN => 'Loyo',
        self::COOKED => 'Gosong',
        self::FRESH => 'Segar',
        self::STRETCHED_THIN => 'Tipis',
        self::PUMPED => 'Membara',
        self::HIBERNATING => 'Hibernasi',
    ];

    /** Emoji partner per vibe — feeds the Blade component. */
    public const array EMOJI = [
        self::BOUNCY => '🦘',
        self::STEADY => '🚶',
        self::WORN_DOWN => '🥵',
        self::COOKED => '🍳',
        self::FRESH => '🌧️',
        self::STRETCHED_THIN => '🧵',
        self::PUMPED => '💥',
        self::HIBERNATING => '🐻',
    ];

    /** Decoupling lookback in days. */
    private const int DECOUPLING_WINDOW_DAYS = 28;

    /** PR lookback in days for the "celebration" vibe. */
    private const int PR_WINDOW_DAYS = 14;

    public function __construct(
        private readonly TrainingLoad $trainingLoad,
        private readonly VibeMatrix $matrix,
    ) {
    }

    public function current(User $user, ?Carbon $asOf = null): string
    {
        $asOf ??= Carbon::today();

        $load = $this->trainingLoad->summary($user, $asOf);

        $daysSinceRun = $this->daysSinceLastRun($user, $asOf);
        $recentPr = $this->hasRecentPr($user, $asOf);
        $decoupling = $this->avgDecouplingPct($user, $asOf);

        return $this->matrix->pick([
            'form' => (float) ($load['form'] ?? 0.0),
            'form_status' => (string) ($load['form_status'] ?? 'optimal'),
            'days_since_run' => $daysSinceRun,
            'recent_pr' => $recentPr,
            'decoupling_avg' => $decoupling,
        ]);
    }

    public static function label(string $vibe): string
    {
        return self::LABELS[$vibe] ?? $vibe;
    }

    public static function emoji(string $vibe): string
    {
        return self::EMOJI[$vibe] ?? '';
    }

    private function daysSinceLastRun(User $user, Carbon $asOf): ?int
    {
        $lastRun = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->orderByDesc('start_date_local')
            ->value('start_date_local');

        if ($lastRun === null) {
            return null;
        }

        return (int) Carbon::parse($lastRun)->startOfDay()->diffInDays($asOf->copy()->startOfDay());
    }

    private function hasRecentPr(User $user, Carbon $asOf): bool
    {
        return PersonalRecord::query()
            ->where('user_id', $user->id)
            ->where('set_at', '>=', $asOf->copy()->subDays(self::PR_WINDOW_DAYS))
            ->exists();
    }

    /**
     * Average decoupling_pct across the user's runs in the last
     * DECOUPLING_WINDOW_DAYS days. Null when the user has no runs with
     * decoupling data — VibeMatrix treats null as "no opinion".
     */
    private function avgDecouplingPct(User $user, Carbon $asOf): ?float
    {
        $cutoff = $asOf->copy()->subDays(self::DECOUPLING_WINDOW_DAYS);

        /** @var Collection<int, ActivityDetail> $rows */
        $rows = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('start_date_local', '>=', $cutoff)
            ->whereNotNull('stream_summary')
            ->get(['stream_summary']);

        $samples = [];
        foreach ($rows as $row) {
            $summary = is_array($row->stream_summary) ? $row->stream_summary : [];
            if (isset($summary['decoupling_pct'])) {
                $samples[] = (float) $summary['decoupling_pct'];
            }
        }

        if ($samples === []) {
            return null;
        }

        return array_sum($samples) / count($samples);
    }
}
