import { Head } from '@inertiajs/react';
import { useRef, useState } from 'react';

import type {
    ActivityDetail,
    BriefingResult,
    Mood,
    PastYouTrend,
    TrainingLoad,
    WeekPlan,
    WeeklySnapshot,
} from '@/types/inertia';

import FeaturedKartuPanel from '@/components/dashboard/FeaturedKartuPanel';
import KpiTile from '@/components/dashboard/KpiTile';
import LastRunCard, {
    type LastRunNote,
} from '@/components/dashboard/LastRunCard';
import TrainingLoadCard from '@/components/dashboard/TrainingLoadCard';
import VitalChips from '@/components/dashboard/VitalChips';
import EvidenceList from '@/components/home/EvidenceList';
import NoVerdictPanel from '@/components/home/NoVerdictPanel';
import TodaySession from '@/components/home/TodaySession';
import VerdictHero from '@/components/home/VerdictHero';
import WeekPlanWidget from '@/components/home/WeekPlanWidget';
import CoachMark from '@/components/onboarding/CoachMark';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import PageContainer from '@/components/ui/PageContainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { poseForRun } from '@/lib/temariPose';

import { featuredCardFor, weekRangeLabel } from './Home/helpers';

interface HomeProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    lastRunNote?: LastRunNote | null;
    recentMoods?: Record<number, Mood>;
    pastYouTrend?: PastYouTrend | null;
    weekPlan?: WeekPlan | null;
}

/**
 * The home screen answers one question: am I getting better? The verdict and
 * the matched pairs behind it lead; today's session follows; everything the
 * dashboard used to open with sits below as supporting detail.
 */
export default function Home({
    briefing,
    load,
    snapshot,
    recentRuns,
    lastRunNote = null,
    recentMoods = {},
    pastYouTrend = null,
    weekPlan = null,
}: Readonly<HomeProps>) {
    const featuredRef = useRef<HTMLDivElement>(null);

    const featured = featuredCardFor(
        recentRuns,
        briefing.featuredCardId,
        recentMoods,
    );
    const lastRun = recentRuns[0] ?? null;

    // Frozen at mount (lazy init) so the week label isn't recomputed impurely on every render.
    const [now] = useState(() => new Date());

    const weekRuns = useCountUp(snapshot?.runs ?? 0);
    const weekKm = useCountUp(snapshot?.distance_km ?? 0);
    const weekTrimp = useCountUp(snapshot?.weekly_trimp ?? 0);
    const weekRunsDisplay = snapshot ? Math.round(weekRuns).toString() : '—';
    const weekKmDisplay = snapshot ? weekKm.toFixed(1) : '—';
    const weekTrimpDisplay =
        snapshot?.weekly_trimp != null ? Math.round(weekTrimp).toString() : '—';

    const hasRuns = recentRuns.length > 0;
    const judged =
        pastYouTrend !== null && pastYouTrend.verdict !== 'not_enough_history'
            ? pastYouTrend.verdict
            : null;

    return (
        <>
            <Head title="Home" />
            <PageContainer>
                {!hasRuns ? (
                    <EmptyRunsState />
                ) : (
                    <>
                        {weekPlan !== null && (
                            <WeekPlanWidget weekPlan={weekPlan} />
                        )}

                        {pastYouTrend !== null && (
                            <div
                                className={cn(
                                    'flex flex-col gap-4',
                                    weekPlan !== null && 'mt-6',
                                )}
                            >
                                {judged !== null ? (
                                    <>
                                        <VerdictHero
                                            trend={pastYouTrend}
                                            verdict={judged}
                                        />
                                        <EvidenceList trend={pastYouTrend} />
                                    </>
                                ) : (
                                    <NoVerdictPanel trend={pastYouTrend} />
                                )}
                            </div>
                        )}

                        <div className="mt-6">
                            <TodaySession briefing={briefing} />
                        </div>

                        <div className="mt-10 flex flex-col gap-6">
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

                            <div className="grid gap-4 sm:grid-cols-2">
                                {lastRun && (
                                    <LastRunCard
                                        run={lastRun}
                                        pose={poseForRun(
                                            lastRun,
                                            recentMoods[lastRun.activity_id] ??
                                                null,
                                        )}
                                        note={lastRunNote}
                                    />
                                )}
                                <TrainingLoadCard
                                    load={load}
                                    snapshot={snapshot}
                                />
                            </div>

                            {featured && (
                                <section>
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
                                </section>
                            )}
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

Home.layout = appLayout;
