<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Read model over the `stream_summary` JSON blob that
 * {@see \App\Services\Run\Ingest\StreamAnalysis::compute()} writes onto
 * {@see \App\Models\ActivityDetail}.
 *
 * Every derivation in the producer omits its key entirely when the stream it
 * needs is missing or too short, so any given row carries a subset of the key
 * set and rows written by older revisions carry fewer keys still. Each accessor
 * therefore reports a key that is absent, present-but-null, or present with an
 * unusable type as "no reading" — the same collapse every consumer's `?? null`
 * and `isset()` already performed.
 */
final readonly class StreamSummary
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data)
    {
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    public static function fromArray(?array $summary): self
    {
        return new self($summary ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Percent of moving time per HR zone, keyed `Z1..Z5`. Empty when the run
     * carries no usable heart-rate stream.
     *
     * @return array<string, float|int>
     */
    public function zonePct(): array
    {
        return $this->array('time_in_zone_pct') ?? [];
    }

    /**
     * Minutes per HR zone, or null when the run carries no zone breakdown.
     *
     * @return array<string, float|int>|null
     */
    public function zoneMinutes(): ?array
    {
        return $this->array('time_in_zone_min');
    }

    /**
     * Combined share of moving time spent in Z3, Z4 and Z5.
     */
    public function hardZoneShare(): float
    {
        $zonePct = $this->zonePct();

        return (float) ($zonePct['Z3'] ?? 0)
            + (float) ($zonePct['Z4'] ?? 0)
            + (float) ($zonePct['Z5'] ?? 0);
    }

    /**
     * Fastest pace sustained over one of the producer's best-effort windows,
     * as an "M:SS" string. $window is the label suffix ("30s", "5min", "60min").
     */
    public function bestPace(string $window): ?string
    {
        return $this->string("best_{$window}_pace");
    }

    /**
     * Per-km split rows, or null when Strava shipped no usable splits.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function perKm(): ?array
    {
        return $this->array('per_km');
    }

    /**
     * Lap rows as the watch recorded them — one row per lap at whatever length
     * it was, not bucketed into kilometres. Null when the activity carries no
     * usable laps.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function laps(): ?array
    {
        return $this->array('laps');
    }

    /**
     * The trailing sub-km "sisa" row, or null when the run finished on a whole
     * kilometre (or the leftover was too short to report).
     *
     * @return array<string, mixed>|null
     */
    public function partialSplit(): ?array
    {
        return $this->array('partial_split');
    }

    public function negativeSplit(): ?bool
    {
        $value = $this->data['negative_split'] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function paceVariabilitySec(): ?float
    {
        return $this->float('pace_variability_sec');
    }

    public function hrDriftBpm(): ?float
    {
        return $this->float('hr_drift_bpm');
    }

    public function cadenceDropSpm(): ?float
    {
        return $this->float('cadence_drop_spm');
    }

    public function decouplingPct(): ?float
    {
        return $this->float('decoupling_pct');
    }

    /**
     * Whether the run cleared the sustained-effort floor and carries a
     * decoupling reading at all, as distinct from carrying one that is zero.
     */
    public function hasDecouplingPct(): bool
    {
        return isset($this->data['decoupling_pct']);
    }

    /**
     * Share of time below / within / above the step-rate band, keyed by band.
     *
     * @return array<string, float|int>
     */
    public function cadenceDistributionPct(): array
    {
        return $this->array('cadence_distribution_pct') ?? [];
    }

    public function optimalCadencePct(): ?float
    {
        return $this->float('optimal_cadence_pct');
    }

    public function maxGradePct(): ?float
    {
        return $this->float('max_grade_pct');
    }

    public function climbTimePct(): ?float
    {
        return $this->float('climb_time_pct');
    }

    public function gapPace(): ?string
    {
        return $this->string('gap_pace');
    }

    public function descentM(): ?int
    {
        return $this->int('descent_m');
    }

    public function stoppedTimeSec(): ?int
    {
        return $this->int('stopped_time_sec');
    }

    public function stopCount(): ?int
    {
        return $this->int('stop_count');
    }

    private function float(string $key): ?float
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function int(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function array(string $key): ?array
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) ? $value : null;
    }
}
