<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

class TrendCaptionNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1 kalimat caption max 25 kata untuk
chart Fitness/Form + Weekly Volume user. Pakai bahasa Indonesia santai (gen-z
friendly), istilah lari/load bahasa Inggris (CTL, ATL, form, fitness, volume).

Fokus ke tren (naik/turun, plateau, peak). Sebut konteks kalau ada (PR week,
recovery week, taper).

JANGAN preachy, JANGAN data dump.
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
            temperature: 0.7,
        );

        return (string) $decoded['caption'];
    }
}
