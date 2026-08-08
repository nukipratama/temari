<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Actions\Run\Story\BuildCardContextAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Run\Ingest\StreamAnalysis;
use App\Services\Run\Metrics\PaceConsistency;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Story\BadgeEvaluator;
use App\Services\Run\Story\RarityScorer;
use App\Services\Run\Story\SpecialMoves;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recomputes every stored run under the CURRENT rules and prints how the
 * distributions move, without writing anything.
 *
 * This only says something while both distributions exist: the stored
 * `stream_summary` was written by whatever rules were live at ingest, and the
 * recomputation applies today's. A re-ingest, or the account reset, overwrites
 * the stored side and the comparison is gone with it.
 */
#[Signature('run:compare-recalibration {--user= : Limit to one user id}')]
#[Description('Compare stored run metrics against a recomputation under current rules. Read-only.')]
class CompareRecalibrationCommand extends Command
{
    public function __construct(
        private readonly StreamAnalysis $streamAnalysis,
        private readonly BuildCardContextAction $contextBuilder,
        private readonly BadgeEvaluator $badgeEvaluator,
        private readonly RarityScorer $rarityScorer,
        private readonly SpecialMoves $specialMoves,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $comparable = $this->comparable();
        if ($comparable === []) {
            $this->error('No activities with stored streams to compare.');

            return self::FAILURE;
        }

        $tallies = [
            'pace' => ['stored' => [], 'recomputed' => []],
            'negative_split' => ['stored' => 0, 'recomputed' => 0],
            'decoupling' => ['stored' => 0, 'recomputed' => 0],
            'rarity' => ['stored' => [], 'recomputed' => []],
            'badges' => ['stored' => 0, 'recomputed' => 0],
            'move' => ['stored' => [], 'recomputed' => []],
        ];
        $scores = [];

        foreach ($comparable as [$activity, $detail, $streams]) {
            $this->tallyOne($activity, $detail, $streams, $tallies, $scores);
        }

        $total = count($comparable);
        $this->line("Compared <info>{$total}</info> runs. Nothing was written.");
        $this->newLine();

        $this->renderBands('Pace consistency', $tallies['pace'], $total);
        $this->renderBands('Rarity tier', $tallies['rarity'], $total);
        $this->renderBands('Special move', $tallies['move'], $total);
        $this->renderFlags($tallies, $total);
        $this->renderScores($scores);

        return self::SUCCESS;
    }

    /** @return list<array{0: Activity, 1: ActivityDetail, 2: array<string, mixed>}> */
    private function comparable(): array
    {
        $userId = $this->option('user');

        $rows = [];
        $activities = Activity::query()
            ->when($userId !== null, fn ($query) => $query->where('user_id', (int) $userId))
            ->whereHas('stream')
            ->whereHas('detail')
            ->with(['detail', 'stream', 'user'])
            ->get();

        foreach ($activities as $activity) {
            $detail = $activity->detail;
            $streams = $activity->stream->data ?? [];
            if ($detail !== null && $streams !== []) {
                $rows[] = [$activity, $detail, $streams];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $streams
     * @param  array<string, mixed>  $tallies
     * @param  list<int>  $scores
     */
    private function tallyOne(Activity $activity, ActivityDetail $detail, array $streams, array &$tallies, array &$scores): void
    {
        $stored = StreamSummary::fromArray($detail->streamSummary());

        $profile = $activity->user->hrProfile();
        $recomputed = StreamSummary::fromArray($this->streamAnalysis->compute(
            $streams,
            $profile['hr_zones'],
            is_array($detail->splits_metric) ? $detail->splits_metric : null,
            $profile['optimal_cadence_spm'],
            $detail->distance,
            $detail->laps(),
        ));

        $this->bump($tallies['pace']['stored'], PaceConsistency::label($stored->paceVariabilitySec()) ?? 'tidak ada');
        $this->bump($tallies['pace']['recomputed'], PaceConsistency::label($recomputed->paceVariabilitySec()) ?? 'tidak ada');

        $tallies['negative_split']['stored'] += $stored->negativeSplit() === true ? 1 : 0;
        $tallies['negative_split']['recomputed'] += $recomputed->negativeSplit() === true ? 1 : 0;
        $tallies['decoupling']['stored'] += $stored->hasDecouplingPct() ? 1 : 0;
        $tallies['decoupling']['recomputed'] += $recomputed->hasDecouplingPct() ? 1 : 0;

        $card = RunCard::query()->where('activity_id', $activity->id)->first();
        $context = ($this->contextBuilder)($activity, $detail);
        $badges = $this->badgeEvaluator->evaluate($detail, $recomputed, $context);
        $score = $this->rarityScorer->score(
            $detail,
            $recomputed,
            $badges,
            $card !== null && $card->pr_set,
            $context,
        );
        $scores[] = $score;

        // An activity can predate card minting, so treat "no card" as its own
        // bucket rather than reading properties off null.
        $tallies['badges']['stored'] += $card === null ? 0 : count((array) $card->badges);
        $tallies['badges']['recomputed'] += count($badges);
        $this->bump($tallies['rarity']['stored'], $card === null ? 'tidak ada kartu' : $card->rarity->value);
        $this->bump($tallies['rarity']['recomputed'], $this->rarityScorer->fromScore($score)->value);
        $this->bump($tallies['move']['stored'], $card === null ? 'tidak ada kartu' : $card->special_move);
        $this->bump($tallies['move']['recomputed'], $this->specialMoves->pick($recomputed, [
            'distance_m' => $detail->distance,
            'pr_set' => $card !== null && $card->pr_set,
            'seed' => $activity->id,
        ]));
    }

    /** @param  array<string, int>  $counter */
    private function bump(array &$counter, string $key): void
    {
        $counter[$key] = ($counter[$key] ?? 0) + 1;
    }

    /** @param  array{stored: array<string, int>, recomputed: array<string, int>}  $bands */
    private function renderBands(string $title, array $bands, int $total): void
    {
        $keys = array_unique([...array_keys($bands['stored']), ...array_keys($bands['recomputed'])]);
        sort($keys);

        $rows = array_map(fn (string $key): array => [
            $key,
            self::share($bands['stored'][$key] ?? 0, $total),
            self::share($bands['recomputed'][$key] ?? 0, $total),
        ], $keys);

        $this->line("<comment>{$title}</comment>");
        $this->table(['Band', 'Stored', 'Recomputed'], $rows);
    }

    /** @param  array<string, mixed>  $tallies */
    private function renderFlags(array $tallies, int $total): void
    {
        $this->line('<comment>Flags</comment>');
        $this->table(['Signal', 'Stored', 'Recomputed'], [
            ['Negative split', self::share($tallies['negative_split']['stored'], $total), self::share($tallies['negative_split']['recomputed'], $total)],
            ['Decoupling reported', self::share($tallies['decoupling']['stored'], $total), self::share($tallies['decoupling']['recomputed'], $total)],
            ['Badges per run (mean)', self::mean($tallies['badges']['stored'], $total), self::mean($tallies['badges']['recomputed'], $total)],
        ]);
    }

    /** @param  list<int>  $scores */
    private function renderScores(array $scores): void
    {
        if ($scores === []) {
            return;
        }

        sort($scores);
        $rows = [];
        foreach ([50, 75, 85, 90, 95] as $percentile) {
            $index = (int) floor(($percentile / 100) * (count($scores) - 1));
            $rows[] = ["p{$percentile}", (string) $scores[$index]];
        }

        $this->line('<comment>Recomputed rarity score percentiles</comment>');
        $this->line('Read the cutoffs off these: the share above a score is the share of cards at that tier or better.');
        $this->table(['Percentile', 'Score'], $rows);
    }

    private static function share(int $count, int $total): string
    {
        $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;

        return "{$count} ({$pct}%)";
    }

    private static function mean(int $sum, int $total): string
    {
        return (string) ($total > 0 ? round($sum / $total, 2) : 0);
    }
}
