import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

import type {
    ActivityDetail,
    BriefingResult,
    Mood,
    PastYouTrend,
    SharedProps,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import FeaturedKartuPanel from '@/components/dashboard/FeaturedKartuPanel';
import KondisiCard from '@/components/dashboard/KondisiCard';
import KpiTile from '@/components/dashboard/KpiTile';
import LastLariCard, {
    type LastRunNote,
} from '@/components/dashboard/LastLariCard';
import PastYouTrendCard from '@/components/dashboard/PastYouTrendCard';
import TodayHeroBanner from '@/components/dashboard/TodayHeroBanner';
import TodayHistoryTabs from '@/components/dashboard/TodayHistoryTabs';
import VitalChips from '@/components/dashboard/VitalChips';
import CoachMark from '@/components/onboarding/CoachMark';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import { type TemariPose } from '@/components/temari/TemariProto';
import PageContainer from '@/components/ui/PageContainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { formatTimeId, formatWeekdayDateId } from '@/lib/pace';
import { VIBE_TO_POSE, poseForRun } from '@/lib/temariPose';

import {
    featuredCardFor,
    vibeSubtitleFor,
    weekRangeLabel,
} from './Today/helpers';

interface TodayProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    lastRunNote?: LastRunNote | null;
    recentMoods?: Record<number, Mood>;
    pastYouTrend?: PastYouTrend | null;
}

export default function Today({
    briefing,
    load,
    snapshot,
    recentRuns,
    lastRunNote = null,
    recentMoods = {},
    pastYouTrend = null,
}: Readonly<TodayProps>) {
    const { props } = usePage<SharedProps & TodayProps>();
    const firstName = props.auth.user?.first_name ?? '';
    const featuredRef = useRef<HTMLDivElement>(null);
    const pose: TemariPose =
        VIBE_TO_POSE[briefing.vibeState] ?? 'observational';

    const featured = featuredCardFor(
        recentRuns,
        briefing.featuredCardId,
        recentMoods,
    );
    const lastRun = recentRuns[0] ?? null;

    // Freeze the date/time line at mount (lazy init) so it isn't recomputed impurely on every render.
    const [now] = useState(() => new Date());
    const dateLine = `${formatWeekdayDateId(now)} · ${formatTimeId(now)} · ${briefing.vibeLabel}`;
    const vibeSubtitle = vibeSubtitleFor(briefing.vibeLabel);

    const weekRuns = useCountUp(snapshot?.runs ?? 0);
    const weekKm = useCountUp(snapshot?.distance_km ?? 0);
    const weekTrimp = useCountUp(snapshot?.weekly_trimp ?? 0);
    const weekRunsDisplay = snapshot ? Math.round(weekRuns).toString() : '—';
    const weekKmDisplay = snapshot ? weekKm.toFixed(1) : '—';
    const weekTrimpDisplay = snapshot ? Math.round(weekTrimp).toString() : '—';

    return (
        <>
            <Head title="Today" />
            <PageContainer>
                <TodayHistoryTabs active="today" className="mb-5" />
                <TodayHeroBanner
                    firstName={firstName}
                    dateLine={dateLine}
                    vibeSubtitle={vibeSubtitle}
                    briefing={briefing}
                    pose={pose}
                    lastRun={lastRun}
                />

                {recentRuns.length === 0 ? (
                    <div className="mt-8">
                        <EmptyRunsState />
                    </div>
                ) : (
                    <>
                        {featured && (
                            <div className="mt-8">
                                <div
                                    ref={featuredRef}
                                    data-coachmark="today-featured-card"
                                >
                                    <FeaturedKartuPanel
                                        featured={featured}
                                        featuredKartuVoice={
                                            briefing.featuredKartuVoice
                                        }
                                    />
                                </div>
                                <CoachMark
                                    id="today-featured-card"
                                    anchorRef={featuredRef}
                                    placement="bottom"
                                    title="Every run gets a card"
                                    body="This one's my pick of your recent runs, and the rest are waiting in Collection."
                                />
                            </div>
                        )}

                        <div className="mt-8 flex flex-col gap-6">
                            <section>
                                <SectionLabel dot dotClass="bg-leaf">
                                    This week · {weekRangeLabel(now)}
                                </SectionLabel>
                                <div className="grid grid-cols-3 gap-3">
                                    <KpiTile
                                        label="Runs"
                                        value={weekRunsDisplay}
                                    />
                                    <KpiTile label="KM" value={weekKmDisplay} />
                                    <KpiTile
                                        label="TRIMP"
                                        value={weekTrimpDisplay}
                                        explainerKey="trimp"
                                    />
                                </div>
                            </section>

                            <VitalChips briefing={briefing} load={load} />

                            {pastYouTrend && (
                                <PastYouTrendCard trend={pastYouTrend} />
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                {lastRun && (
                                    <LastLariCard
                                        run={lastRun}
                                        pose={poseForRun(
                                            lastRun,
                                            recentMoods[lastRun.activity_id] ??
                                                null,
                                        )}
                                        note={lastRunNote}
                                    />
                                )}
                                <KondisiCard load={load} snapshot={snapshot} />
                            </div>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

Today.layout = appLayout;
