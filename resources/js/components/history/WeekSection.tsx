import { memo } from 'react';

import type { Mood, WeeklySnapshotWithRecap } from '@/types/inertia';

import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import { useCountUp } from '@/hooks/useCountUp';
import { formStatusLabel } from '@/lib/formStatus';
import { dominantMood } from '@/lib/mood';
import { type WeekBucket } from '@/pages/Activities/weekBuckets';

import RecapCard from './RecapCard';
import WeeklyStatusChips from './WeeklyStatusChips';

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
                <div className="font-mono text-[0.59375rem] leading-[1.2] text-text-3">
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
