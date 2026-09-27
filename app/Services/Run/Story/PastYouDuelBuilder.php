<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;

/**
 * The km-by-km duel inside {@see \App\Http\Controllers\RunController}'s
 * `pastYou` prop — UI-only, never fed to a narrator. Unlike
 * {@see PastYouMatcher::findMatchContext}, whose unsigned-delta rule exists so
 * a narrator can't invert a sign, this class's gaps are signed on purpose: the
 * frontend needs the sign to pick a bar direction, and nothing here reaches a
 * prompt.
 *
 * Source is `activity_details.splits_metric` on both sides — Strava's raw
 * per-km splits, the same field {@see \App\Services\Run\Ingest\KmSplitBuilder}
 * treats as a last-resort fallback for the canonical splits table. The duel
 * only compares whole kilometres both runs covered.
 */
final class PastYouDuelBuilder
{
    /** Distance (m) at or above which a split counts as a full kilometre. */
    private const float FULL_KM_MIN_DISTANCE_M = 950;

    /**
     * @return array{gaps: list<array{km: int, gap_sec: int, label: string}>, net_gap_sec: int, net_label: string, compared_km: int, footnote: string|null}|null
     */
    public function build(ActivityDetail $current, ActivityDetail $past): ?array
    {
        $currentSplits = $this->fullKmElapsedByKm($current->splits_metric);
        $pastSplits = $this->fullKmElapsedByKm($past->splits_metric);

        if ($currentSplits === [] || $pastSplits === []) {
            return null;
        }

        $comparedKm = min(max(array_keys($currentSplits)), max(array_keys($pastSplits)));

        $gaps = [];
        $netGapSec = 0;
        for ($km = 1; $km <= $comparedKm; $km++) {
            if (! isset($currentSplits[$km], $pastSplits[$km])) {
                continue;
            }

            $gapSec = (int) round($currentSplits[$km] - $pastSplits[$km]);
            $netGapSec += $gapSec;
            $gaps[] = ['km' => $km, 'gap_sec' => $gapSec, 'label' => self::gapLabel($gapSec)];
        }

        if ($gaps === []) {
            return null;
        }

        return [
            'gaps' => $gaps,
            'net_gap_sec' => $netGapSec,
            'net_label' => self::gapLabel($netGapSec),
            'compared_km' => count($gaps),
            'footnote' => $this->footnote($current, $past),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $splits
     * @return array<int, float>  elapsed seconds keyed by 1-indexed km
     */
    private function fullKmElapsedByKm(?array $splits): array
    {
        if ($splits === null) {
            return [];
        }

        $byKm = [];
        foreach ($splits as $split) {
            $km = (int) ($split['split'] ?? 0);
            $distance = (float) ($split['distance'] ?? 0);
            $elapsed = (float) ($split['elapsed_time'] ?? 0);
            if ($km < 1 || $distance < self::FULL_KM_MIN_DISTANCE_M || $elapsed <= 0) {
                continue;
            }
            $byKm[$km] = $elapsed;
        }

        return $byKm;
    }

    /** Whichever run went further, named for the km the other one didn't run. */
    private function footnote(ActivityDetail $current, ActivityDetail $past): ?string
    {
        $currentKm = (float) ($current->distance ?? 0) / 1000;
        $pastKm = (float) ($past->distance ?? 0) / 1000;
        $extraKm = round(abs($currentKm - $pastKm), 1);

        if ($extraKm <= 0) {
            return null;
        }

        $whoDidnt = $pastKm < $currentKm ? 'past you' : 'you';

        return sprintf('+%.1f km %s didn\'t run', $extraKm, $whoDidnt);
    }

    private static function gapLabel(int $gapSec): string
    {
        if ($gapSec === 0) {
            return 'even';
        }

        $sign = $gapSec < 0 ? '−' : '+';
        $abs = abs($gapSec);

        return sprintf('%s%d:%02d', $sign, intdiv($abs, 60), $abs % 60);
    }
}
