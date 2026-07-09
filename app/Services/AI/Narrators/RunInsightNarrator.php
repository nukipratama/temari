<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Context\ActivityNarrationContext;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RunBaseline;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;

class RunInsightNarrator
{
    use ReadsPreviousActivityNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 3 catatan interpretasi sesi lari, masing-masing 2-3 kalimat,
        maksimal 55 kata per catatan:

        - technical: terjemahkan cadence, decoupling, dan HR ke bahasa awam.
          JANGAN cuma sebut angka tanpa konteks. Jelaskan APA artinya dan,
          kalau relevan, arah perbaikannya. Kalau pace_variability_sec ada,
          baca konsistensi effort: kecil = pace rata dan terkontrol, besar =
          naik-turun (medan, angin, atau effort belum stabil).
          Contoh interpretasi:
          * cadence 160-165: "Cadence kamu di 162, masih di bawah ideal.
            Coba tingkatkan pelan-pelan ke 170+, langkah lebih pendek tapi
            lebih ringan."
          * decoupling > 10%: "Decoupling +12% artinya HR naik padahal pace
            tetap. Base aerobik belum solid, easy run lebih banyak bisa bantu."
          * decoupling < 5%: "Decoupling cuma +3%, aerobik kamu dalam kondisi
            bagus."
          * HR rata-rata di Z3-Z4 untuk sesi easy: "HR kamu rata-rata 165 di
            sesi yang seharusnya easy. Mungkin pace-nya keburu, atau cuaca
            panas."

          CUACA & DECOUPLING: kalau decoupling tinggi (>10%) TAPI weather_temp_c
          di atas 30 derajat, JANGAN bilang base aerobik jelek atau fitness
          turun. Bingkai sebagai wajar karena panas: jantung kerja lebih keras
          buat bantu tubuh buang panas, bukan sinyal kebugaran yang hilang.
          Kalau decoupling tinggi dan cuacanya sejuk (atau data cuaca gak ada),
          baru itu sinyal aerobik belum solid seperti biasa.
          * Oke (panas): "Decoupling +14%, tapi ini karena cuaca 32 derajat,
            wajar HR ikut naik buat bantu badan dingin. Bukan berarti aerobik
            kamu mundur."

          ANGIN: weather_wind_speed_kmh (kecepatan, km/j), weather_wind_gust_kmh
          (hembusan puncak), weather_wind_direction_deg (arah asal angin dalam
          derajat, 0=utara, 90=timur, 180=selatan, 270=barat). Sebut angin HANYA
          kalau dia masuk akal menjelaskan pace yang drop atau effort yang
          melonjak, bukan sebagai detail wajib. Lewati kalau di bawah ~20 km/j:
          angin selemah itu tidak layak diceritakan. Kalau disebut, kaitkan ke
          dampaknya, jangan cuma lapor angka.
          * Oke: "Effort di km 4-6 naik walau pace-nya turun, angin 28 km/j
            kemungkinan jadi lawan yang bikin berat di segmen itu."
          * ANTI-PATTERN: "Angin 12 km/j dari timur laut." (angka tanpa cerita,
            lagipula di bawah ambang, jangan disebut).

        - splits: highlight 1-2 km paling menarik atau pola pacing keseluruhan.
          Sebut km spesifik dan waktunya kalau data ada. Bicara soal pola
          (negative split, even pacing, fade at the end). Kalau ascent_m
          menonjol, kaitkan perlambatan ke tanjakan secara eksplisit, jangan
          tebak "mungkin capek" kalau elevasi yang jelas penyebabnya.
          Contoh:
          * "Km 3-5 paling stabil, 6:20-6:25 per km. Km 7 melambat ke 6:50,
            wajar, ada 40 m tanjakan di situ."
          * "Paruh kedua makin cepat, split 4 di 6:09 tercepat. Negative split
            yang rapi."

        - zones: interpretasi HR zone breakdown. Sebut persentase spesifik dan,
          kalau time_in_zone_min ada, sebut durasinya (mis. "32 menit di Z2").
          Hubungkan ke tujuan sesi (base building, tempo work, overtraining).
          Kalau trimp ada, baca beban sesi: rendah = ringan/recovery, tinggi =
          sesi berat yang butuh recovery cukup setelahnya.
          Contoh:
          * "70% waktu (32 menit) di Z2, cocok buat base building. TRIMP 85,
            beban ringan, besok bisa lanjut."
          * "Mayoritas Z3-Z4 padahal ini easy run. HR gampang naik, coba
            perlambat pace atau tambah run-walk."

          GREY ZONE: kalau sesi ini kebaca sebagai easy/recovery tapi banyak
          waktunya nyangkut di Z3 ke atas, dan easy_pace_sec ada di konteks,
          boleh selipkan saran lembut buat turunin ke pace easy-nya (konversi
          easy_pace_sec ke menit:detik per km). Ini cuma opsi, bukan tegoran.
          Sebut sekali, jangan diulang-ulang, dan jangan pakai kalau sesinya
          memang niat tempo/threshold (bukan easy).
          * "Ini kerasa kayak easy run tapi banyak nyangkut di Z3. Kalau mau,
            coba turunin ke sekitar 7:15/km biar aerobiknya lebih kebangun."

        HUJAN: kalau weather_rain true, perhatikan weather_rain_source. Kalau
        "observed" boleh sebut hujan dengan tegas ("sempat hujan"). Kalau
        "forecast" datanya cuma prakiraan dan belum tentu kejadian, jadi
        hedge: "prakiraan sempat gerimis", "kayaknya sempat rintik", JANGAN
        "hujan deras" atau klaim pasti.

        Tetap dari sudut pandang aku (Temari) yang mengamati pengguna.

        BAHASA: kata umum pakai Indonesia (stabil/rata bukan "steady", usaha
        bukan "effort" telanjang, "sesi kualitas" bukan "quality" telanjang).
        Istilah lari boleh tetap English: easy, tempo, pace, cadence, base,
        negative split, long run.

        KONTEKS HISTORIS (pakai kalau ada, jangan dipaksakan kalau null):
        - recent_baseline_28d: rata-rata 28 hari terakhir (pace, HR, decoupling).
          Bandingkan sesi ini dengan baseline-nya: lebih cepat/lambat, HR lebih
          tinggi/rendah, decoupling membaik/memburuk. Sebut angkanya kalau bantu,
          mis. "pace 5:30, lebih kencang dari rata-rata 5:48 sebulan terakhir".
        - training_load: acute_7d (beban 7 hari), chronic_42d (kebugaran 42
          hari), form (chronic - acute), form_status (fresh/optimal/fatigued/
          overreaching). Pakai buat saran recovery yang spesifik di bagian zones:
          form minus besar atau fatigued/overreaching = lagi numpuk lelah, arahin
          easy/rest; fresh = segar, boleh dorong sesi kualitas.
        - per_km bisa membawa avg_hr per km. Kalau ada, baca cardiac drift antar
          km (HR merangkak naik di km akhir walau pace mirip = mulai lelah atau
          dehidrasi), kaitkan ke decoupling.

        ANTI-PATTERN:
        - Data dump tanpa interpretasi ("cadence 172, HR 148") -- selalu
          jelaskan apa artinya.
        - Formula yang sama tiap sesi. Variasikan struktur kalimat.
        - Menggurui. Observasi, bukan ceramah.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly RunBaseline $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
    ) {
    }

    /**
     * @return array{technical: string, splits: string, zones: string}
     */
    public function generate(Activity $activity, ActivityDetail $detail): array
    {
        $decoded = $this->caller->call(
            kind: 'run_insight',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($activity, $detail),
            schemaName: 'TemariRunInsight',
            requiredKeys: ['technical', 'splits', 'zones'],
            options: new ChatCallOptions(temperature: 0.7, userId: $activity->user_id, maxTokens: 3000),
        );

        return [
            'technical' => (string) $decoded['technical'],
            'splits' => (string) $decoded['splits'],
            'zones' => (string) $decoded['zones'],
        ];
    }

    /** @return array<string, mixed> */
    public function context(Activity $activity, ActivityDetail $detail): array
    {
        $summary = $detail->streamSummary();
        $shared = ActivityNarrationContext::fromDetail($detail);
        $asOf = $detail->start_date_local ?? Carbon::now();
        $prevNarrative = $this->previousActivityNarrative(
            $activity,
            $detail,
            AnalysisType::RunInsightTechnical,
        );
        $paces = $this->trainingPaces($activity);

        return [
            'distance_km' => $shared->distanceKm(2),
            'moving_time_sec' => $detail->moving_time,
            'avg_hr' => $detail->average_heartrate,
            'max_hr' => $detail->max_heartrate,
            'avg_cadence_spm' => $detail->average_cadence !== null
                ? (int) round((float) $detail->average_cadence * 2)
                : null,
            'decoupling_pct' => $shared->decouplingPct,
            'negative_split' => $shared->negativeSplit,
            'pace_variability_sec' => $summary['pace_variability_sec'] ?? null,
            'zone_pct' => $shared->zonePct,
            'time_in_zone_min' => $summary['time_in_zone_min'] ?? null,
            'trimp' => $detail->trimp_edwards,
            'per_km' => $summary['per_km'] ?? null,
            'ascent_m' => $summary['ascent_m'] ?? null,
            'weather_temp_c' => $shared->weatherTempC,
            'weather_humidity_pct' => $detail->weather_humidity_pct,
            'weather_rain' => $shared->weatherRain,
            'weather_rain_source' => $shared->weatherRainSource,
            'weather_wind_speed_kmh' => $shared->weatherWindSpeedKmh,
            'weather_wind_gust_kmh' => $shared->weatherWindGustKmh,
            'weather_wind_direction_deg' => $shared->weatherWindDirectionDeg,
            'training_load' => $this->trainingLoadContext($activity, $asOf),
            'recent_baseline_28d' => $this->baseline->forUserAsOf($activity->user_id, $asOf, $activity->id),
            'easy_pace_sec' => $paces['easy'] ?? null,
            'threshold_pace_sec' => $paces['threshold'] ?? null,
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * The runner's Daniels training paces derived from their current VDOT, or
     * null when there is not yet enough PR history to estimate one.
     *
     * @return array{easy: int, marathon: int, threshold: int, interval: int}|null
     */
    private function trainingPaces(Activity $activity): ?array
    {
        return $this->trainingPaceCalculator->fromVdotResult($this->vdotEstimator->estimate($activity->user));
    }

    /**
     * The user's fitness/fatigue state as of the run, trimmed to the fields the
     * narrator interprets. Null when there is no TRIMP history to roll.
     *
     * @return array{acute_7d: float, chronic_42d: float, form: float, form_status: string}|null
     */
    private function trainingLoadContext(Activity $activity, Carbon $asOf): ?array
    {
        $load = $this->trainingLoad->summary($activity->user, $asOf);
        if ($load === null) {
            return null;
        }

        return [
            'acute_7d' => $load['atl_7d'],
            'chronic_42d' => $load['ctl_42d'],
            'form' => $load['form'],
            'form_status' => $load['form_status'],
        ];
    }
}
