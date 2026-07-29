import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { appLayout } from '@/layouts/appLayout';
import JourneyStrip, { type JourneyMatchData } from '@/components/aktivitas/JourneyStrip';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import EmptyPanel from '@/components/ui/EmptyPanel';
import PillButton from '@/components/ui/PillButton';
import PageHero from '@/components/ui/PageHero';
import RiwayatFilter from '@/components/riwayat/RiwayatFilter';
import ActiveFilterChips from '@/components/riwayat/ActiveFilterChips';
import RiwayatTabs from '@/components/riwayat/RiwayatTabs';
import ResumeFilterChip from '@/components/riwayat/ResumeFilterChip';
import WeekSection from '@/components/riwayat/WeekSection';
import { RangeWidenedNote, RunsTruncatedNote, WeekFocusNote } from '@/components/riwayat/InlineNote';
import BackLink from '@/components/ui/BackLink';
import StravaSyncButton from '@/components/StravaSyncButton';
import Temari from '@/components/temari/Temari';
import {
    DEFAULT_SORT,
    SORT_OPTIONS,
    labelFor,
    useJejakFilters,
    type DistanceBand,
    type RangeFilterValue,
    type RunWithDetail,
    type SortMode,
} from './useJejakFilters';
import PageContainer from '@/components/ui/PageContainer';
import type { Mood, SharedProps, StravaSyncState, WeeklySnapshotWithRecap } from '@/types/inertia';

interface RunsIndexProps {
    runs: ReadonlyArray<RunWithDetail>;
    notes?: Record<number, RunNote>;
    moods?: Record<number, Mood>;
    rangeFilter: RangeFilterValue;
    /** Moods the server filtered on. Empty = no mood filter. */
    moodFilter?: ReadonlyArray<Mood>;
    /** Distance band the server filtered on, or null for any distance. */
    distanceFilter?: DistanceBand | null;
    /** Free-text term the server matched against the run name, or null. */
    searchFilter?: string | null;
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
    searchFilter = null,
    sortMode = DEFAULT_SORT,
    weekFilter = null,
    rangeAutoWidened = false,
    runsTruncated = false,
    maxRuns = 0,
    weeklySnapshots,
    journeyMatch = null,
}: Readonly<RunsIndexProps>) {
    const { buckets, snapshotsByWeek, sections, chips, resetFilters, resume, anyFilterActive, ranked } =
        useJejakFilters({
            runs,
            weeklySnapshots,
            rangeFilter,
            moodFilter,
            distanceFilter,
            searchFilter,
            sortMode,
            weekFilter,
        });

    const hasRuns = runs.length > 0;

    return (
        <>
            <Head title="Riwayat · Jejak" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PageHero
                        eyebrow={
                            anyFilterActive
                                ? `Riwayat · ${runs.length} hasil`
                                : `Riwayat · ${runs.length} aktivitas`
                        }
                        lead="Setiap lari"
                        emph="ada ceritanya."
                        noItalic
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <RiwayatTabs active="jejak" />
                        <RiwayatFilter {...sections} onReset={resetFilters} />
                    </div>
                    <ActiveFilterChips chips={chips} onClearAll={resetFilters} />
                    {resume !== null && (
                        <ResumeFilterChip
                            summary={resume.summary}
                            onResume={resume.apply}
                            onDismiss={resume.dismiss}
                        />
                    )}
                </header>

                <JourneyStrip match={journeyMatch} className="mt-6 mb-6" />

                {weekFilter !== null && <WeekFocusNote weekEnding={weekFilter} />}

                {hasRuns && (
                    <div className="space-y-8">
                        {rangeAutoWidened && <RangeWidenedNote rangeFilter={rangeFilter} />}
                        {runsTruncated && <RunsTruncatedNote maxRuns={maxRuns} />}
                        {ranked ? (
                            <RankedList runs={runs} notes={notes} moods={moods} sort={sortMode} />
                        ) : (
                            buckets.map((bucket) => (
                                <WeekSection
                                    key={bucket.weekStart}
                                    bucket={bucket}
                                    snapshot={snapshotsByWeek.get(bucket.weekEnding) ?? null}
                                    notes={notes}
                                    moods={moods}
                                    filtered={anyFilterActive}
                                />
                            ))
                        )}
                    </div>
                )}
                {/* A filtered view that matched nothing is a different story from
                    a genuinely empty history, so it gets its own state with a way
                    back rather than the "connect Strava" onboarding copy. */}
                {!hasRuns && anyFilterActive && <NoFilterMatchState onReset={resetFilters} />}
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
        <Card as="section" padding="none" className="overflow-hidden shadow-sm">
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-cream-deep bg-cream-deep/40 px-5 py-4">
                <div className="font-display text-lg italic text-ink">{label}</div>
                <Eyebrow tracking="0.12" weight="none" tone="ink-3">
                    {runs.length} lari · diurutkan
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
        line: 'Sambungin Strava dulu ya',
        sub: 'Aku baca lari kamu dari Strava. Sambungin biar riwayatnya keisi.',
    },
    revoked: {
        line: 'Sambungan Strava putus',
        sub: 'Token kamu udah gak aktif. Sambungin lagi biar lari baru kebaca.',
    },
    syncing: {
        line: 'Aku lagi narik lari kamu 🏃‍♀️',
        sub: 'Sebentar ya, riwayatnya muncul begitu lari pertama selesai diproses.',
    },
    ready: {
        line: 'Belum ada lari yang bisa ditampilkan',
        sub: 'Lari baru muncul di sini begitu selesai diproses. Coba sync lagi kalau baru kelar lari.',
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
                    {state !== 'syncing' && <StravaSyncButton state={state} className="mt-4" />}
                    <BackLink href="/" tone="accent" className="mt-4">
                        Kembali ke Hari Ini
                    </BackLink>
                </>
            }
            className="flex flex-col items-center"
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
        <Card tone="empty" padding="lg" className="flex flex-col items-center text-center">
            <Temari pose="observational" size={112} animate={false} />
            <p className="mt-4 font-display text-2xl italic text-ink-2">Gak ada lari yang cocok.</p>
            <p className="mt-2 font-sans text-sm text-ink-2">
                Filternya kesempitan nih. Coba longgarin dikit biar keliatan lagi.
            </p>
            <PillButton tone="outline" onClick={onReset} className="mt-4">
                <Icon icon="mdi:filter-remove-outline" width={15} height={15} aria-hidden />
                Reset filter
            </PillButton>
        </Card>
    );
}

RunsIndex.layout = appLayout;
