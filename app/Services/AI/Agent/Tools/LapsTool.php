<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\Run\Ingest\KmSplitBuilder;
use App\Services\Run\Metrics\PaceCalculator;

final class LapsTool extends ActivityTool
{
    /**
     * Above this many laps the row table is dropped and the findings carry the
     * reading alone. A 400 m auto-split over a long run runs to dozens of rows,
     * and handing the model a table that long is what invites reciting it.
     */
    private const int MAX_ROWS = 20;

    /**
     * How far apart (sec/km) the quickest and slowest lap must sit before the
     * fast/slow alternation is read as deliberate. A rep sits a minute or more
     * off its recovery; anything tighter is ordinary drift over manual laps.
     */
    private const float REP_PACE_GAP_SEC = 45.0;

    /** A lap under this fraction of the run's own median lap distance reads as a stop. */
    private const float PAUSE_DISTANCE_RATIO = 0.25;

    private const int MIN_PAUSE_LAPS = 2;

    public function name(): string
    {
        return 'get_laps';
    }

    public function description(): string
    {
        return 'Lap sesuai yang dipencet/direkam jam, satu baris per lap dan panjangnya belum tentu '
            .'1 km, plus lap tercepat dan terlambat. Kalau lap-nya berulang cepat-pelan, rep_count '
            .'(jumlah lap cepat) dan recovery_sec (lama tiap jeda di antaranya, detik) ikut muncul; '
            .'kalau gak muncul berarti lap-nya gak berpola. Kalau bukan interval tapi ada beberapa lap '
            .'yang jauh lebih pendek dari lap normalnya, pause_count dan paused_laps (nomor lap-nya) '
            .'ikut muncul, itu tandanya sempat berhenti (lampu merah, nyeberang), bukan capek. '
            .'Balikannya kosong kalau lap-nya cuma auto-split per km, karena sesi itu udah kebaca utuh '
            .'dari get_km_splits. Di sesi dengan lap kebanyakan, baris lap-nya dilewat dan yang tersisa '
            .'cuma temuannya.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        /** @var list<array<string, mixed>> $laps */
        $laps = array_values($this->summary()->laps() ?? []);
        if ($laps === [] || self::isJustTheKilometres($laps)) {
            return self::nothingToAdd();
        }

        $paces = self::paceSeconds($laps);
        [$fastest, $slowest] = self::extremes($paces);
        $reps = self::reps($paces);
        $recoveries = self::recoveries($laps, $reps);
        $pauses = $reps === [] ? self::pauses($laps) : [];

        return [
            'lap_count' => count($laps),
            'laps' => count($laps) > self::MAX_ROWS ? null : $laps,
            'fastest_lap' => $fastest === null ? null : self::lapNumber($laps, $fastest),
            'slowest_lap' => $slowest === null ? null : self::lapNumber($laps, $slowest),
            'rep_count' => $reps === [] ? null : count($reps),
            'recovery_sec' => $recoveries === [] ? null : $recoveries,
            'pause_count' => $pauses === [] ? null : count($pauses),
            'paused_laps' => $pauses === [] ? null : array_values(array_filter(array_map(
                fn (int $position): ?int => self::lapNumber($laps, $position),
                $pauses,
            ))),
        ];
    }

    /**
     * The reading a run has no laps story for. Every key null, so the encoder
     * hands the model `{}` rather than a shape it has to work out is empty.
     *
     * @return array<string, null>
     */
    private static function nothingToAdd(): array
    {
        return [
            'lap_count' => null,
            'laps' => null,
            'fastest_lap' => null,
            'slowest_lap' => null,
            'rep_count' => null,
            'recovery_sec' => null,
            'pause_count' => null,
            'paused_laps' => null,
        ];
    }

    /**
     * Whether the laps are the plain kilometre grid, which get_km_splits already
     * tells whole. Same threshold as the builder that wrote the rows.
     *
     * @param  list<array<string, mixed>>  $laps
     */
    private static function isJustTheKilometres(array $laps): bool
    {
        return KmSplitBuilder::isPlainKmGrid(self::distances($laps));
    }

    /**
     * @param  list<array<string, mixed>>  $laps
     * @return list<float>
     */
    private static function distances(array $laps): array
    {
        return array_map(
            fn (array $lap): float => is_numeric($lap['distance_m'] ?? null) ? (float) $lap['distance_m'] : 0.0,
            $laps,
        );
    }

    /**
     * Pace in sec/km per lap, keyed by the lap's position. A lap that carries no
     * usable distance or time is absent rather than zero.
     *
     * @param  list<array<string, mixed>>  $laps
     * @return array<int, float>
     */
    private static function paceSeconds(array $laps): array
    {
        $paces = [];
        foreach ($laps as $index => $lap) {
            $pace = PaceCalculator::secPerKm(
                is_numeric($lap['distance_m'] ?? null) ? (float) $lap['distance_m'] : null,
                is_numeric($lap['elapsed_sec'] ?? null) ? (float) $lap['elapsed_sec'] : null,
            );
            if ($pace !== null) {
                $paces[$index] = $pace;
            }
        }

        return $paces;
    }

    /**
     * Positions of the quickest and slowest lap, or nulls when none is readable.
     *
     * @param  array<int, float>  $paces
     * @return array{0: int|null, 1: int|null}
     */
    private static function extremes(array $paces): array
    {
        if ($paces === []) {
            return [null, null];
        }

        return [
            (int) array_search(min($paces), $paces, true),
            (int) array_search(max($paces), $paces, true),
        ];
    }

    /**
     * Positions of the work laps, when the laps repeat a fast/slow structure at
     * all: at least two quick laps, none of them back to back, and a spread wide
     * enough that the split into quick and easy means something. An even set of
     * manual laps comes back empty, which is the reading "no structure here".
     *
     * @param  array<int, float>  $paces
     * @return list<int>
     */
    private static function reps(array $paces): array
    {
        if (count($paces) < 3 || max($paces) - min($paces) < self::REP_PACE_GAP_SEC) {
            return [];
        }

        $threshold = (min($paces) + max($paces)) / 2;
        $work = array_keys(array_filter($paces, fn (float $pace): bool => $pace <= $threshold));
        if (count($work) < 2) {
            return [];
        }

        foreach ($work as $position) {
            if (in_array($position + 1, $work, true)) {
                return [];
            }
        }

        return $work;
    }

    /**
     * Only called when reps() found nothing, so recovery laps in a real
     * interval session are never relabelled as stops. Excludes the final lap:
     * every run's last lap is short because the run ended mid-lap.
     *
     * @param  list<array<string, mixed>>  $laps
     * @return list<int>
     */
    private static function pauses(array $laps): array
    {
        $n = count($laps);
        if ($n < 3) {
            return [];
        }

        $body = array_slice(self::distances($laps), 0, $n - 1);

        $sorted = $body;
        sort($sorted);
        $median = $sorted[intdiv(count($sorted), 2)] ?? 0.0;
        if ($median <= 0.0) {
            return [];
        }

        $threshold = $median * self::PAUSE_DISTANCE_RATIO;
        $flagged = array_keys(array_filter(
            $body,
            fn (float $distance): bool => $distance > 0.0 && $distance < $threshold,
        ));

        return count($flagged) >= self::MIN_PAUSE_LAPS ? $flagged : [];
    }

    /**
     * Seconds spent between one rep and the next, however many laps the runner
     * pressed in the gap.
     *
     * @param  list<array<string, mixed>>  $laps
     * @param  list<int>  $reps
     * @return list<int>
     */
    private static function recoveries(array $laps, array $reps): array
    {
        $gaps = [];
        for ($i = 1; $i < count($reps); $i++) {
            $seconds = 0;
            for ($position = $reps[$i - 1] + 1; $position < $reps[$i]; $position++) {
                $elapsed = $laps[$position]['elapsed_sec'] ?? null;
                $seconds += is_numeric($elapsed) ? (int) $elapsed : 0;
            }
            if ($seconds > 0) {
                $gaps[] = $seconds;
            }
        }

        return $gaps;
    }

    /**
     * @param  list<array<string, mixed>>  $laps
     */
    private static function lapNumber(array $laps, int $position): ?int
    {
        $lap = $laps[$position]['lap'] ?? null;

        return is_numeric($lap) ? (int) $lap : null;
    }
}
