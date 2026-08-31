import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useMemo, useState } from 'react';

import type {
    Mood,
    SharedProps,
    StravaSyncState,
    WeeklySnapshotWithRecap,
} from '@/types/inertia';

import HistoryNav from '@/components/history/HistoryNav';
import {
    RangeWidenedNote,
    RunsTruncatedNote,
    WeekFocusNote,
    type RangeFilterValue,
} from '@/components/history/InlineNote';
import WeekSection from '@/components/history/WeekSection';
import { type RunNote } from '@/components/run/RunListRow';
import StravaSyncButton from '@/components/StravaSyncButton';
import BackLink from '@/components/ui/BackLink';
import EmptyPanel from '@/components/ui/EmptyPanel';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';

import { groupByWeek, type RunWithDetail } from './weekBuckets';

interface RunsIndexProps {
    runs: ReadonlyArray<RunWithDetail>;
    notes?: Record<number, RunNote>;
    moods?: Record<number, Mood>;
    rangeFilter: RangeFilterValue;
    /** Week deep link (that week's Sunday, YYYY-MM-DD), or null. */
    weekFilter?: string | null;
    /** Server widened the requested range to reach an older run. */
    rangeAutoWidened?: boolean;
    /** Older runs beyond the per-page cap were dropped from this list. */
    runsTruncated?: boolean;
    /** The per-page cap, shown in the truncation note. */
    maxRuns?: number;
    weeklySnapshots: ReadonlyArray<WeeklySnapshotWithRecap>;
}

/** How many of the most-recent weeks show without tapping "load older weeks". */
const VISIBLE_WEEKS = 2;

export default function RunsIndex({
    runs,
    notes = {},
    moods = {},
    rangeFilter,
    weekFilter = null,
    rangeAutoWidened = false,
    runsTruncated = false,
    maxRuns = 0,
    weeklySnapshots,
}: Readonly<RunsIndexProps>) {
    const [olderRevealed, setOlderRevealed] = useState(false);

    const buckets = useMemo(() => groupByWeek(runs), [runs]);
    const snapshotsByWeek = useMemo(() => {
        const map = new Map<string, WeeklySnapshotWithRecap>();
        for (const snap of weeklySnapshots)
            map.set(snap.week_ending.slice(0, 10), snap);
        return map;
    }, [weeklySnapshots]);

    const hasRuns = runs.length > 0;
    const visibleBuckets = olderRevealed
        ? buckets
        : buckets.slice(0, VISIBLE_WEEKS);
    const hasOlderWeeks = buckets.length > VISIBLE_WEEKS;

    return (
        <>
            <Head title="History · Log" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PageHero
                        eyebrow={`History · ${runs.length} activities`}
                        size="quote-lg"
                        italic
                    >
                        every run{' '}
                        <em className="text-horizon-ink">has a story.</em>
                    </PageHero>
                    <HistoryNav active="feed" />
                </header>

                {weekFilter !== null && (
                    <div className="mt-8">
                        <WeekFocusNote weekEnding={weekFilter} />
                    </div>
                )}

                {hasRuns && (
                    <motion.div
                        key={weekFilter ?? 'all'}
                        initial="hidden"
                        animate="visible"
                        variants={staggerContainer}
                        className="mt-8 space-y-8"
                    >
                        {rangeAutoWidened && (
                            <RangeWidenedNote rangeFilter={rangeFilter} />
                        )}
                        {runsTruncated && (
                            <RunsTruncatedNote maxRuns={maxRuns} />
                        )}
                        {visibleBuckets.map((bucket) => (
                            <motion.div
                                key={bucket.weekStart}
                                variants={fadeInUp}
                            >
                                <WeekSection
                                    bucket={bucket}
                                    snapshot={
                                        snapshotsByWeek.get(
                                            bucket.weekEnding,
                                        ) ?? null
                                    }
                                    notes={notes}
                                    moods={moods}
                                />
                            </motion.div>
                        ))}
                        {!olderRevealed && hasOlderWeeks && (
                            <div className="flex justify-center">
                                <button
                                    type="button"
                                    onClick={() => setOlderRevealed(true)}
                                    className="pressable focus-ring inline-flex items-center gap-1.5 rounded-full border border-border-strong bg-card px-4.5 py-2.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase shadow-e1"
                                >
                                    Load older weeks
                                </button>
                            </div>
                        )}
                    </motion.div>
                )}
                {!hasRuns && <EmptyState />}
            </PageContainer>
        </>
    );
}

const EMPTY_COPY: Record<StravaSyncState, { line: string; sub: string }> = {
    disconnected: {
        line: 'Connect Strava first',
        sub: 'I read your runs from Strava. Connect it to fill in your history.',
    },
    revoked: {
        line: 'Strava connection dropped',
        sub: "Your token isn't active anymore. Reconnect so new runs get picked up.",
    },
    syncing: {
        line: 'Pulling in your runs',
        sub: 'Hang tight, your history shows up as soon as the first run finishes processing.',
    },
    ready: {
        line: 'No runs to show yet',
        sub: "New runs appear here once they're processed. Try syncing again if you just finished a run.",
    },
};

function EmptyState() {
    const { stravaSync } = usePage<SharedProps>().props;
    const state: StravaSyncState = stravaSync?.state ?? 'disconnected';
    const { line, sub } = EMPTY_COPY[state];

    // The page auto-widens to show any run the user has, so reaching the empty
    // state means there is genuinely nothing to show yet. The copy explains why
    // per connection state; the sync button is hidden while a sync is already
    // running (nothing for the user to do but wait).
    return (
        <EmptyPanel
            pose="excited"
            title={line}
            body={sub}
            action={
                <>
                    {state !== 'syncing' && (
                        <StravaSyncButton state={state} className="mt-4" />
                    )}
                    <BackLink href="/" tone="accent" className="mt-4">
                        Back to Today
                    </BackLink>
                </>
            }
            className="mt-8 flex flex-col items-center"
        />
    );
}

RunsIndex.layout = appLayout;
