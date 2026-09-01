import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import { type MetricKey } from '@/lib/metricGlossary';

const MONOTONY_ALERT_AT = 1.5;
const DECOUPLING_ALERT_PCT_AT = 8;

const CHIP_BASE =
    'inline-flex items-center gap-1 rounded-full px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] uppercase';

/**
 * The metric chips under a week's narration. Shared by the feed's week section
 * and the calendar's week-row disclosure, which the prototype draws as the same
 * row of tags at two grains.
 */
export default function WeeklyStatusChips({
    snapshot,
    tone = 'muted',
}: Readonly<{
    snapshot: WeeklySnapshotWithRecap;
    /** Ground the chips sit on: `muted` inside a card, `card` inside a muted panel. */
    tone?: 'muted' | 'card';
}>) {
    // Monotony ≥ 1.5 and decoupling ≥ 8% are the runner-relevant alarm thresholds.
    const monotonyAlert =
        snapshot.monotony !== null && snapshot.monotony >= MONOTONY_ALERT_AT;
    const decouplingAlert =
        snapshot.avg_decoupling !== null &&
        snapshot.avg_decoupling >= DECOUPLING_ALERT_PCT_AT;
    const neutral = tone === 'muted' ? 'bg-muted' : 'bg-card';

    return (
        <>
            {snapshot.atl_7d !== null && (
                <Chip
                    label="Fatigue"
                    value={snapshot.atl_7d.toFixed(1)}
                    neutral={neutral}
                />
            )}
            {snapshot.monotony !== null && (
                <Chip
                    label="Monotony"
                    value={snapshot.monotony.toFixed(2)}
                    alert={monotonyAlert}
                    explainerKey="monotony"
                    neutral={neutral}
                />
            )}
            {snapshot.avg_decoupling !== null && (
                <Chip
                    label="Drift"
                    value={`${snapshot.avg_decoupling.toFixed(1)}%`}
                    alert={decouplingAlert}
                    explainerKey="decoupling"
                    neutral={neutral}
                />
            )}
            {snapshot.ctl_42d !== null && (
                <Chip
                    label="Fitness"
                    value={snapshot.ctl_42d.toFixed(1)}
                    neutral={neutral}
                />
            )}
            {snapshot.form !== null && (
                <Chip
                    label="Readiness"
                    value={`${snapshot.form >= 0 ? '+' : ''}${snapshot.form.toFixed(1)}`}
                    neutral={neutral}
                />
            )}
            {snapshot.form_status && (
                <span className={cn(CHIP_BASE, neutral, 'text-foreground')}>
                    {formStatusLabel(snapshot.form_status)}
                </span>
            )}
        </>
    );
}

function Chip({
    label,
    value,
    alert = false,
    explainerKey,
    neutral,
}: Readonly<{
    label: string;
    value: string;
    alert?: boolean;
    explainerKey?: MetricKey;
    neutral: string;
}>) {
    return (
        <span
            className={cn(
                CHIP_BASE,
                alert
                    ? 'bg-mood-gassed/15 text-mood-gassed-ink'
                    : `${neutral} text-foreground`,
            )}
        >
            {label} {value}
            {explainerKey && (
                <MetricExplainer metricKey={explainerKey} size="xs" />
            )}
        </span>
    );
}
