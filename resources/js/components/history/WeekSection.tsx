import { memo } from 'react';

import type {
    FormStatus,
    Mood,
    WeeklySnapshotWithRecap,
} from '@/types/inertia';

import SummaryCard from '@/components/activities/SummaryCard';
import MetricExplainer from '@/components/MetricExplainer';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import SendNotificationButton from '@/components/SendNotificationButton';
import Temari from '@/components/temari/Temari';
import Card from '@/components/ui/Card';
import { Icon } from '@/components/ui/Icon';
import { useCountUp } from '@/hooks/useCountUp';
import { useNotificationsReachable } from '@/hooks/useNotificationsReachable';
import { cn } from '@/lib/cn';
import { formStatusLabel } from '@/lib/formStatus';
import { type MetricKey } from '@/lib/metricGlossary';
import { poseForFormStatus } from '@/lib/temariPose';
import { type WeekBucket } from '@/pages/Activities/useFeedFilters';

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
    /** A filter narrowed this week's runs, so its totals describe a subset. */
    filtered: boolean;
}

const WeekSection = memo(function WeekSection({
    bucket,
    snapshot,
    notes,
    moods,
    filtered,
}: Readonly<WeekSectionProps>) {
    const notificationsReachable = useNotificationsReachable();

    // The date-range filter can truncate a week's runs list without truncating
    // the week itself, e.g. the range boundary lands mid-week. bucket.* only
    // sums the runs actually in view, so it can undercount vs. the pre-aggregated
    // WeeklySnapshot the recap text below quotes — prefer the snapshot's totals
    // whenever one exists so the header always agrees with the narration.
    // Except: (a) the in-progress week, since WeeklyAggregator recomputes the
    // snapshot from a queued listener (DispatchPostRunAnalysis), so right after a
    // fresh sync bucket can be more current than a snapshot the worker hasn't
    // caught up to yet; and (b) a filtered view, where the snapshot describes the
    // whole week but only a subset is on screen.
    const useSnapshotTotals =
        snapshot !== null && !filtered && !snapshot.is_current_week;
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

    // Filtering removes non-matching runs outright, so a week silently loses the
    // context the old dimmed-row treatment used to convey. The WeeklySnapshot
    // already carries the week's true total, computed independently of any
    // filter, so the gap can be named without a second query. Only shown when
    // the snapshot is trustworthy for a count: the in-progress week's is still
    // being recomputed by a queued worker, so it can lag the live bucket.
    const weekTotal =
        snapshot !== null && !snapshot.is_current_week ? snapshot.runs : null;
    const hiddenCount =
        filtered && weekTotal !== null
            ? Math.max(0, weekTotal - bucket.runs.length)
            : 0;

    // hiddenCount > 0 shows two numbers in one string, so it skips count-up.
    const countedRunCount = useCountUp(runCount);
    const countedTotalKm = useCountUp(totalKm);
    const countedTrimp = useCountUp(weekTrimp ?? 0);

    return (
        <Card
            as="section"
            padding="none"
            className="overflow-hidden transition"
        >
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-cream-deep bg-cream-deep/40 px-5 py-4">
                <div className="font-serif text-headline-xs italic text-foreground">
                    {bucket.label}
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs tabular-nums">
                    <Stat
                        icon="mdi:run"
                        label={
                            hiddenCount > 0
                                ? `${bucket.runs.length} of ${weekTotal} run`
                                : `${Math.round(countedRunCount)} run`
                        }
                    />
                    <Stat
                        icon="mdi:map-marker-distance"
                        label={`${countedTotalKm.toFixed(1)} km`}
                    />
                    <Stat
                        icon="mdi:fire"
                        label={
                            weekTrimp === null
                                ? '— TRIMP'
                                : `${Math.round(countedTrimp)} TRIMP`
                        }
                    />
                    {snapshot && <WeeklyStatusChips snapshot={snapshot} />}
                </div>
            </header>

            {hiddenCount > 0 && (
                <p className="flex items-center gap-2 border-b border-cream-deep bg-cream-deep/10 px-5 py-2.5 font-sans text-xs text-text-3">
                    <Icon
                        icon="mdi:eye-off-outline"
                        width={14}
                        height={14}
                        className="shrink-0"
                        aria-hidden
                    />
                    {hiddenCount} other run{hiddenCount === 1 ? '' : 's'} this
                    week {hiddenCount === 1 ? "doesn't" : "don't"} match the
                    filter.
                </p>
            )}

            {snapshot && (
                <div className="border-b border-cream-deep bg-cream-deep/20 px-5 py-4">
                    <div className="flex items-start gap-3.5">
                        <Temari
                            pose={poseForFormStatus(snapshot.form_status)}
                            size={48}
                            animate={false}
                        />
                        <div className="min-w-0 flex-1">
                            <SummaryCard
                                analysis={snapshot.recap_analysis}
                                fallback={ruleBasedFallback(snapshot)}
                                awaitingSchedule={snapshot.is_current_week}
                                isChainHead={snapshot.is_chain_head}
                            />
                            {snapshot.recap_analysis.status === 'done' && (
                                <div className="mt-3">
                                    <SendNotificationButton
                                        url={`/recaps/weekly/${snapshot.id}/send`}
                                        retryAfterSeconds={
                                            snapshot.notification_retry_after_seconds
                                        }
                                        reachable={notificationsReachable}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            <div>
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
        </Card>
    );
});

export default WeekSection;

function WeeklyStatusChips({
    snapshot,
}: Readonly<{ snapshot: WeeklySnapshotWithRecap }>) {
    // Monotony ≥ 1.5 and decoupling ≥ 8% are the runner-relevant alarm thresholds.
    // Below those, render the chip in the neutral cream tone so the row doesn't
    // light up with semantic color when nothing is wrong.
    const monotonyAlert =
        snapshot.monotony !== null && snapshot.monotony >= MONOTONY_ALERT_AT;
    const decouplingAlert =
        snapshot.avg_decoupling !== null &&
        snapshot.avg_decoupling >= DECOUPLING_ALERT_PCT_AT;
    return (
        <>
            {snapshot.atl_7d !== null && (
                <MetricChip
                    label="Fatigue"
                    value={snapshot.atl_7d.toFixed(1)}
                />
            )}
            {snapshot.monotony !== null && (
                <MetricChip
                    label="Monotony"
                    value={snapshot.monotony.toFixed(2)}
                    alert={monotonyAlert}
                    explainerKey="monotony"
                />
            )}
            {snapshot.avg_decoupling !== null && (
                <MetricChip
                    label="Drift"
                    value={`${snapshot.avg_decoupling.toFixed(1)}%`}
                    alert={decouplingAlert}
                    explainerKey="decoupling"
                />
            )}
            {snapshot.ctl_42d !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-leaf/15 px-2.5 py-0.5 text-xs font-semibold text-leaf-ink">
                    Fitness {snapshot.ctl_42d.toFixed(1)}
                </span>
            )}
            {snapshot.form !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-horizon/15 px-2.5 py-0.5 text-xs font-semibold text-horizon-ink">
                    Readiness {snapshot.form >= 0 ? '+' : ''}
                    {snapshot.form.toFixed(1)}
                </span>
            )}
            {snapshot.form_status && (
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        FORM_CHIP_CLASS[snapshot.form_status],
                    )}
                >
                    {formStatusLabel(snapshot.form_status)}
                </span>
            )}
        </>
    );
}

function MetricChip({
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
                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                alert
                    ? 'bg-mood-gassed/15 text-mood-gassed-ink'
                    : 'bg-cream-deep/60 text-text-2',
            )}
        >
            <span className="text-label-micro text-text-2">{label}</span>
            <span className="tabular-nums">{value}</span>
            {explainerKey && (
                <MetricExplainer metricKey={explainerKey} size="xs" />
            )}
        </span>
    );
}

function Stat({ icon, label }: Readonly<{ icon: string; label: string }>) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-cream-deep/60 px-3 py-1 text-foreground">
            <Icon
                icon={icon}
                width={12}
                height={12}
                className="text-text-3"
                aria-hidden
            />
            <span className="font-semibold">{label}</span>
        </span>
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
