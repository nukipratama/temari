import { Head } from '@inertiajs/react';
import { lazy, Suspense, useRef, useState } from 'react';

import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    Mood,
    StoryLine,
} from '@/types/inertia';

import CoachMark from '@/components/onboarding/CoachMark';
import AskAboutRun from '@/components/run/AskAboutRun';
import LapsCarousel from '@/components/run/LapsCarousel';
import PastYouCard, { type PastYouMatch } from '@/components/run/PastYouCard';
import RunHero from '@/components/run/RunHero';
import RunHydratingNotice from '@/components/run/RunHydratingNotice';
import RunLenses from '@/components/run/RunLenses';
import SplitsChart from '@/components/run/SplitsChart';
import VitalsCard from '@/components/run/VitalsCard';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { formatAbsoluteId } from '@/lib/pace';

import { useRunShow, type RunCardDetail } from './useRunShow';

// Carries the ~1200-line canvas engine; fetched on the share tap.
const ShareCardModal = lazy(() => import('@/components/card/ShareCardModal'));

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
}: Readonly<ShowProps>) {
    const shareRef = useRef<HTMLButtonElement>(null);
    const [shareOpen, setShareOpen] = useState(false);
    const { summary, perKm, laps, partialSplit, mood, paceSec, hr, trimp, kartuProps, shareData } =
        useRunShow({ detail, card, storyLine, moodFallback });

    // The deeper fetch owns everything below the hero, so while it is in flight
    // the page shows the notice and the summary it does have, not a column of
    // empty panels — the prototype's `awaitingDetail: 'hydrating'` shape.
    const detailed = !awaitingDetail;
    // The sync moment, not the run's own clock — analyzed_at is a true instant,
    // so it takes the absolute (date + local time) formatter.
    const syncedAt = formatAbsoluteId(activity.analyzed_at);

    return (
        <>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer className="flex flex-col gap-4">
                <Eyebrow token="hero" tone="ink-3">
                    Activity
                </Eyebrow>

                <RunHydratingNotice hydrating={awaitingDetail} />

                <RunHero
                    detail={detail}
                    mood={mood}
                    duration={kartuProps.duration}
                    paceSec={paceSec}
                    hr={hr}
                    trimp={trimp}
                    shareRef={shareRef}
                    onShare={shareData ? () => setShareOpen(true) : undefined}
                />
                <CoachMark
                    id="run-share"
                    anchorRef={shareRef}
                    placement="top"
                    title="Share the card"
                    body="I'll turn this run into an image you can send anywhere."
                />

                {detailed && <PastYouCard match={pastYou} />}

                {detailed && (
                    <>
                        <RunLenses
                            story={speechAnalysis}
                            insight={runInsight}
                            isChainHead={isChainHead}
                        />

                        <AskAboutRun
                            activityId={activity.id}
                            summaryOnly={activity.ingest_state === 'summary'}
                        />

                        <Eyebrow token="hero" tone="ink-3" className="mt-2">
                            The breakdown
                        </Eyebrow>

                        <VitalsCard detail={detail} summary={summary} />

                        {(perKm.length > 0 || partialSplit) && (
                            <SplitsChart
                                rows={perKm}
                                partial={partialSplit}
                            />
                        )}

                        {laps.length > 0 && <LapsCarousel laps={laps} />}
                    </>
                )}

                <Eyebrow as="footer" token="micro" tone="ink-3" className="mt-2 text-center">
                    Synced from Strava · {syncedAt}
                    {activity.strava_external_id != null &&
                        ` · #${activity.strava_external_id}`}
                </Eyebrow>
            </PageContainer>
            {shareOpen && shareData !== null && (
                <Suspense fallback={null}>
                    <ShareCardModal
                        kartu={shareData}
                        onClose={() => setShareOpen(false)}
                    />
                </Suspense>
            )}
        </>
    );
}

RunsShow.layout = appLayout;
