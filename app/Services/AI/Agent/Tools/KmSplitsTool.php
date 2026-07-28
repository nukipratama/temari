<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\Run\Metrics\PaceConsistency;

final class KmSplitsTool extends ActivityTool
{
    /**
     * Rows to sample from a long run before the two extremes are added back.
     *
     * Not a cost measure — a 21 km run's splits are a few hundred tokens. It is
     * about shape: the narrator prompt asks for "1-2 km paling menarik", and
     * handing it a forty-row table to scan is what invites reciting the table
     * instead. Most runs are shorter than this and pass through untouched.
     */
    private const int SAMPLE_ROWS = 12;

    public function name(): string
    {
        return 'get_km_splits';
    }

    public function description(): string
    {
        return 'Split per km (dengan avg_hr per km kalau ada), plus km tercepat dan terlambat yang '
            .'sudah dicarikan buat kamu, sisa jarak setelah km bulat terakhir, pola negative split, '
            .'dan seberapa rata pace-nya. Di lari panjang, per_km cuma sampel: omitted_km bilang '
            .'berapa km yang gak ikut, dan km tercepat/terlambat selalu ada di sampelnya.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $summary = $this->summary();
        /** @var list<array<string, mixed>> $perKm */
        $perKm = is_array($summary['per_km'] ?? null) ? array_values($summary['per_km']) : [];

        [$fastest, $slowest] = self::extremes($perKm);
        $sampled = self::sample($perKm, $fastest, $slowest);

        return [
            'per_km' => $perKm === [] ? null : $sampled,
            'omitted_km' => count($perKm) - count($sampled),
            'fastest_km' => $fastest === null ? null : ($perKm[$fastest]['km'] ?? null),
            'slowest_km' => $slowest === null ? null : ($perKm[$slowest]['km'] ?? null),
            'finish_partial' => $summary['partial_split'] ?? null,
            'negative_split' => $summary['negative_split'] ?? null,
            'pace_consistency' => PaceConsistency::label($summary['pace_variability_sec'] ?? null),
        ];
    }

    /**
     * Indices of the quickest and slowest kilometre, or nulls when no row
     * carries a readable pace.
     *
     * @param  list<array<string, mixed>>  $perKm
     * @return array{0: int|null, 1: int|null}
     */
    private static function extremes(array $perKm): array
    {
        $paces = [];
        foreach ($perKm as $index => $row) {
            $seconds = self::paceSeconds(is_string($row['pace'] ?? null) ? $row['pace'] : '');
            if ($seconds !== null) {
                $paces[$index] = $seconds;
            }
        }

        if ($paces === []) {
            return [null, null];
        }

        return [
            (int) array_search(min($paces), $paces, true),
            (int) array_search(max($paces), $paces, true),
        ];
    }

    /**
     * An even spread across the run, which keeps the first and last kilometre
     * and so preserves the opening and the finish, with the two extremes added
     * back in case the spread stepped over them. Short runs come back whole.
     *
     * @param  list<array<string, mixed>>  $perKm
     * @return list<array<string, mixed>>
     */
    private static function sample(array $perKm, ?int $fastest, ?int $slowest): array
    {
        $count = count($perKm);
        if ($count <= self::SAMPLE_ROWS) {
            return $perKm;
        }

        $keep = [];
        $step = ($count - 1) / (self::SAMPLE_ROWS - 1);
        for ($i = 0; $i < self::SAMPLE_ROWS; $i++) {
            $keep[(int) round($i * $step)] = true;
        }
        foreach ([$fastest, $slowest] as $index) {
            if ($index !== null) {
                $keep[$index] = true;
            }
        }

        ksort($keep);

        return array_values(array_intersect_key($perKm, $keep));
    }

    /** Total seconds in a "m:ss" pace, or null when it is not one. */
    private static function paceSeconds(string $pace): ?int
    {
        $parts = explode(':', $pace);

        return count($parts) === 2 ? ((int) $parts[0]) * 60 + (int) $parts[1] : null;
    }
}
