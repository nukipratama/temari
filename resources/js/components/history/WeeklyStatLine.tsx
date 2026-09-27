import { useId, useState } from 'react';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { METRIC_GLOSSARY, type MetricKey } from '@/lib/metricGlossary';

const MONOTONY_ALERT_AT = 1.5;
const DECOUPLING_ALERT_PCT_AT = 8;

interface StatMetric {
    glossaryKey: MetricKey;
    word: string;
    value: string;
    flagged: boolean;
    flaggedRead: string | null;
}

function buildMetrics(snapshot: WeeklySnapshotWithRecap): StatMetric[] {
    const metrics: StatMetric[] = [];

    if (snapshot.strain !== null) {
        metrics.push({
            glossaryKey: 'strain',
            word: 'load',
            value: snapshot.strain.toFixed(0),
            flagged: false,
            flaggedRead: null,
        });
    }

    if (snapshot.atl_7d !== null) {
        metrics.push({
            glossaryKey: 'atl',
            word: 'fatigue',
            value: snapshot.atl_7d.toFixed(1),
            flagged: false,
            flaggedRead: null,
        });
    }

    if (snapshot.monotony !== null) {
        const flagged = snapshot.monotony >= MONOTONY_ALERT_AT;
        const value = snapshot.monotony.toFixed(2);
        metrics.push({
            glossaryKey: 'monotony',
            word: 'variety',
            value,
            flagged,
            flaggedRead: flagged
                ? `variety ${value}: this week's intensity barely changed day to day, and staying this flat raises injury risk.`
                : null,
        });
    }

    if (snapshot.avg_decoupling !== null) {
        const flagged = snapshot.avg_decoupling >= DECOUPLING_ALERT_PCT_AT;
        const value = `${snapshot.avg_decoupling.toFixed(1)}%`;
        metrics.push({
            glossaryKey: 'decoupling',
            word: 'drift',
            value,
            flagged,
            flaggedRead: flagged
                ? `drift ${value}: your heart rate crept up in the second half of your runs this week.`
                : null,
        });
    }

    return metrics;
}

/**
 * The week's numbers as one tappable mono line, shown under a week's
 * narration. Shared by the feed's week section and the calendar's week-row
 * disclosure, which the prototype draws as the same line at two grains.
 * Tapping a metric word reveals a plain explanation inline underneath, so
 * nothing needs a popover that a clipped ancestor could cut off. A metric
 * past its alarm threshold opens by default with a deterministic,
 * rule-based read instead of the general glossary explanation.
 */
export default function WeeklyStatLine({
    snapshot,
}: Readonly<{ snapshot: WeeklySnapshotWithRecap }>) {
    const metrics = buildMetrics(snapshot);
    const [overrides, setOverrides] = useState<
        Partial<Record<MetricKey, boolean>>
    >({});
    const baseId = useId();

    if (metrics.length === 0) {
        return null;
    }

    const isOpen = (metric: StatMetric) =>
        overrides[metric.glossaryKey] ?? metric.flagged;

    const toggle = (metric: StatMetric) =>
        setOverrides((prev) => ({
            ...prev,
            [metric.glossaryKey]: !isOpen(metric),
        }));

    return (
        <div>
            <p className="text-meta">
                {metrics.map((metric, index) => {
                    const open = isOpen(metric);
                    const explainerId = `${baseId}-${metric.glossaryKey}`;

                    return (
                        <span key={metric.glossaryKey}>
                            {index > 0 && ' · '}
                            <button
                                type="button"
                                onClick={() => toggle(metric)}
                                aria-expanded={open}
                                aria-controls={open ? explainerId : undefined}
                                className={cn(
                                    'focus-ring rounded-xs underline decoration-dotted decoration-1 underline-offset-2',
                                    metric.flagged
                                        ? 'text-ember-ink'
                                        : 'text-foreground',
                                )}
                            >
                                {metric.word}
                            </button>{' '}
                            <span
                                className={cn(
                                    'tabular-nums',
                                    metric.flagged && 'text-ember-ink',
                                )}
                            >
                                {metric.value}
                            </span>
                        </span>
                    );
                })}
            </p>
            {metrics
                .filter((metric) => isOpen(metric))
                .map((metric) => (
                    <p
                        key={metric.glossaryKey}
                        id={`${baseId}-${metric.glossaryKey}`}
                        className="narration-dense mt-1 text-text-2"
                    >
                        {metric.flagged
                            ? metric.flaggedRead
                            : METRIC_GLOSSARY[metric.glossaryKey].body}
                    </p>
                ))}
        </div>
    );
}
