import { useId, useState } from 'react';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import { cn } from '@/lib/cn';
import {
    formStatusMeaning,
    formStatusWord,
    loadBalanceOf,
} from '@/lib/formStatus';
import { METRIC_GLOSSARY } from '@/lib/metricGlossary';
import { DECOUPLING_HIGH_PCT } from '@/types/generated';

interface StatMetric {
    key: string;
    word: string;
    value: string;
    flagged: boolean;
    explanation: string;
}

function buildMetrics(snapshot: WeeklySnapshotWithRecap): StatMetric[] {
    const metrics: StatMetric[] = [];

    if (snapshot.avg_decoupling_v2 !== null) {
        const flagged = snapshot.avg_decoupling_v2 >= DECOUPLING_HIGH_PCT;
        const value = `${snapshot.avg_decoupling_v2.toFixed(1)}%`;
        metrics.push({
            key: 'decoupling',
            word: 'drift',
            value,
            flagged,
            explanation: flagged
                ? `drift ${value}: your heart rate crept up in the second half of your runs this week.`
                : METRIC_GLOSSARY.decoupling.body,
        });
    }

    if (snapshot.form_status !== null) {
        metrics.push({
            key: 'form',
            word: 'load balance',
            value: formStatusWord(snapshot.form_status),
            flagged: loadBalanceOf(snapshot.form_status) === 'heavy',
            explanation: formStatusMeaning(snapshot.form_status),
        });
    }

    return metrics;
}

/**
 * The week's numbers as one tappable mono line, shown under a week's narration
 * and shared by the feed's week section and the calendar's week-row disclosure.
 * Tapping a metric word reveals a plain explanation inline; a metric past its
 * alarm threshold opens by default with a deterministic, rule-based read.
 */
export default function WeeklyStatLine({
    snapshot,
}: Readonly<{ snapshot: WeeklySnapshotWithRecap }>) {
    const metrics = buildMetrics(snapshot);
    const [overrides, setOverrides] = useState<Record<string, boolean>>({});
    const baseId = useId();

    if (metrics.length === 0) {
        return null;
    }

    const isOpen = (metric: StatMetric) =>
        overrides[metric.key] ?? metric.flagged;

    const toggle = (metric: StatMetric) =>
        setOverrides((prev) => ({
            ...prev,
            [metric.key]: !isOpen(metric),
        }));

    return (
        <div>
            <p className="text-meta">
                {metrics.map((metric, index) => {
                    const open = isOpen(metric);
                    const explainerId = `${baseId}-${metric.key}`;

                    return (
                        <span key={metric.key}>
                            {index > 0 && ' · '}
                            <button
                                type="button"
                                onClick={() => toggle(metric)}
                                aria-expanded={open}
                                aria-controls={open ? explainerId : undefined}
                                className={cn(
                                    'pressable focus-ring rounded-xs underline decoration-dotted decoration-1 underline-offset-2',
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
                        key={metric.key}
                        id={`${baseId}-${metric.key}`}
                        className="narration-dense mt-1 text-text-2"
                    >
                        {metric.explanation}
                    </p>
                ))}
        </div>
    );
}
