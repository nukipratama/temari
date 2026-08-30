import { memo } from 'react';

import type {
    FormStatus,
    Mood,
    WeeklySnapshotWithRecap,
} from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import { type MetricKey } from '@/lib/metricGlossary';
import { dominantMood } from '@/lib/mood';
import { type WeekBucket } from '@/pages/Activities/weekBuckets';

import RecapCard from './RecapCard';

const FORM_CHIP_CLASS: Record<FormStatus, string> = {
    fresh: 'bg-leaf/15 text-leaf-ink',
    optimal: 'bg-mood-easy/15 text-mood-easy-ink',
    fatigued: 'bg-mood-blazing/15 text-mood-blazing-ink',
    overreaching: 'bg-mood-gassed/15 text-mood-gassed-ink',
};

const MONOTONY_ALERT_AT = 1.5;
const DECOUPLING_ALERT_PCT_AT = 8;

interface WeekSectionProps {
    bucket: WeekBucket;
    snapshot: WeeklySnapshotWithRecap | null;
    notes: Record<number, RunNote>;
    moods: Record<number, Mood>;
}

const WeekSection = memo(function WeekSection({
    bucket,
    snapshot,
    notes,
    moods,
}: Readonly<WeekSectionProps>) {
    const useSnapshotTotals = snapshot !== null && !snapshot.is_current_week;
    const runCount =
        useSnapshotTotals && snapshot.runs !== null
            ? snapshot.runs
            : bucket.runs.length;
    const totalKm =
        useSnapshotTotals && snapshot.distance_km !== null
            ? snapshot.distance_km
            : bucket.totalKm;
    const weekTrimp =
        useSnapshotTotals && snapshot.weekly_trimp !== null
            ? snapshot.weekly_trimp
            : bucket.totalTrimp;

    const countedRunCount = useCountUp(runCount);
    const countedTotalKm = useCountUp(totalKm);
    const countedTrimp = useCountUp(weekTrimp ?? 0);

    const weekMood = dominantMood(
        bucket.runs.map((activity) => moods[activity.id] ?? null),
    );

    return (
        <div className="mb-5.5">
            <div className="mb-2.5 flex items-baseline justify-between px-0.5">
                <div className="font-serif text-base font-semibold text-foreground">
                    {bucket.label}
                </div>
                <div className="font-mono text-[9.5px] leading-[1.2] text-text-3">
                    {Math.round(countedRunCount)} run
                    {Math.round(countedRunCount) === 1 ? '' : 's'} ·{' '}
                    {countedTotalKm.toFixed(1)} km ·{' '}
                    {weekTrimp === null
                        ? '— TRIMP'
                        : `${Math.round(countedTrimp)} TRIMP`}
                </div>
            </div>

            {snapshot && (
                <RecapCard
                    mood={weekMood}
                    analysis={snapshot.recap_analysis}
                    fallback={ruleBasedFallback(snapshot)}
                    awaitingSchedule={snapshot.is_current_week}
                    isChainHead={snapshot.is_chain_head}
                    chips={<WeeklyStatusChips snapshot={snapshot} />}
                    notification={{
                        url: `/recaps/weekly/${snapshot.id}/send`,
                        retryAfterSeconds:
                            snapshot.notification_retry_after_seconds,
                    }}
                    className="mb-2.5"
                />
            )}

            <div className="overflow-hidden rounded-md border border-border-strong bg-card shadow-e1">
                {bucket.runs.map((activity) => (
                    <RunListRow
                        key={activity.id}
                        detail={activity.detail}
                        note={notes[activity.id] ?? null}
                        mood={moods[activity.id] ?? null}
                        runCard={activity.run_card ?? null}
                    />
                ))}
            </div>
        </div>
    );
});

export default WeekSection;

function Chip({
    label,
    value,
    alert = false,
    explainerKey,
}: Readonly<{
    label: string;
    value: string;
    alert?: boolean;
    explainerKey?: MetricKey;
}>) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] uppercase',
                alert
                    ? 'bg-mood-gassed/15 text-mood-gassed-ink'
                    : 'bg-muted text-foreground',
            )}
        >
            {label} {value}
            {explainerKey && (
                <MetricExplainer metricKey={explainerKey} size="xs" />
            )}
        </span>
    );
}

function WeeklyStatusChips({
    snapshot,
}: Readonly<{ snapshot: WeeklySnapshotWithRecap }>) {
    // Monotony ≥ 1.5 and decoupling ≥ 8% are the runner-relevant alarm thresholds.
    const monotonyAlert =
        snapshot.monotony !== null && snapshot.monotony >= MONOTONY_ALERT_AT;
    const decouplingAlert =
        snapshot.avg_decoupling !== null &&
        snapshot.avg_decoupling >= DECOUPLING_ALERT_PCT_AT;
    return (
        <>
            {snapshot.atl_7d !== null && (
                <Chip label="Fatigue" value={snapshot.atl_7d.toFixed(1)} />
            )}
            {snapshot.monotony !== null && (
                <Chip
                    label="Monotony"
                    value={snapshot.monotony.toFixed(2)}
                    alert={monotonyAlert}
                    explainerKey="monotony"
                />
            )}
            {snapshot.avg_decoupling !== null && (
                <Chip
                    label="Drift"
                    value={`${snapshot.avg_decoupling.toFixed(1)}%`}
                    alert={decouplingAlert}
                    explainerKey="decoupling"
                />
            )}
            {snapshot.ctl_42d !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-leaf/15 px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] text-leaf-ink uppercase">
                    Fitness {snapshot.ctl_42d.toFixed(1)}
                </span>
            )}
            {snapshot.form !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-horizon/15 px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] text-horizon-ink uppercase">
                    Readiness {snapshot.form >= 0 ? '+' : ''}
                    {snapshot.form.toFixed(1)}
                </span>
            )}
            {snapshot.form_status && (
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] uppercase',
                        FORM_CHIP_CLASS[snapshot.form_status],
                    )}
                >
                    {formStatusLabel(snapshot.form_status)}
                </span>
            )}
        </>
    );
}

function ruleBasedFallback(snap: WeeklySnapshotWithRecap): string {
    const parts: string[] = [];
    if (snap.runs !== null && snap.distance_km !== null) {
        parts.push(
            `You ran ${snap.runs}x this week for ${snap.distance_km.toFixed(1)} km.`,
        );
    }
    if (snap.form !== null && snap.form_status) {
        const formLabel = formStatusLabel(snap.form_status);
        parts.push(
            `Readiness ${snap.form >= 0 ? '+' : ''}${snap.form.toFixed(1)}, ${formLabel.toLowerCase()}.`,
        );
    }
    return parts.join(' ') || 'No data for this week yet, hang tight.';
}
