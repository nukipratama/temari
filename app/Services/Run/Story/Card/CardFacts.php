<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Story\BadgeEvaluator;

/**
 * Everything a card prints, resolved once and read by all three styles — so
 * "form follows the run" and the numbers themselves cannot drift between a
 * broadsheet, a ticket and a survey plate.
 *
 * Distance is the app's own 2-decimal reading and the date stamp is the app's
 * own `j M Y`, cased up for the mono labels the styles set them in.
 */
final readonly class CardFacts
{
    /** How many split cells a race card prints, at most. */
    private const int SPLIT_CELLS = 5;

    /**
     * @param  list<string>  $badges  emoji-stripped display names
     * @param  list<array{0: string, 1: string}>  $splits  label + elapsed, race only
     * @param  list<float>  $paceProfile  per-km effort, 0 slowest to 1 fastest
     */
    public function __construct(
        public RunForm $form,
        public Rarity $rarity,
        public string $kind,
        public string $km,
        public float $distanceKm,
        public string $time,
        public string $pace,
        public ?string $heartRate,
        public ?string $elevation,
        public string $place,
        public string $placeShort,
        public ?string $weather,
        public string $dateLong,
        public string $dateShort,
        public string $clock,
        public array $badges,
        public string $serial,
        public ?string $polyline,
        public ?string $raceName,
        public ?string $raceDistance,
        public array $splits,
        public array $paceProfile,
    ) {
    }

    public static function from(RunCard $card, CardOptions $options): self
    {
        $card->loadMissing('activity.detail');
        $detail = $card->activity->detail ?? null;
        $distance = (float) ($detail->distance ?? 0.0);
        $elapsed = (int) ($detail->elapsed_time ?? 0);
        $polyline = $detail?->summary_polyline;
        $polyline = $polyline === '' ? null : $polyline;
        $isRace = $detail?->workout_type === 1;
        $form = self::resolveForm($isRace, $card->pr_set, $distance, $polyline);
        $secPerKm = PaceCalculator::secPerKm($distance, $elapsed);

        return new self(
            form: $form,
            rarity: $card->rarity,
            kind: self::kindLabel($form),
            km: DistanceFormatter::kmString($distance, DistanceFormatter::EXACT) ?? '0.00',
            distanceKm: $distance / 1000,
            time: DurationFormatter::hms($elapsed),
            pace: $secPerKm === null ? '—' : PaceFormatter::format($secPerKm),
            heartRate: $options->heartRate && $detail?->average_heartrate !== null
                ? (string) (int) round($detail->average_heartrate)
                : null,
            elevation: $options->elevation && $detail?->total_elevation_gain !== null
                ? (string) (int) round($detail->total_elevation_gain)
                : null,
            place: $detail->location_name ?? 'no location',
            placeShort: mb_strtoupper(self::firstSegment($detail?->location_name) ?? 'no location'),
            weather: $options->weather ? self::weather($detail) : null,
            dateLong: mb_strtoupper($detail?->start_date_local?->format('D j M Y') ?? ''),
            dateShort: $detail?->start_date_local?->format('d.m.y') ?? '',
            clock: $detail?->start_date_local?->format('H:i') ?? '',
            badges: $options->badges ? self::badgeNames($card->badges ?? []) : [],
            serial: sprintf('TMR-%04d', (int) $card->getKey()),
            polyline: $polyline,
            raceName: $isRace ? mb_strtoupper(trim($detail->name ?? '') ?: 'race') : null,
            raceDistance: $isRace ? self::raceDistanceLabel($distance) : null,
            splits: $isRace ? self::splits($detail) : [],
            paceProfile: self::paceProfile($detail),
        );
    }

    public function hasRoute(): bool
    {
        return $this->polyline !== null;
    }

    /** The bib slot's number: the print's own serial, since no bib is recorded. */
    public function bib(): string
    {
        return mb_substr($this->serial, -4);
    }

    /** 1 at common through 5 at legendary, the ladder every style escalates on. */
    public function level(): int
    {
        return $this->rarity->rank() + 1;
    }

    /**
     * TIME, PACE and one flex cell, in the order the broadsheet and the ticket
     * rule them. A long run always spends the flex cell on elevation; every
     * other form prefers heart rate and falls back to elevation, then to the
     * start clock, so the row is never short a cell.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function statCells(): array
    {
        $flex = match (true) {
            $this->form === RunForm::Long && $this->elevation !== null => ['ELEV', $this->elevation.' m'],
            $this->heartRate !== null => ['AVG HR', $this->heartRate],
            $this->elevation !== null => ['ELEV', $this->elevation.' m'],
            default => ['START', $this->clock],
        };

        return [['TIME', $this->time], ['PACE', $this->pace.'/km'], $flex];
    }

    private static function resolveForm(bool $isRace, bool $prSet, float $distance, ?string $polyline): RunForm
    {
        return match (true) {
            $isRace => RunForm::Race,
            $prSet => RunForm::Pr,
            $distance >= BadgeEvaluator::LONG_SLOW_DISTANCE_THRESHOLD_M => RunForm::Long,
            $polyline === null => RunForm::NoGps,
            default => RunForm::Easy,
        };
    }

    private static function kindLabel(RunForm $form): string
    {
        return match ($form) {
            RunForm::Easy => 'EASY RUN',
            RunForm::Long => 'LONG RUN',
            RunForm::Race => 'RACE',
            RunForm::Pr => 'PERSONAL RECORD',
            RunForm::NoGps => 'NO GPS',
        };
    }

    /**
     * Badge display names with their emoji emblem removed. The image carries a
     * colour-emoji font, but a colour glyph through librsvg is not something
     * the card should depend on.
     *
     * @param  array<int, string>  $slugs
     * @return list<string>
     */
    private static function badgeNames(array $slugs): array
    {
        $names = [];
        foreach (array_slice(array_values($slugs), 0, 3) as $slug) {
            $label = Badge::tryFrom($slug)?->label();
            if ($label === null) {
                continue;
            }
            $names[] = self::stripEmoji($label);
        }

        return $names;
    }

    private static function stripEmoji(string $value): string
    {
        $stripped = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0E}\x{FE0F}\x{200D}]/u',
            '',
            $value,
        ) ?? $value;

        return trim($stripped);
    }

    private static function firstSegment(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $first = trim(explode(',', $location)[0]);

        return $first === '' ? null : $first;
    }

    /** "29°C · WIND 15 KM/H", dropping either half that has no reading. */
    private static function weather(?ActivityDetail $detail): ?string
    {
        if ($detail?->weather_temp_c === null) {
            return null;
        }

        $label = "{$detail->weather_temp_c}°C";
        if ($detail->weather_wind_speed_kmh !== null) {
            $label .= " · wind {$detail->weather_wind_speed_kmh} km/h";
        }

        return $label;
    }

    private static function raceDistanceLabel(float $meters): string
    {
        return match (true) {
            abs($meters - 42_195) < 600 => 'FULL',
            abs($meters - 21_097.5) < 400 => 'HALF',
            abs($meters - 10_000) < 250 => '10K',
            abs($meters - 5_000) < 150 => '5K',
            default => (string) (int) round($meters / 1000).'K',
        };
    }

    /**
     * The run's kilometre splits folded into at most five contiguous buckets,
     * labelled by the kilometre each bucket closes on.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function splits(?ActivityDetail $detail): array
    {
        $rows = self::perKm($detail);
        if ($rows === []) {
            return [];
        }

        $size = (int) ceil(count($rows) / self::SPLIT_CELLS);
        $splits = [];
        foreach (array_chunk($rows, max($size, 1)) as $chunk) {
            $seconds = 0;
            $lastKm = 0;
            foreach ($chunk as $row) {
                $seconds += (int) ($row['elapsed_sec'] ?? 0);
                $lastKm = (int) ($row['km'] ?? $lastKm);
            }
            $splits[] = [$lastKm.'K', DurationFormatter::hms($seconds)];
        }

        return $splits;
    }

    /**
     * Per-km effort normalised across the run, so a style can draw the shape of
     * the run itself instead of inventing terrain. Empty below three splits,
     * where a profile would read as noise.
     *
     * @return list<float>
     */
    private static function paceProfile(?ActivityDetail $detail): array
    {
        $seconds = array_values(array_map(
            fn (array $row): int => (int) ($row['elapsed_sec'] ?? 0),
            array_filter(self::perKm($detail), fn (array $row): bool => (int) ($row['elapsed_sec'] ?? 0) > 0),
        ));
        if (count($seconds) < 3) {
            return [];
        }

        $slowest = max($seconds);
        $fastest = min($seconds);
        $span = $slowest - $fastest;

        return array_map(
            fn (int $value): float => $span === 0 ? 0.5 : round(($slowest - $value) / $span, 3),
            $seconds,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function perKm(?ActivityDetail $detail): array
    {
        return array_values(StreamSummary::fromArray($detail->stream_summary ?? [])->perKm() ?? []);
    }

}
