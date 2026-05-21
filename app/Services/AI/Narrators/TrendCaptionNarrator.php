<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

class TrendCaptionNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1 kalimat caption maksimal 25 kata untuk chart Fitness/Form + Weekly
        Volume.

        Fokus ke tren (naik, turun, plateau, peak). Sebutkan konteks bila ada (PR
        week, recovery week, taper).
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function generate(User $user, Carbon $asOf): string
    {
        $weeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        $decoded = $this->caller->call(
            kind: 'trend_caption',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'as_of' => $asOf->toDateString(),
                'load_today' => $this->trainingLoad->summary($user, $asOf),
                'weeks' => $weeks->map(fn (WeeklySnapshot $w): array => [
                    'ending' => $w->week_ending->toDateString(),
                    'distance_km' => $w->distance_km,
                    'trimp' => $w->weekly_trimp,
                    'ctl_42d' => $w->ctl_42d,
                    'atl_7d' => $w->atl_7d,
                    'form' => $w->form,
                    'status' => $w->form_status,
                ])->all(),
            ],
            schemaName: 'TemariTrendCaption',
            requiredKeys: ['caption'],
            options: new ChatCallOptions(temperature: 0.7, userId: $user->id, maxTokens: 600),
        );

        return (string) $decoded['caption'];
    }
}
