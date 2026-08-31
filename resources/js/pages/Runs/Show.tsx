import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { lazy, Suspense, useRef, useState } from 'react';

import type { MetricKey } from '@/lib/metricGlossary';
import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    Mood,
    StoryLine,
} from '@/types/inertia';

import Kartu from '@/components/card/Kartu';
import KartuMount from '@/components/card/KartuMount';
import CoachMark from '@/components/onboarding/CoachMark';
import AskAboutRun from '@/components/run/AskAboutRun';
import DetailTiles from '@/components/run/DetailTiles';
import LapsGraph from '@/components/run/LapsGraph';
import MapWeatherPanel from '@/components/run/MapWeatherPanel';
import PastYouHero, { type PastYouMatch } from '@/components/run/PastYouHero';
import RunHydratingNotice from '@/components/run/RunHydratingNotice';
import RunLenses from '@/components/run/RunLenses';
import SplitsTable from '@/components/run/SplitsTable';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Temari from '@/components/temari/Temari';
import Eyebrow from '@/components/ui/Eyebrow';
import HeroPanel from '@/components/ui/HeroPanel';
import { Icon } from '@/components/ui/Icon';
import MoodChip from '@/components/ui/MoodChip';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatIdDate, formatPace, formatShortDateTimeId } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

import { useRunShow, type RunCardDetail } from './useRunShow';

// Carries the ~1200-line canvas engine; fetched on the share tap.
const ShareCardModal = lazy(() => import('@/components/card/ShareCardModal'));

function countDisplay(
    raw: number | null,
    tweened: number,
    format: (n: number) => string,
): string {
    return raw != null ? format(tweened) : '—';
}

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
    const shareRef = useRef<HTMLDivElement>(null);
    const {
        summary,
        perKm,
        laps,
        partialSplit,
        mood,
        pose,
        paceSec,
        hr,
        trimp,
        kartuProps,
        cardBadges,
        rarityLabel,
        shareData,
    } = useRunShow({ detail, card, storyLine, moodFallback });

    const distanceKmCount = useCountUp(
        detail.distance != null ? detail.distance / 1000 : 0,
    );
    const paceSecCount = useCountUp(paceSec ?? 0);
    const hrCount = useCountUp(hr ?? 0);
    const trimpCount = useCountUp(trimp ?? 0);
    const elevationCount = useCountUp(detail.total_elevation_gain ?? 0);

    const kmDisplay = countDisplay(detail.distance, distanceKmCount, (n) =>
        n.toFixed(2),
    );
    const paceDisplay = countDisplay(paceSec, paceSecCount, formatPace);
    const hrDisplay = countDisplay(hr, hrCount, (n) => `${Math.round(n)}`);
    const trimpDisplay = countDisplay(
        trimp,
        trimpCount,
        (n) => `${Math.round(n)}`,
    );
    const elevationDisplay = countDisplay(
        detail.total_elevation_gain ?? null,
        elevationCount,
        (n) => `${Math.round(n)}`,
    );

    const secondaryStats: Array<{
        label: string;
        value: string;
        unit?: string;
        explainerKey?: MetricKey;
    }> = [
        { label: 'HR', value: hrDisplay, unit: 'bpm' },
        {
            label: 'TRIMP',
            value: trimpDisplay,
            unit: 'Edwards',
            explainerKey: 'trimp',
        },
        {
            label: 'ELEVATION',
            value: elevationDisplay,
            unit: 'm',
            explainerKey: 'ascent',
        },
    ];

    const [shareOpen, setShareOpen] = useState(false);

    return (
        <>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer>
                <RunHydratingNotice hydrating={awaitingDetail} />

                {/* HERO — one panel, stats left + route map right */}
                <section>
                    <HeroPanel>
                        <div className="relative grid grid-cols-1 gap-6">
                            <div className="flex h-full flex-col justify-center">
                                <div className="mb-5 flex items-start gap-4">
                                    <Temari
                                        pose={pose}
                                        size={72}
                                        animate={false}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-1.5 flex flex-wrap items-center gap-2">
                                            <MoodChip mood={mood} onSky />
                                            <Eyebrow
                                                as="span"
                                                token="micro"
                                                tone="ink-on-sky"
                                            >
                                                {formatShortDateTimeId(
                                                    detail.start_date_local,
                                                )}
                                            </Eyebrow>
                                        </div>
                                        <h1 className="font-serif text-quote-lg text-cream italic">
                                            {detail.name ?? 'run'}
                                        </h1>
                                    </div>
                                </div>
                                <motion.div
                                    data-coachmark="run-hero-stats"
                                    variants={staggerContainer}
                                    initial="hidden"
                                    animate="visible"
                                >
                                    <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                                        <motion.div variants={fadeInUp}>
                                            <StatTile
                                                tone="plainSky"
                                                size="lg"
                                                label="DISTANCE"
                                                value={kmDisplay}
                                                unit="km"
                                            />
                                        </motion.div>
                                        <div className="flex gap-5">
                                            <motion.div variants={fadeInUp}>
                                                <StatTile
                                                    tone="plainSky"
                                                    size="md"
                                                    label="DURATION"
                                                    value={kartuProps.duration}
                                                />
                                            </motion.div>
                                            <motion.div variants={fadeInUp}>
                                                <StatTile
                                                    tone="plainSky"
                                                    size="md"
                                                    label="PACE"
                                                    value={paceDisplay}
                                                    unit="/km"
                                                />
                                            </motion.div>
                                        </div>
                                    </div>

                                    <div className="mt-4 grid grid-cols-3 gap-2.5">
                                        {secondaryStats.map((stat) => (
                                            <motion.div
                                                key={stat.label}
                                                variants={fadeInUp}
                                            >
                                                <StatTile
                                                    tone="sky"
                                                    size="sm"
                                                    align="center"
                                                    {...stat}
                                                />
                                            </motion.div>
                                        ))}
                                    </div>
                                </motion.div>
                            </div>

                            <MapWeatherPanel detail={detail} className="flex" />
                        </div>

                        {/* YOU VS PAST YOU — the page's headline claim, so it
                            spans the full hero rather than sitting beside the
                            stats. */}
                        <PastYouHero
                            match={pastYou}
                            className={
                                pastYou
                                    ? 'mt-7 border-t border-cream/15 pt-7'
                                    : undefined
                            }
                        />
                    </HeroPanel>
                </section>

                <AskAboutRun
                    activityId={activity.id}
                    summaryOnly={activity.ingest_state === 'summary'}
                    className="mt-8"
                />

                {/* KARTU — its own section. The card sits in a slim sky mount sized
                    to fit it (not a full hero panel); actions + lore live on the right. */}
                {card && (
                    <section
                        data-coachmark="run-kartu"
                        className="mt-10 grid gap-8"
                    >
                        <KartuMount>
                            <Kartu
                                name={card.special_move}
                                km={kartuProps.km}
                                duration={kartuProps.duration}
                                trimp={kartuProps.trimp}
                                rarity={card.rarity}
                                mood={mood}
                                badges={cardBadges}
                                stats={kartuProps.stats}
                                zonePct={kartuProps.zonePct}
                                polyline={detail.summary_polyline}
                                paceShape={kartuProps.paceShape}
                                edition={card.edition}
                                size="lg"
                                className="w-full"
                            />
                        </KartuMount>

                        <div className="flex flex-col gap-6">
                            <div>
                                <Eyebrow
                                    token="hero"
                                    tone="ink-2"
                                    className="mb-3"
                                >
                                    ★ {rarityLabel}
                                    {card.edition &&
                                        ` · ${card.edition.total} in your collection`}
                                </Eyebrow>
                                <h2 className="font-serif text-display-sm leading-[0.95] tracking-[-0.02em] text-foreground">
                                    {card.special_move}.
                                </h2>
                                <div className="mt-3">
                                    <AnalysisStatus
                                        analysis={card.flavor_analysis}
                                        inertiaReloadProps={['card']}
                                        allowReanalyze
                                        showTimestamp={false}
                                        renderContent={(text) => (
                                            <p className="font-serif text-quote-md italic leading-relaxed text-text-2">
                                                &ldquo;
                                                {renderBold(
                                                    stripEdgeQuotes(text),
                                                )}
                                                &rdquo;
                                            </p>
                                        )}
                                    />
                                </div>
                                <div
                                    ref={shareRef}
                                    data-coachmark="run-share"
                                    className="mt-4 flex flex-wrap gap-2"
                                >
                                    <PillButton
                                        tone="sky"
                                        size="sm"
                                        onClick={() => setShareOpen(true)}
                                    >
                                        <Icon
                                            icon="mdi:share-variant"
                                            width={14}
                                            height={14}
                                            aria-hidden
                                        />
                                        Share
                                    </PillButton>
                                </div>
                                <CoachMark
                                    id="run-share"
                                    anchorRef={shareRef}
                                    placement="top"
                                    title="Share the card"
                                    body="I'll turn this run into an image you can send anywhere."
                                />
                            </div>
                        </div>
                    </section>
                )}

                {/* WHAT TEMARI SAYS */}
                <section data-coachmark="run-narration" className="mt-10">
                    <header className="mb-4 flex items-center gap-3.5">
                        <Temari
                            pose="observational"
                            size={48}
                            animate={false}
                        />
                        <div>
                            <h2 className="font-serif text-headline-sm text-foreground">
                                What Temari says
                            </h2>
                            <p className="mt-1 font-sans text-xs text-text-3">
                                The story of this run, and what stood out.
                            </p>
                        </div>
                    </header>
                    <RunLenses
                        story={speechAnalysis}
                        insight={runInsight}
                        isChainHead={isChainHead}
                    />
                </section>

                {/* DETAIL TILES */}
                <DetailTiles
                    detail={detail}
                    summary={summary}
                    className="mt-10"
                />

                {/* SPLITS */}
                {(perKm.length > 0 || partialSplit) && (
                    <SplitsTable
                        rows={perKm}
                        partial={partialSplit}
                        className="mt-10"
                    />
                )}

                {/* LAPS */}
                {laps.length > 0 && <LapsGraph laps={laps} className="mt-10" />}

                <Eyebrow
                    as="footer"
                    token="micro"
                    tone="ink-3"
                    className="mt-10"
                >
                    Auto-synced from Strava ·{' '}
                    {formatIdDate(activity.analyzed_at ?? null, 'long')}
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
