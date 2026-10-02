import { Head } from '@inertiajs/react';
import { Suspense, useState } from 'react';

import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    Mood,
    StoryLine,
} from '@/types/inertia';

import TimeInZoneBar from '@/components/profile/TimeInZoneBar';
import AskAboutRun from '@/components/run/AskAboutRun';
import { EffortPicker, EffortSaved } from '@/components/run/EffortScore';
import LapsCarousel from '@/components/run/LapsCarousel';
import PastYouCard, { type PastYouMatch } from '@/components/run/PastYouCard';
import { type PrBib } from '@/components/run/PrBibStamp';
import RunHero from '@/components/run/RunHero';
import RunHydratingNotice from '@/components/run/RunHydratingNotice';
import RunLenses from '@/components/run/RunLenses';
import SplitsChart from '@/components/run/SplitsChart';
import VitalsCard from '@/components/run/VitalsCard';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { drawnRunAnchors } from '@/lib/anchors';
import { cn } from '@/lib/cn';
import { lazyIsland } from '@/lib/lazyIsland';
import { formatAbsoluteId } from '@/lib/pace';
import { zonePctFromDetail } from '@/lib/runcard';
import { laneStack } from '@/lib/variants';

import { useRunShow, type RunCardDetail } from './useRunShow';

const ShareCardModal = lazyIsland(
    () => import('@/components/card/ShareCardModal'),
);

type DetailedActivity = Activity & {
    detail: ActivityDetail;
};

interface ShowProps {
    activity: DetailedActivity;
    detail: ActivityDetail;
    /** This view queued the run's detail + streams fetch; the page is still thin. */
    awaitingDetail?: boolean;
    card: RunCardDetail | null;
    storyLine: StoryLine | null;
    speechAnalysis: AnalysisPayload;
    runInsight: AnalysisPayload;
    /** Backend-computed mood used only until the post-run StoryLine is persisted. */
    moodFallback: Mood;
    /** This run is the head of the per-activity narration chain (latest run). */
    isChainHead: boolean;
    pastYou: PastYouMatch | null;
    prBib: PrBib | null;
}

export default function RunsShow({
    activity,
    detail,
    awaitingDetail = false,
    card,
    storyLine,
    speechAnalysis,
    runInsight,
    moodFallback,
    isChainHead,
    pastYou,
    prBib,
}: Readonly<ShowProps>) {
    const [shareOpen, setShareOpen] = useState(false);
    const [shareAsked, setShareAsked] = useState(false);
    const [editingEffort, setEditingEffort] = useState(false);
    const effortScore = detail.perceived_effort ?? null;
    const effortSaved = effortScore !== null && !editingEffort;
    const {
        summary,
        perKm,
        laps,
        partialSplit,
        mood,
        paceSec,
        hr,
        trimp,
        cardProps,
        shareData,
    } = useRunShow({ detail, card, storyLine, moodFallback });

    // The deeper fetch owns everything below the hero, so while it is in flight
    // the page shows the notice and the summary it does have, not a column of
    // empty panels — the prototype's `awaitingDetail: 'hydrating'` shape.
    const detailed = !awaitingDetail;
    const zonePct = zonePctFromDetail(detail);
    const anchors = drawnRunAnchors(summary, perKm.length, zonePct);
    // The sync moment, not the run's own clock — analyzed_at is a true instant,
    // so it takes the absolute (date + local time) formatter.
    const syncedAt = formatAbsoluteId(activity.analyzed_at);

    return (
        <>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer>
                {!effortSaved && (
                    <section className="mb-6 border-b border-dashed border-border pb-6">
                        <EffortPicker
                            activityId={activity.id}
                            saved={effortScore}
                            onClose={() => setEditingEffort(false)}
                        />
                    </section>
                )}

                <Eyebrow token="hero" tone="ink-3">
                    Activity
                </Eyebrow>

                {awaitingDetail && (
                    <div className="mt-4">
                        <RunHydratingNotice hydrating={awaitingDetail} />
                    </div>
                )}

                <div className={cn('mt-6', laneStack)}>
                    <RunHero
                        detail={detail}
                        mood={mood}
                        duration={cardProps.duration}
                        paceSec={paceSec}
                        hr={hr}
                        trimp={trimp}
                        onShare={
                            shareData
                                ? () => {
                                      setShareAsked(true);
                                      setShareOpen(true);
                                  }
                                : undefined
                        }
                        prBib={prBib}
                        effort={
                            effortSaved && (
                                <EffortSaved
                                    score={effortScore}
                                    onChange={() => setEditingEffort(true)}
                                />
                            )
                        }
                    />

                    {detailed && <PastYouCard match={pastYou} />}

                    {detailed && (
                        <>
                            <RunLenses
                                mood={mood}
                                story={speechAnalysis}
                                insight={runInsight}
                                isChainHead={isChainHead}
                                drawnAnchors={anchors}
                            />

                            <AskAboutRun
                                activityId={activity.id}
                                summaryOnly={
                                    activity.ingest_state === 'summary'
                                }
                            />

                            <section>
                                <Eyebrow token="small" tone="ink-3">
                                    The breakdown
                                </Eyebrow>
                                <div className="mt-3 flex flex-col gap-4">
                                    <VitalsCard
                                        detail={detail}
                                        summary={summary}
                                    />
                                    {zonePct && (
                                        <TimeInZoneBar
                                            zones={zonePct}
                                            label="Time in zone · this run"
                                            anchored
                                        />
                                    )}
                                </div>
                            </section>

                            {(perKm.length > 0 || partialSplit) && (
                                <SplitsChart
                                    rows={perKm}
                                    partial={partialSplit}
                                />
                            )}

                            {laps.length > 0 && <LapsCarousel laps={laps} />}
                        </>
                    )}
                </div>

                <Eyebrow
                    as="footer"
                    token="micro"
                    tone="ink-3"
                    className="mt-6 text-center"
                >
                    Synced from Strava · {syncedAt}
                    {activity.strava_external_id != null &&
                        ` · #${activity.strava_external_id}`}
                </Eyebrow>
            </PageContainer>
            {shareAsked && shareData !== null && (
                <Suspense fallback={null}>
                    <ShareCardModal
                        open={shareOpen}
                        card={shareData}
                        onClose={() => setShareOpen(false)}
                    />
                </Suspense>
            )}
        </>
    );
}

RunsShow.layout = appLayout;
