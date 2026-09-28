import { useId, useState } from 'react';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { formStatusMeaning, formStatusWord } from '@/lib/formStatus';
import { METRIC_GLOSSARY } from '@/lib/metricGlossary';

const MONOTONY_ALERT_AT = 1.5;
// Mirrors DecouplingBands::HIGH, the version 2 steady-effort scale (app/Services/Run/Metrics/DecouplingBands.php).
const DECOUPLING_ALERT_PCT_AT = 12;

interface StatMetric {
    key: string;
    word: string;
    value: string;
    flagged: boolean;
    explanation: string;
}

function buildMetrics(snapshot: WeeklySnapshotWithRecap): StatMetric[] {
    const metrics: StatMetric[] = [];

    if (snapshot.atl_7d !== null) {
        metrics.push({
            key: 'atl',
            word: 'fatigue',
            value: snapshot.atl_7d.toFixed(1),
            flagged: false,
            explanation: METRIC_GLOSSARY.atl.body,
        });
    }

    if (snapshot.monotony !== null) {
        const flagged = snapshot.monotony >= MONOTONY_ALERT_AT;
        const value = snapshot.monotony.toFixed(2);
        metrics.push({
            key: 'monotony',
            word: 'variety',
            value,
            flagged,
            explanation: flagged
                ? `variety ${value}: slip in an easy day to break the week up, since intensity this flat nudges injury risk up.`
                : METRIC_GLOSSARY.monotony.body,
        });
    }

    if (snapshot.avg_decoupling_v2 !== null) {
        const flagged = snapshot.avg_decoupling_v2 >= DECOUPLING_ALERT_PCT_AT;
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
            word: 'form',
            value: formStatusWord(snapshot.form_status),
            flagged: snapshot.form_status === 'overreaching',
            explanation: formStatusMeaning(snapshot.form_status),
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
 * rule-based read instead of the general explanation.
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
