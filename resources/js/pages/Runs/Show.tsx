import { lazy, Suspense, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { usePendingPost } from '@/hooks/usePendingPost';
import { appLayout } from '@/layouts/appLayout';
import PillButton from '@/components/ui/PillButton';
import SendNotificationButton from '@/components/SendNotificationButton';
import { useNotificationsReachable } from '@/hooks/useNotificationsReachable';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import DetailTiles from '@/components/run/DetailTiles';
import Eyebrow from '@/components/ui/Eyebrow';
import FourLensGrid from '@/components/run/FourLensGrid';
import HeroPanel from '@/components/ui/HeroPanel';
import MapWeatherPanel from '@/components/run/MapWeatherPanel';
import SplitsTable from '@/components/run/SplitsTable';
import Kartu from '@/components/card/Kartu';
import KartuMount from '@/components/card/KartuMount';
import type { ShareKartuData } from '@/lib/shareCard';
import BackLink from '@/components/ui/BackLink';
import MoodChip from '@/components/ui/MoodChip';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import Temari from '@/components/temari/Temari';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { type TemariPose } from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import { postJson } from '@/lib/http';
import { aktivitasUrl } from '@/lib/routes';
import PageContainer from '@/components/ui/PageContainer';
import {
    formatIdDate,
    formatKm,
    formatNaiveTimeId,
    formatPace,
    formatShortDateId,
    formatShortDateTimeId,
    paceSecPerKm,
} from '@/lib/pace';
import { districtFromLocation } from '@/pages/HariIni/helpers';
import { BADGE_ABILITY, RARITY_LABELS, avgCadenceFromDetail, badgeEmblem, badgeName, fastestKmFromDetail, kartuPropsFromDetail } from '@/lib/runcard';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    CardEdition,
    Mood,
    RunCard,
    StoryLine,
    StreamSummary,
} from '@/types/inertia';

// Carries the ~1200-line canvas engine; fetched on the Bagikan tap.
const ShareCardModal = lazy(() => import('@/components/card/ShareCardModal'));

type DetailedActivity = Activity & {
    detail: ActivityDetail;
};

interface PastYouMatch {
    past: {
        start_date_local: string | null;
        activity_id?: number | null;
        name?: string | null;
    };
    pace_diff_sec: number;
    hr_diff_bpm: number | null;
    days_ago: number;
}

/** Effort of this run vs the runner's own 28-day TRIMP baseline (see RelativeEffort). */
interface RelativeEffortPayload {
    trimp: number;
    baseline: number | null;
    ratio: number | null;
    band: 'well_above' | 'above' | 'typical' | 'below' | null;
}

/** Human "vs biasanya" line per band. Null band (thin baseline) shows nothing. */
const EFFORT_SUB: Record<NonNullable<RelativeEffortPayload['band']>, string> = {
    well_above: 'lebih berat dari biasanya',
    above: 'agak lebih berat dari biasanya',
    typical: 'kayak biasanya',
    below: 'lebih enteng dari biasanya',
};

/** The run's RunCard, enriched with the flavor/edition/share fields this page's
 *  card section needs (see RunController::cardPayload). */
type RunCardDetail = Omit<RunCard, 'activity' | 'edition'> & {
    edition: CardEdition | null;
    flavor_analysis: AnalysisPayload;
    public_share_url: string;
};

interface ShowProps {
    activity: DetailedActivity;
    detail: ActivityDetail;
    card: RunCardDetail | null;
    storyLine: StoryLine | null;
    speechAnalysis: AnalysisPayload;
    insightTechnical: AnalysisPayload;
    insightSplits: AnalysisPayload;
    insightZones: AnalysisPayload;
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
    card,
    storyLine,
    speechAnalysis,
    insightTechnical,
    insightSplits,
    insightZones,
    moodFallback,
    isChainHead,
    notificationRetryAfterSeconds,
    pastYou,
    relativeEffort,
}: Readonly<ShowProps>) {
    const notificationsReachable = useNotificationsReachable();
    const summary: StreamSummary = detail.stream_summary ?? {};
    const perKm = summary.per_km ?? [];
    const partialSplit = summary.partial_split ?? null;

    const mood: Mood = storyLine?.mood ?? moodFallback;
    const pose: TemariPose = MOOD_TO_POSE[mood];

    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.moving_time, detail.distance);
    const pace = paceSec != null ? formatPace(paceSec) : '—';
    const hr = detail.average_heartrate != null ? Math.round(detail.average_heartrate) : null;
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const effortSub = relativeEffort?.band != null ? EFFORT_SUB[relativeEffort.band] : undefined;

    const kartuProps = useMemo(() => kartuPropsFromDetail(detail), [detail]);

    const [resyncing, resync] = usePendingPost(`/aktivitas/${activity.id}/resync`, { preserveScroll: true });

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
        void postJson(`/api/kartu/${card.id}/replay`)
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Replay failed (${response.status})`);
                }
                router.reload({ only: ['pendingReveal'] });
            })
            .catch(() => setReplayError(true))
            .finally(() => setReplaying(false));
    };

    const cardBadges = useMemo(() => (card?.badges ?? []).slice(0, 3), [card]);
    const cadence = avgCadenceFromDetail(detail);
    const fastestKm = fastestKmFromDetail(detail);
    const rarityLabel = card ? RARITY_LABELS[card.rarity] : null;

    const shareDate = detail.start_date_local
        ? (() => {
              const time = formatNaiveTimeId(detail.start_date_local);
              const shortDate = formatShortDateId(detail.start_date_local);
              return time === null ? shortDate : `${shortDate}\n${time}`;
          })()
        : null;

    const shareWeather = (() => {
        if (detail.weather_temp_c == null) {
            return null;
        }
        const temp = `${Math.round(detail.weather_temp_c)}°C`;
        const wind = detail.weather_wind_speed_kmh != null ? `, angin ${Math.round(detail.weather_wind_speed_kmh)} km/j` : '';
        return `${temp}${wind}`;
    })();

    const shareData: ShareKartuData | null = useMemo(
        () =>
            card === null
                ? null
                : {
                      id: card.id,
                      name: card.special_move,
                      shareUrl: card.public_share_url,
                      rarity: card.rarity,
                      mood,
                      subtitle: kartuProps.subtitle,
                      date: shareDate,
                      km,
                      durasi: kartuProps.durasi,
                      pace: paceSec != null ? formatPace(paceSec) : null,
                      trimp: kartuProps.trimp,
                      hr: hr != null ? `${hr} bpm` : null,
                      cadence: cadence != null ? `${cadence} spm` : null,
                      fastestKm: fastestKm != null ? `${fastestKm}/km` : null,
                      ascent: detail.total_elevation_gain != null ? `${Math.round(detail.total_elevation_gain)} m` : null,
                      zonePct: kartuProps.zonePct,
                      location: districtFromLocation(detail.location_name ?? null),
                      weather: shareWeather,
                      wind: detail.weather_wind_speed_kmh != null ? `${Math.round(detail.weather_wind_speed_kmh)} km/j` : null,
                      tags: cardBadges.map((b) => badgeName(b)),
                      tagEmojis: cardBadges.map((b) => badgeEmblem(b)),
                      quote: card.flavor_analysis.content ?? null,
                      polyline: detail.summary_polyline ?? null,
                      distanceKm: detail.distance != null ? detail.distance / 1000 : null,
                      edition: card.edition ?? null,
                  },
        [card, mood, kartuProps, shareDate, km, paceSec, hr, cadence, fastestKm, detail, shareWeather, cardBadges],
    );

    return (
        <>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer>
                <BackLink href="/aktivitas" className="mb-4 hidden lg:inline-flex">
                    Riwayat · Jejak
                </BackLink>

                <div className="mb-5 flex flex-wrap gap-2">
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
                            className={resyncing ? 'animate-spin' : undefined}
                            aria-hidden
                        />
                        {resyncing ? 'Lagi narik…' : 'Resync dari Strava'}
                    </PillButton>
                    <SendNotificationButton
                        url={`/aktivitas/${activity.id}/kirim`}
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
                                    <Temari pose={pose} size={72} animate={false} />
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-1.5 flex flex-wrap items-center gap-2">
                                            <MoodChip mood={mood} onSky />
                                            <Eyebrow as="span" token="micro" tone="ink-on-sky">
                                                {formatShortDateTimeId(detail.start_date_local)}
                                            </Eyebrow>
                                        </div>
                                        <h1 className="font-display text-display-sm text-cream">
                                            {detail.name ?? 'Lari'}
                                        </h1>
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-5 sm:grid-cols-3 justify-items-center">
                                    <StatTile tone="plainSky" size="md" align="center" label="JARAK" value={km} unit="km" />
                                    <StatTile tone="plainSky" size="md" align="center" label="DURASI" value={kartuProps.durasi} />
                                    <StatTile tone="plainSky" size="md" align="center" label="PACE" value={pace} unit="/km" />
                                    <StatTile tone="plainSky" size="md" align="center" label="HR" value={hr != null ? `${hr}` : '—'} unit="bpm" />
                                    <StatTile tone="plainSky" size="md" align="center" label="TRIMP" value={trimp != null ? `${trimp}` : '—'} unit="Edwards" sub={effortSub} explainerKey="trimp" />
                                    <StatTile tone="plainSky" size="md" align="center" label="ELEVASI" value={detail.total_elevation_gain != null ? `${Math.round(detail.total_elevation_gain)}` : '—'} unit="m" explainerKey="ascent" />
                                </div>

                                {/* KAMU VS KAMU DULU — inline in hero */}
                                {pastYou && (
                                    <div className="mt-5 flex items-center justify-between gap-3 rounded-xl border border-cream/15 bg-cream/[0.08] px-4 py-3 backdrop-blur-sm">
                                        <div className="min-w-0">
                                            <Eyebrow token="micro" className="text-cream/60">
                                                Kamu vs {pastYou.days_ago} hari lalu
                                            </Eyebrow>
                                            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-cream/90">
                                                <span className={cn('font-bold tabular-nums', pastYou.pace_diff_sec > 0 ? 'text-leaf' : 'text-citrus')}>
                                                    {Math.abs(Math.round(pastYou.pace_diff_sec))} detik/km {pastYou.pace_diff_sec > 0 ? 'lebih cepat' : 'lebih lambat'}
                                                </span>
                                                {pastYou.hr_diff_bpm !== null && (
                                                    <span className={cn('font-bold tabular-nums', pastYou.hr_diff_bpm < 0 ? 'text-leaf' : 'text-citrus')}>
                                                        {Math.abs(Math.round(pastYou.hr_diff_bpm))} bpm {pastYou.hr_diff_bpm < 0 ? 'lebih rendah' : 'lebih tinggi'}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        {pastYou.past.activity_id != null && (
                                            <Link
                                                href={aktivitasUrl({ activity_id: pastYou.past.activity_id })}
                                                className="focus-ring-on-sky inline-flex shrink-0 items-center gap-1 rounded-full border border-cream/20 px-3 py-1.5 text-label-micro text-cream/70 transition hover:border-cream/40 hover:text-cream"
                                            >
                                                Lihat
                                                <Icon icon="mdi:arrow-right" width={12} height={12} aria-hidden />
                                            </Link>
                                        )}
                                    </div>
                                )}
                            </div>

                            <MapWeatherPanel detail={detail} className="flex" />
                        </div>
                    </HeroPanel>
                </section>

                {/* KARTU — its own section. The card sits in a slim sky mount sized
                    to fit it (not a full hero panel); actions + lore live on the right. */}
                {card && (
                    <section className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,340px)_1fr] lg:items-start">
                        <KartuMount>
                            <Kartu
                                name={card.special_move}
                                km={kartuProps.km}
                                durasi={kartuProps.durasi}
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
                                <Eyebrow token="hero" tone="ink-2" className="mb-3">
                                    ★ {rarityLabel}{card.edition && ` · ${card.edition.total} dari koleksimu`}
                                </Eyebrow>
                                <h2 className="font-display text-display-sm leading-[0.95] tracking-[-0.02em] text-ink">
                                    {card.special_move}.
                                </h2>
                                <div className="mt-3">
                                    <AnalysisStatus
                                        analysis={card.flavor_analysis}
                                        inertiaReloadProps={['card']}
                                        allowReanalyze
                                        showTimestamp={false}
                                        renderContent={(text) => (
                                            <p className="font-display text-quote-md italic leading-relaxed text-ink-2">
                                                &ldquo;{renderBold(stripEdgeQuotes(text))}&rdquo;
                                            </p>
                                        )}
                                    />
                                </div>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <PillButton tone="sky" size="sm" onClick={() => setShareOpen(true)}>
                                        <Icon icon="mdi:share-variant" width={14} height={14} aria-hidden />
                                        Bagikan
                                    </PillButton>
                                    <PillButton tone="outline" size="sm" onClick={replayReveal} disabled={replaying}>
                                        <Icon
                                            icon="mdi:refresh"
                                            width={14}
                                            height={14}
                                            className={replaying ? 'animate-spin' : undefined}
                                            aria-hidden
                                        />
                                        {replaying ? 'Menyiapkan…' : 'Buka ulang kartu'}
                                    </PillButton>
                                </div>
                                {replayError && (
                                    <p role="status" aria-live="polite" className="mt-2 font-sans text-xs text-ember-deep">
                                        Gagal buka ulang kartu. Coba lagi sebentar ya.
                                    </p>
                                )}
                            </div>

                            {/* Kenapa [rarity] — always shown (even with no badges):
                                rarity is a composite score, so a badge-less card still
                                deserves an honest explanation instead of a blank. */}
                            <Card padding="md" className="flex flex-col gap-4">
                                <SectionLabel>Kenapa dapet {rarityLabel}</SectionLabel>
                                <p className="text-sm text-ink-2">
                                    Ditentuin dari gabungan hal keren di lari ini: PR, pace yang stabil atau makin
                                    ngebut, jarak jauh, konsistensi mingguan, plus badge yang kamu bawa pulang.
                                </p>
                                {cardBadges.length > 0 && (
                                    <div className="flex flex-col gap-3">
                                        {cardBadges.map((b, i) => (
                                            <div
                                                key={b}
                                                className={cn(
                                                    'flex items-start gap-3 pb-3',
                                                    i < cardBadges.length - 1 ? 'border-b border-dashed border-cream-deep' : '',
                                                )}
                                            >
                                                <Chip tone="horizon">{badgeName(b)}</Chip>
                                                <p className="flex-1 text-sm text-ink-2">
                                                    {BADGE_ABILITY[b] ?? 'Kondisi spesial yang bikin lari ini istimewa.'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Card>
                        </div>
                    </section>
                )}

                {/* KATA TEMARI */}
                <section className="mt-8">
                    <header className="mb-4 flex items-center gap-3.5">
                        <Temari pose="observational" size={48} animate={false} />
                        <div>
                            <h2 className="font-display text-headline-sm text-ink">
                                Kata Temari
                            </h2>
                            <p className="mt-1 font-sans text-xs text-ink-3">Empat cara liat lari ini.</p>
                        </div>
                    </header>
                    <FourLensGrid
                        cerita={speechAnalysis}
                        terjemahan={insightTechnical}
                        split={insightSplits}
                        hr={insightZones}
                        isChainHead={isChainHead}
                    />
                </section>

                {/* DETAIL TILES */}
                <section className="mt-10">
                    <DetailTiles detail={detail} summary={summary} />
                </section>

                {/* SPLITS */}
                {(perKm.length > 0 || partialSplit) && <SplitsTable rows={perKm} partial={partialSplit} className="mt-10" />}

                <Eyebrow as="footer" token="micro" tone="ink-3" className="mt-8">
                    Tersambung otomatis dari Strava · {formatIdDate(activity.analyzed_at ?? null, 'long')}
                </Eyebrow>
            </PageContainer>
            {shareOpen && shareData !== null && (
                <Suspense fallback={null}>
                    <ShareCardModal kartu={shareData} onClose={() => setShareOpen(false)} />
                </Suspense>
            )}
        </>
    );
}

RunsShow.layout = appLayout;
