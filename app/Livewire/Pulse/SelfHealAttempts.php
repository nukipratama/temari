<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Per-block self-heal retry budget on the /pulse dashboard. /devtools/ai-usage groups
 * attention buckets by user, type and error; this is the numeric view of where
 * each individual Failed block sits against MAX_SELF_HEAL_ATTEMPTS, so a block
 * one attempt from dead-lettering is visible before it gets there.
 *
 * Not lazy: one grouped count plus a bounded row read, so deferring buys nothing.
 */
class SelfHealAttempts extends Card
{
    private const int LISTED_BLOCKS = 25;

    public function render(): Renderable
    {
        $max = Analysis::MAX_SELF_HEAL_ATTEMPTS;

        $byAttempt = Analysis::query()
            ->where('status', AnalysisStatus::Failed)
            ->selectRaw('attempts, COUNT(*) as total')
            ->groupBy('attempts')
            ->pluck('total', 'attempts');

        $blocks = Analysis::query()
            ->where('status', AnalysisStatus::Failed)
            ->orderByDesc('attempts')
            ->orderByDesc('updated_at')
            ->limit(self::LISTED_BLOCKS)
            ->get(['subject_type', 'subject_id', 'analysis_type', 'attempts', 'error', 'updated_at']);

        $buckets = [];
        for ($attempt = 0; $attempt < $max; $attempt++) {
            $buckets[] = [
                'label' => "{$attempt}/{$max}",
                'count' => (int) ($byAttempt[$attempt] ?? 0),
                'tone' => $attempt === $max - 1 ? 'warn' : 'neutral',
            ];
        }

        $deadLettered = collect($byAttempt)
            ->filter(fn (int $total, int $attempts): bool => $attempts >= $max)
            ->sum();
        $buckets[] = ['label' => 'dead-letter', 'count' => (int) $deadLettered, 'tone' => 'alert'];

        return View::make('livewire.pulse.self-heal-attempts', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'max' => $max,
            'buckets' => $buckets,
            'blocks' => $blocks,
            'severity' => match (true) {
                $deadLettered > 0 => 'alert',
                ($byAttempt[$max - 1] ?? 0) > 0 => 'warn',
                default => 'ok',
            },
        ]);
    }
}
