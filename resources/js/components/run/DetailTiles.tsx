import type { MetricKey } from '@/lib/metricGlossary';
import type { ActivityDetail, StreamSummary } from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';

// Mirrors the "hot run" threshold used across the backend narration (e.g.
// RunCardFactory, Story/Temari) so the frontend softens the same runs the
// narrators already treat as heat-affected.
const HOT_TEMP_C = 31;

interface DetailTile {
    label: string;
    value: string;
    sub?: string;
    warn?: boolean;
    metricKey?: MetricKey;
}

export default function DetailTiles({
    detail,
    summary,
}: Readonly<{ detail: ActivityDetail; summary: StreamSummary }>) {
    const tiles: DetailTile[] = [];

    if (detail.average_heartrate != null) {
        tiles.push({
            label: 'AVG HR',
            value: `${Math.round(detail.average_heartrate)}`,
            sub: 'bpm',
        });
    }
    if (detail.max_heartrate != null) {
        tiles.push({
            label: 'MAX HR',
            value: `${detail.max_heartrate}`,
            sub: 'bpm',
        });
    }
    if (detail.average_cadence != null) {
        tiles.push({
            label: 'CADENCE',
            value: `${Math.round(detail.average_cadence * 2)}`,
            sub: 'spm avg',
            metricKey: 'cadence',
        });
    }
    // Elevation gain (ASCENT) now lives in the hero stat row; only max-grade stays here.
    // Only when the run actually climbed, so a flat GPS run doesn't show a noisy 0%.
    // stream_summary is an untyped DB JSON blob; a corrupt or legacy row can
    // carry an unusable reading, which must not render as "NaN%".
    const maxGrade = Number(summary.max_grade_pct);
    if (
        summary.max_grade_pct != null &&
        Number.isFinite(maxGrade) &&
        maxGrade >= 3
    ) {
        tiles.push({
            label: 'CLIMB',
            value: `${maxGrade}%`,
            sub: 'steepest climb',
        });
        if (summary.gap_pace != null) {
            tiles.push({
                label: 'GAP',
                value: summary.gap_pace,
                sub: '/km flat-equivalent',
                metricKey: 'gap',
            });
        }
    }
    const decoupling = Number(summary.decoupling_pct);
    if (summary.decoupling_pct != null && Number.isFinite(decoupling)) {
        const decouplingHigh = Math.abs(decoupling) > 8;
        // A hot run drifts HR up for a physiological reason (body works harder to shed
        // heat), not a fitness regression, so it doesn't earn the scary warn tone. Only
        // applies to a positive drift, mirroring the backend's rule (RuleBasedInsightBuilder
        // decoupling > DECOUPLING_HIGH) — a large negative decoupling isn't HR drift at all,
        // so heat can't explain it away.
        const wasHot =
            detail.weather_temp_c != null &&
            detail.weather_temp_c >= HOT_TEMP_C;
        const heatExplainsIt = decoupling > 8 && wasHot;
        tiles.push({
            label: 'DECOUPLING',
            value: `${decoupling >= 0 ? '+' : ''}${decoupling.toFixed(1)}%`,
            sub: heatExplainsIt
                ? `normal, it was ${Math.round(detail.weather_temp_c as number)}°C out`
                : 'breathing drifted in the second half',
            warn: decouplingHigh && !heatExplainsIt,
            metricKey: 'decoupling',
        });
    }

    if (tiles.length === 0) {
        return (
            <EmptyPanel
                title="Technical detail hasn't been read yet."
                className=""
            />
        );
    }

    return (
        <div className="grid grid-cols-2 gap-2.5">
            {tiles.map((t, i) => (
                <div
                    key={t.label}
                    className={cn(
                        'rounded-xl border border-cream-deep bg-cream px-4 py-3.5 shadow-sm',
                        // A lone trailing tile in this 2-column grid would otherwise
                        // waste half the row — span it across both columns instead.
                        i === tiles.length - 1 &&
                            tiles.length % 2 === 1 &&
                            'col-span-2',
                    )}
                >
                    <Eyebrow
                        token="micro"
                        tone="ink-2"
                        className="mb-1.5 inline-flex items-center gap-1"
                    >
                        {t.label}
                        {t.metricKey && (
                            <MetricExplainer
                                metricKey={t.metricKey}
                                size="xs"
                            />
                        )}
                    </Eyebrow>
                    <div
                        className={cn(
                            'font-sans font-bold leading-none tabular-nums tracking-[-0.01em] text-[22px]',
                            t.warn ? 'text-ember' : 'text-ink',
                        )}
                    >
                        {t.value}
                    </div>
                    {t.sub && (
                        <div className="mt-1.5 font-sans text-[11px] leading-snug text-ink-3">
                            {t.sub}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
