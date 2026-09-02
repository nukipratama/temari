import { Head, Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';

import type {
    Mood,
    SharedProps,
    StravaSyncState,
    WeeklySnapshotWithRecap,
} from '@/types/inertia';

import HistoryHeader from '@/components/history/HistoryHeader';
import {
    RangeWidenedNote,
    WeekFocusNote,
    type RangeFilterValue,
} from '@/components/history/InlineNote';
import WeekSection from '@/components/history/WeekSection';
import { type RunNote } from '@/components/run/RunListRow';
import StravaSyncButton from '@/components/StravaSyncButton';
import BackLink from '@/components/ui/BackLink';
import EmptyPanel from '@/components/ui/EmptyPanel';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';

import { groupByWeek, type RunWithDetail } from './weekBuckets';

interface LifetimeStats {
    total_runs: number;
    total_km: number;
    first_run_at: string | null;
}

interface RunsIndexProps {
    runs: ReadonlyArray<RunWithDetail>;
    notes?: Record<number, RunNote>;
    moods?: Record<number, Mood>;
    rangeFilter: RangeFilterValue;
    /** Week deep link (that week's Sunday, YYYY-MM-DD), or null. */
    weekFilter?: string | null;
    /** Server widened the requested range to reach an older run. */
    rangeAutoWidened?: boolean;
    lifetime?: LifetimeStats;
    /** Week sections this page asked the server for. */
    weeksShown?: number;
    /** A run exists behind the oldest week on the page. */
    hasOlderWeeks?: boolean;
    weeklySnapshots: ReadonlyArray<WeeklySnapshotWithRecap>;
}

/** Week sections each "load older weeks" press adds — mirrors FeedFilters::WEEKS_PER_PAGE. */
const WEEKS_PER_PAGE = 2;

export default function RunsIndex({
    runs,
    notes = {},
    moods = {},
    rangeFilter,
    weekFilter = null,
    rangeAutoWidened = false,
    lifetime,
    weeksShown = WEEKS_PER_PAGE,
    hasOlderWeeks = false,
    weeklySnapshots,
}: Readonly<RunsIndexProps>) {
    const buckets = useMemo(() => groupByWeek(runs), [runs]);
    const snapshotsByWeek = useMemo(() => {
        const map = new Map<string, WeeklySnapshotWithRecap>();
        for (const snap of weeklySnapshots)
            map.set(snap.week_ending.slice(0, 10), snap);
        return map;
    }, [weeklySnapshots]);

    const hasRuns = runs.length > 0;

    return (
        <>
            <Head title="History · Log" />
            <PageContainer>
                <HistoryHeader
                    active="feed"
                    activityCount={lifetime?.total_runs}
                />

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
                        {buckets.map((bucket) => (
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
                        {hasOlderWeeks && (
                            <LoadOlderWeeks weeksShown={weeksShown} />
                        )}
                    </motion.div>
                )}
                {!hasRuns && <EmptyState />}
            </PageContainer>
        </>
    );
}

/**
 * P3: a real page, not a reveal. Each press asks the server for two more week
 * sections; `preserveScroll` keeps the weeks already read where they were, and
 * only the list props are refetched.
 */
function LoadOlderWeeks({ weeksShown }: Readonly<{ weeksShown: number }>) {
    const { url } = usePage();
    const next = new URL(url, 'http://history.local');
    next.searchParams.set('weeks', String(weeksShown + WEEKS_PER_PAGE));

    return (
        <div className="flex justify-center">
            <Link
                href={`${next.pathname}${next.search}`}
                preserveScroll
                preserveState
                only={[
                    'runs',
                    'notes',
                    'moods',
                    'weeklySnapshots',
                    'weeksShown',
                    'hasOlderWeeks',
                ]}
                className="pressable focus-ring inline-flex items-center gap-1.25 rounded-full border border-border-strong bg-card px-4.5 py-2.25 font-mono text-[0.59375rem] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase shadow-e1"
            >
                Load older weeks
                <Icon
                    icon="mdi:chevron-down"
                    width={12}
                    height={12}
                    aria-hidden
                />
            </Link>
        </div>
    );
}

const EMPTY_COPY: Record<StravaSyncState, { line: string; sub: string }> = {
    disconnected: {
        line: 'connect Strava first',
        sub: 'i read your runs from Strava. connect it to fill in your history.',
    },
    revoked: {
        line: 'Strava connection dropped',
        sub: "your token isn't active anymore. reconnect so new runs get picked up.",
    },
    syncing: {
        line: 'pulling in your runs',
        sub: 'hang tight, your history shows up as soon as the first run finishes processing.',
    },
    ready: {
        line: 'no runs to show yet',
        sub: "new runs appear here once they're processed. try syncing again if you just finished a run.",
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
            face
            layout="horizontal"
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
