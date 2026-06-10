<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrendCaptionNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1-2 kalimat caption maksimal 40 kata untuk chart Fitness/Form +
        Weekly Volume.

        Fokus ke tren (naik, turun, plateau, peak). Sebutkan konteks bila ada
        (PR week, recovery week, taper).

        Gunakan data `weeks` yang ada di context: bandingkan 4 minggu terakhir
        dengan 4 minggu sebelumnya. WAJIB sebut 1 sinyal terkuat dengan ANGKA
        konkret, pakai field turunan yang sudah dihitung:
        - ctl_delta_4w: perubahan CTL (fitness) 4 minggu terakhir, positif = naik.
        - volume_recent_4w_km vs volume_prev_4w_km: ayunan volume.
        Contoh "CTL naik 6 poin dalam 4 minggu" atau "volume turun dari 38 ke
        31 km". Kalau field turunan null (pengguna baru), baca data apa adanya.

        Contoh:
        - "Fitness naik 3 minggu berturut, volume juga meningkat. Base lagi
          dibangun solid."
        - "Tren volume turun 2 minggu terakhir, form positif. Kayaknya lagi
          taper atau recovery alami."
        - "CTL stagnan di 40-an, volume flat. Perlu variasi buat naik level."

        ANTI-PATTERN:
        - "Tren beberapa minggu terakhir relatif rata. Solid base." --
          terlalu generik.
        - Caption yang sama setiap refresh.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function generate(User $user, Carbon $asOf): string
    {
        $decoded = $this->caller->call(
            kind: 'trend_caption',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($user, $asOf),
            schemaName: 'TemariTrendCaption',
            requiredKeys: ['caption'],
            options: new ChatCallOptions(temperature: 0.7, userId: $user->id, maxTokens: 600),
        );

        return (string) $decoded['caption'];
    }

    /** @return array<string, mixed> */
    public function context(User $user, Carbon $asOf): array
    {
        $weeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_ending')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        [$ctlDelta4w, $volumeRecent, $volumePrev] = $this->fourWeekDeltas($weeks);

        return [
            'as_of' => $asOf->toDateString(),
            'load_today' => $this->trainingLoad->summary($user, $asOf),
            'ctl_delta_4w' => $ctlDelta4w,
            'volume_recent_4w_km' => $volumeRecent,
            'volume_prev_4w_km' => $volumePrev,
            'weeks' => $weeks->map(fn (WeeklySnapshot $w): array => [
                'ending' => $w->week_ending->toDateString(),
                'distance_km' => $w->distance_km,
                'trimp' => $w->weekly_trimp,
                'ctl_42d' => $w->ctl_42d,
                'atl_7d' => $w->atl_7d,
                'form' => $w->form,
                'status' => $w->form_status,
            ])->all(),
        ];
    }

    /**
     * Derived 4-week signals so the caption can cite a concrete number: the
     * CTL swing over the last 4 weeks, and the recent-vs-prior 4-week volume
     * totals. Null when there isn't enough history (fewer than 5 weeks for CTL,
     * fewer than 8 for the volume comparison).
     *
     * @param  Collection<int, WeeklySnapshot>  $weeks  oldest-first
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function fourWeekDeltas(Collection $weeks): array
    {
        $ctlDelta = null;
        if ($weeks->count() >= 5) {
            $latestCtl = $weeks->last()?->ctl_42d;
            $priorCtl = $weeks->get($weeks->count() - 5)?->ctl_42d;
            if ($latestCtl !== null && $priorCtl !== null) {
                $ctlDelta = round($latestCtl - $priorCtl, 1);
            }
        }

        $recent = $prev = null;
        if ($weeks->count() >= 8) {
            $recent = round((float) $weeks->slice($weeks->count() - 4, 4)->sum('distance_km'), 1);
            $prev = round((float) $weeks->slice($weeks->count() - 8, 4)->sum('distance_km'), 1);
        }

        return [$ctlDelta, $recent, $prev];
    }
}
