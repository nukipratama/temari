import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useRef } from 'react';

import type {
    Mood,
    Rarity,
    SharedProps,
    StravaSyncState,
    WeeklySnapshotWithRecap,
} from '@/types/inertia';

import JourneyStrip, {
    type JourneyMatchData,
} from '@/components/activities/JourneyStrip';
import ActiveFilterChips from '@/components/history/ActiveFilterChips';
import HistoryFilter from '@/components/history/HistoryFilter';
import HistoryTabs from '@/components/history/HistoryTabs';
import {
    RangeWidenedNote,
    RunsTruncatedNote,
    WeekFocusNote,
} from '@/components/history/InlineNote';
import ResumeFilterChip from '@/components/history/ResumeFilterChip';
import WeekSection from '@/components/history/WeekSection';
import CoachMark from '@/components/onboarding/CoachMark';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import StravaSyncButton from '@/components/StravaSyncButton';
import Temari from '@/components/temari/Temari';
import BackLink from '@/components/ui/BackLink';
import Card from '@/components/ui/Card';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';

import {
    DEFAULT_SORT,
    SORT_OPTIONS,
    labelFor,
    useFeedFilters,
    type DistanceBand,
    type RangeFilterValue,
    type RunWithDetail,
    type SortMode,
} from './useFeedFilters';

interface RunsIndexProps {
    runs: ReadonlyArray<RunWithDetail>;
    notes?: Record<number, RunNote>;
    moods?: Record<number, Mood>;
    rangeFilter: RangeFilterValue;
    /** Moods the server filtered on. Empty = no mood filter. */
    moodFilter?: ReadonlyArray<Mood>;
    /** Distance band the server filtered on, or null for any distance. */
    distanceFilter?: DistanceBand | null;
    /** Rarity the server filtered on, or null for any rarity. */
    rarityFilter?: Rarity | null;
    /** Ordering the server applied. Anything but 'newest' renders a flat list. */
    sortMode?: SortMode;
    /** Week deep link (that week's Sunday, YYYY-MM-DD), or null. */
    weekFilter?: string | null;
    rangeStart: string | null;
    /** Server widened the requested range to reach an older run. */
    rangeAutoWidened?: boolean;
    /** Older runs beyond the per-page cap were dropped from this list. */
    runsTruncated?: boolean;
    /** The per-page cap, shown in the truncation note. */
    maxRuns?: number;
    weeklySnapshots: ReadonlyArray<WeeklySnapshotWithRecap>;
    journeyMatch?: JourneyMatchData | null;
}

export default function RunsIndex({
    runs,
    notes = {},
    moods = {},
    rangeFilter,
    moodFilter = [],
    distanceFilter = null,
    rarityFilter = null,
    sortMode = DEFAULT_SORT,
    weekFilter = null,
    rangeAutoWidened = false,
    runsTruncated = false,
    maxRuns = 0,
    weeklySnapshots,
    journeyMatch = null,
}: Readonly<RunsIndexProps>) {
    const filterRef = useRef<HTMLDivElement>(null);
    const {
        buckets,
        snapshotsByWeek,
        sections,
        chips,
        resetFilters,
        resume,
        anyFilterActive,
        ranked,
    } = useFeedFilters({
        runs,
        weeklySnapshots,
        rangeFilter,
        moodFilter,
        distanceFilter,
        rarityFilter,
        sortMode,
        weekFilter,
    });

    const hasRuns = runs.length > 0;
    // Keying on the active filters replays the results reveal when they change.
    const resultsKey = [
        rangeFilter,
        sortMode,
        moodFilter.join(','),
        distanceFilter ?? '',
        rarityFilter ?? '',
        weekFilter ?? '',
    ].join('|');

    return (
        <>
            <Head title="History · Log" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PageHero
                        eyebrow={
                            anyFilterActive
                                ? `History · ${runs.length} results`
                                : `History · ${runs.length} activities`
                        }
                    >
                        Every run{' '}
                        <em className="not-italic text-horizon-ink">
                            has a story.
                        </em>
                    </PageHero>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <HistoryTabs active="feed" />
                        <div ref={filterRef} data-coachmark="history-filters">
                            <HistoryFilter
                                {...sections}
                                onReset={resetFilters}
                            />
                        </div>
                        <CoachMark
                            id="history-filters"
                            anchorRef={filterRef}
                            placement="bottom"
                            title="Filter the log"
                            body="When the list gets long, narrow it down by mood, distance, rarity, or week."
                        />
                    </div>
                    <ActiveFilterChips
                        chips={chips}
                        onClearAll={resetFilters}
                    />
                    {resume !== null && (
                        <ResumeFilterChip
                            summary={resume.summary}
                            onResume={resume.apply}
                            onDismiss={resume.dismiss}
                        />
                    )}
                </header>

                <JourneyStrip match={journeyMatch} className="mt-8" />

                {weekFilter !== null && (
                    <WeekFocusNote weekEnding={weekFilter} />
                )}

                {hasRuns && (
                    <motion.div
                        key={resultsKey}
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
                        {ranked ? (
                            <motion.div variants={fadeInUp}>
                                <RankedList
                                    runs={runs}
                                    notes={notes}
                                    moods={moods}
                                    sort={sortMode}
                                />
                            </motion.div>
                        ) : (
                            buckets.map((bucket) => (
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
                                        filtered={anyFilterActive}
                                    />
                                </motion.div>
                            ))
                        )}
                    </motion.div>
                )}
                {/* A filtered view that matched nothing is a different story from
                    a genuinely empty history, so it gets its own state with a way
                    back rather than the "connect Strava" onboarding copy. */}
                {!hasRuns && anyFilterActive && (
                    <NoFilterMatchState onReset={resetFilters} />
                )}
                {!hasRuns && !anyFilterActive && <EmptyState />}
            </PageContainer>
        </>
    );
}

/**
 * The ranked (non-chronological) view. Week cards and their recap narration are
 * deliberately absent: a weekly recap only means something in date order, so
 * ranking globally is a different mode rather than a re-ordering of this one.
 * The header says which ranking is active so the missing weeks aren't a mystery.
 */
function RankedList({
    runs,
    notes,
    moods,
    sort,
}: Readonly<{
    runs: ReadonlyArray<RunWithDetail>;
    notes: Record<number, RunNote>;
    moods: Record<number, Mood>;
    sort: SortMode;
}>) {
    const label = labelFor(SORT_OPTIONS, sort);

    return (
        <Card as="section" padding="none" className="overflow-hidden">
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-cream-deep bg-cream-deep/40 px-5 py-4">
                <div className="font-serif text-headline-xs italic text-foreground">
                    {label}
                </div>
                <Eyebrow token="micro" tone="ink-3">
                    {runs.length} runs · sorted
                </Eyebrow>
            </header>
            <div>
                {runs.map((activity) => (
                    <RunListRow
                        key={activity.id}
                        detail={activity.detail}
                        note={notes[activity.id] ?? null}
                        mood={moods[activity.id] ?? null}
                    />
                ))}
            </div>
        </Card>
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

/**
 * Shown when a filter matched nothing. Distinct from {@see EmptyState}: the user
 * has runs, they just narrowed past them, so the copy says so and the only
 * action offered is a way back out instead of Strava onboarding.
 */
function NoFilterMatchState({ onReset }: Readonly<{ onReset: () => void }>) {
    return (
        <Card
            tone="empty"
            padding="hero"
            className="mt-8 flex flex-col items-center text-center"
        >
            <Temari pose="observational" size={112} animate={false} />
            <p className="mt-4 font-serif text-headline-sm italic text-text-2">
                No runs match.
            </p>
            <p className="mt-2 font-sans text-sm text-text-2">
                Your filters are too narrow. Try loosening them up to see more.
            </p>
            <PillButton tone="outline" onClick={onReset} className="mt-4">
                <Icon
                    icon="mdi:filter-remove-outline"
                    width={15}
                    height={15}
                    aria-hidden
                />
                Reset filter
            </PillButton>
        </Card>
    );
}

RunsIndex.layout = appLayout;
