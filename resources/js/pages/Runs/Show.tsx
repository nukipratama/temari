import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { lazy, Suspense, useRef, useState } from 'react';

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
import SendNotificationButton from '@/components/SendNotificationButton';
import StravaAction from '@/components/StravaAction';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Temari from '@/components/temari/Temari';
import BackLink from '@/components/ui/BackLink';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import HeroPanel from '@/components/ui/HeroPanel';
import { Icon } from '@/components/ui/Icon';
import MoodChip from '@/components/ui/MoodChip';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { useNotificationsReachable } from '@/hooks/useNotificationsReachable';
import { usePendingPost } from '@/hooks/usePendingPost';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { postJson } from '@/lib/http';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatIdDate, formatPace, formatShortDateTimeId } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { BADGE_ABILITY, badgeName } from '@/lib/runcard';

import {
    useRunShow,
    type RelativeEffortPayload,
    type RunCardDetail,
} from './useRunShow';

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
    /** Remaining Telegram-send cooldown for this run's speech, or null. */
    notificationRetryAfterSeconds: number | null;
    pastYou: PastYouMatch | null;
    /** This run's effort vs the runner's own 28-day baseline, or null (no HR). */
    relativeEffort: RelativeEffortPayload | null;
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
    notificationRetryAfterSeconds,
    pastYou,
    relativeEffort,
}: Readonly<ShowProps>) {
    const notificationsReachable = useNotificationsReachable();
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
        effortSub,
        kartuProps,
        cardBadges,
        rarityLabel,
        shareData,
    } = useRunShow({ detail, card, storyLine, moodFallback, relativeEffort });

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

    const [resyncing, resync] = usePendingPost(
        `/activities/${activity.id}/resync`,
        { preserveScroll: true },
    );

    const [shareOpen, setShareOpen] = useState(false);
    const [replaying, setReplaying] = useState(false);
    const [replayError, setReplayError] = useState(false);

    // Re-arm the reveal for this card, then reload the pendingReveal prop so the
    // CardReveal modal (mounted in AppShell) plays again. A non-ok response
    // (419/429/500) surfaces a transient error instead of faking success.
    const replayReveal = () => {
        if (replaying || card === null) {
            return;
        }
        setReplaying(true);
        setReplayError(false);
        void postJson(`/api/cards/${card.id}/replay`)
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Replay failed (${response.status})`);
                }
                router.reload({ only: ['pendingReveal'] });
            })
            .catch(() => setReplayError(true))
            .finally(() => setReplaying(false));
    };

    return (
        <>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer>
                <BackLink
                    href="/history"
                    className="mb-4 hidden lg:inline-flex"
                >
                    History · Log
                </BackLink>

                <RunHydratingNotice hydrating={awaitingDetail} />

                <div className="mb-5 flex flex-wrap gap-2">
                    <StravaAction>
                        <PillButton
                            tone="outline"
                            size="sm"
                            disabled={resyncing}
                            className="disabled:opacity-60 disabled:cursor-not-allowed"
                            onClick={resync}
                        >
                            <Icon
                                icon={resyncing ? 'mdi:loading' : 'mdi:sync'}
                                width={15}
                                height={15}
                                className={
                                    resyncing ? 'animate-spin' : undefined
                                }
                                aria-hidden
                            />
                            {resyncing ? 'Syncing…' : 'Resync from Strava'}
                        </PillButton>
                    </StravaAction>
                    <SendNotificationButton
                        url={`/activities/${activity.id}/send`}
                        retryAfterSeconds={notificationRetryAfterSeconds}
                        reachable={notificationsReachable}
                    />
                </div>

                {/* HERO — one panel, stats left + route map right */}
                <section>
                    <HeroPanel className="lg:px-9 lg:py-8">
                        <div className="relative grid grid-cols-1 gap-6 lg:grid-cols-[1.4fr_1fr] lg:items-stretch">
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
                                        <h1 className="font-serif text-display-sm text-cream">
                                            {detail.name ?? 'Run'}
                                        </h1>
                                    </div>
                                </div>
                                <motion.div
                                    data-coachmark="run-hero-stats"
                                    variants={staggerContainer}
                                    initial="hidden"
                                    animate="visible"
                                    className="grid grid-cols-2 gap-5 sm:grid-cols-3 justify-items-center"
                                >
                                    <motion.div variants={fadeInUp}>
                                        <StatTile
                                            tone="plainSky"
                                            size="md"
                                            align="center"
                                            label="DISTANCE"
                                            value={kmDisplay}
                                            unit="km"
                                        />
                                    </motion.div>
                                    <motion.div variants={fadeInUp}>
                                        <StatTile
                                            tone="plainSky"
                                            size="md"
                                            align="center"
                                            label="DURATION"
                                            value={kartuProps.duration}
                                        />
                                    </motion.div>
                                    <motion.div variants={fadeInUp}>
                                        <StatTile
                                            tone="plainSky"
                                            size="md"
                                            align="center"
                                            label="PACE"
                                            value={paceDisplay}
                                            unit="/km"
                                        />
                                    </motion.div>
                                    <motion.div variants={fadeInUp}>
                                        <StatTile
                                            tone="plainSky"
                                            size="md"
                                            align="center"
                                            label="HR"
                                            value={hrDisplay}
                                            unit="bpm"
                                        />
                                    </motion.div>
                                    <motion.div variants={fadeInUp}>
                                        <StatTile
                                            tone="plainSky"
                                            size="md"
                                            align="center"
                                            label="TRIMP"
                                            value={trimpDisplay}
                                            unit="Edwards"
                                            sub={effortSub}
                                            explainerKey="trimp"
                                        />
                                    </motion.div>
                                    <motion.div variants={fadeInUp}>
                                        <StatTile
                                            tone="plainSky"
                                            size="md"
                                            align="center"
                                            label="ELEVATION"
                                            value={elevationDisplay}
                                            unit="m"
                                            explainerKey="ascent"
                                        />
                                    </motion.div>
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
                        className="mt-10 grid gap-8 lg:grid-cols-[minmax(0,340px)_1fr] lg:items-start"
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
                                    <PillButton
                                        tone="outline"
                                        size="sm"
                                        onClick={replayReveal}
                                        disabled={replaying}
                                    >
                                        <Icon
                                            icon="mdi:refresh"
                                            width={14}
                                            height={14}
                                            className={
                                                replaying
                                                    ? 'animate-spin'
                                                    : undefined
                                            }
                                            aria-hidden
                                        />
                                        {replaying
                                            ? 'Preparing…'
                                            : 'Replay card reveal'}
                                    </PillButton>
                                </div>
                                <CoachMark
                                    id="run-share"
                                    anchorRef={shareRef}
                                    placement="top"
                                    title="Share the card"
                                    body="I'll turn this run into an image you can send anywhere."
                                />
                                {replayError && (
                                    <p
                                        role="status"
                                        aria-live="polite"
                                        className="mt-2 font-sans text-xs text-ember-ink"
                                    >
                                        Couldn't replay the card. Try again in a
                                        bit.
                                    </p>
                                )}
                            </div>

                            {/* The rarity explainer is always shown, even with no
                                badges: rarity is a composite score, so a badge-less
                                card still deserves an honest reason, not a blank. */}
                            <Card
                                padding="card"
                                className="flex flex-col gap-4"
                            >
                                <SectionLabel>
                                    Why this earned {rarityLabel}
                                </SectionLabel>
                                <p className="text-sm text-text-2">
                                    Determined by a mix of great things in this
                                    run: a PR, steady or negative-split pace,
                                    long distance, weekly consistency, plus the
                                    badges you brought home.
                                </p>
                                {cardBadges.length > 0 && (
                                    <div className="flex flex-col gap-3">
                                        {cardBadges.map((b, i) => (
                                            <div
                                                key={b}
                                                className={cn(
                                                    'flex items-start gap-3 pb-3',
                                                    i < cardBadges.length - 1
                                                        ? 'border-b border-dashed border-cream-deep'
                                                        : '',
                                                )}
                                            >
                                                <Chip tone="horizon">
                                                    {badgeName(b)}
                                                </Chip>
                                                <p className="flex-1 text-sm text-text-2">
                                                    {BADGE_ABILITY[b] ??
                                                        'A special condition that makes this run stand out.'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Card>
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
                <section className="mt-10">
                    <DetailTiles detail={detail} summary={summary} />
                </section>

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
