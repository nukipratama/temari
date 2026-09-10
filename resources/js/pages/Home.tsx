import { Head } from '@inertiajs/react';

import type {
    ActivityDetail,
    BriefingResult,
    PastYouTrend,
    WeekPlan,
    WeeklySnapshot,
} from '@/types/inertia';

import EvidenceList from '@/components/home/EvidenceList';
import NoPlanCard from '@/components/home/NoPlanCard';
import NoVerdictPanel from '@/components/home/NoVerdictPanel';
import TodaySession from '@/components/home/TodaySession';
import VerdictHero from '@/components/home/VerdictHero';
import WeekPlanWidget from '@/components/home/WeekPlanWidget';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { drawnHomeAnchors } from '@/lib/anchors';
import { todayLocalIso } from '@/lib/pace';

interface HomeProps {
    briefing: BriefingResult;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    pastYouTrend?: PastYouTrend | null;
    weekPlan?: WeekPlan | null;
}

/**
 * Today, on the frozen prototype's `TodayScreen` section list: Temari's read
 * on today, the week's plan card carrying the week's own numbers (or its empty
 * state), then "you vs past you" and the evidence behind it. The deep stats —
 * vitals and condition — live on Trends.
 */
export default function Home({
    briefing,
    snapshot,
    recentRuns,
    pastYouTrend = null,
    weekPlan = null,
}: Readonly<HomeProps>) {
    const hasRuns = recentRuns.length > 0;
    const todayIso = todayLocalIso();
    const todayPlan =
        weekPlan?.days.find((day) => day.date === todayIso) ?? null;
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
                    <div className="flex flex-col gap-4">
                        <TodaySession
                            briefing={briefing}
                            today={todayPlan}
                            drawnAnchors={drawnHomeAnchors(weekPlan)}
                        />

                        {weekPlan !== null ? (
                            <WeekPlanWidget
                                weekPlan={weekPlan}
                                snapshot={snapshot}
                            />
                        ) : (
                            <NoPlanCard snapshot={snapshot} />
                        )}

                        {pastYouTrend !== null &&
                            (judged !== null ? (
                                <div>
                                    <VerdictHero
                                        trend={pastYouTrend}
                                        verdict={judged}
                                    />
                                    <EvidenceList trend={pastYouTrend} />
                                </div>
                            ) : (
                                <NoVerdictPanel trend={pastYouTrend} />
                            ))}
                    </div>
                )}
            </PageContainer>
        </>
    );
}

Home.layout = appLayout;
