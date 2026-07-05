import { lazy, Suspense, useMemo } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { usePendingPost } from '@/hooks/usePendingPost';
import AppShell from '@/layouts/AppShell';
import PillButton from '@/components/ui/PillButton';
import SendToTelegramButton from '@/components/SendToTelegramButton';
import Card from '@/components/ui/Card';
import FourLensGrid from '@/components/run/FourLensGrid';
import HeroPanel from '@/components/ui/HeroPanel';
import Kartu from '@/components/card/Kartu';
import BackLink from '@/components/ui/BackLink';
import MoodChip from '@/components/ui/MoodChip';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import MetricExplainer from '@/components/MetricExplainer';
import type { MetricKey } from '@/lib/metricGlossary';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import { aktivitasUrl, kartuUrl } from '@/lib/routes';
import PageContainer from '@/components/ui/PageContainer';
import { formatIdDate, formatKm, formatPace, formatShortDateTimeId, paceSecPerKm, parsePaceSec } from '@/lib/pace';
import { kartuPropsFromDetail } from '@/lib/runcard';
import { emberGlowStyle } from '@/lib/styles';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    Mood,
    RunCard as RunCardModel,
    SharedProps,
    StoryLine,
} from '@/types/inertia';

const RouteMap = lazy(() => import('@/components/run/RouteMap'));

type DetailedActivityDetail = ActivityDetail & {
    stream_summary?: Record<string, unknown> | null;
    max_heartrate?: number | null;
    average_cadence?: number | null;
    weather_temp_c?: number | null;
    weather_humidity_pct?: number | null;
    weather_rain_detected?: boolean | null;
};

type DetailedActivity = Activity & {
    analyzed_at?: string | null;
    detail: DetailedActivityDetail;
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

interface PerKmRow {
    km?: number | string;
    pace?: string;
    pace_sec?: number;
    avg_hr?: number;
    avg_cadence_spm?: number;
}

interface ShowProps {
    activity: DetailedActivity;
    detail: DetailedActivityDetail;
    card: RunCardModel | null;
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
    telegramRetryAfterSeconds: number | null;
    pastYou: PastYouMatch | null;
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
    telegramRetryAfterSeconds,
    pastYou,
}: Readonly<ShowProps>) {
    const telegramConnected = usePage<SharedProps>().props.telegramConnected ?? false;
    const summary = (detail.stream_summary ?? {}) as Record<string, unknown>;
    const perKm = (summary.per_km as PerKmRow[] | undefined) ?? [];

    const mood: Mood = storyLine?.mood ?? moodFallback;
    const pose: TemariPose = MOOD_TO_POSE[mood];

    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.moving_time, detail.distance);
    const pace = paceSec != null ? formatPace(paceSec) : '—';
    const hr = detail.average_heartrate != null ? Math.round(detail.average_heartrate) : null;
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;

    const kartuProps = useMemo(() => kartuPropsFromDetail(detail), [detail]);

    const [resyncing, resync] = usePendingPost(`/aktivitas/${activity.id}/resync`, { preserveScroll: true });

    return (
        <AppShell>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer>
                <BackLink href="/aktivitas" className="mb-4">
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
                    <SendToTelegramButton
                        url={`/aktivitas/${activity.id}/telegram`}
                        retryAfterSeconds={telegramRetryAfterSeconds}
                        connected={telegramConnected}
                    />
                </div>

                {/* HERO — stats left + route map right */}
                <section className="grid items-stretch gap-4 lg:grid-cols-[1.4fr_1fr]">
                    <HeroPanel className="lg:px-9 lg:py-8">
                        <span
                            aria-hidden
                            className="pointer-events-none absolute -right-10 -top-10 h-52 w-52 rounded-full"
                            style={emberGlowStyle()}
                        />
                        <div className="relative">
                            <div className="mb-5 flex items-start gap-4">
                                <Temari pose={pose} size={72} animate={false} />
                                <div className="min-w-0 flex-1">
                                    <div className="mb-1.5 flex flex-wrap items-center gap-2">
                                        <MoodChip mood={mood} onSky />
                                        <span className="font-mono text-[11px] uppercase tracking-[0.14em] text-ink-on-sky">
                                            {formatShortDateTimeId(detail.start_date_local)}
                                        </span>
                                    </div>
                                    <h1 className="font-display text-display-sm text-cream">
                                        {detail.name ?? 'Lari'}
                                    </h1>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-5 sm:grid-cols-5">
                                <StatTile tone="plainSky" size="md" label="JARAK" value={km} unit="km" />
                                <StatTile tone="plainSky" size="md" label="DURASI" value={kartuProps.durasi} />
                                <StatTile tone="plainSky" size="md" label="PACE" value={pace} unit="/km" />
                                <StatTile tone="plainSky" size="md" label="HR" value={hr != null ? `${hr}` : '—'} unit="bpm" />
                                <StatTile tone="plainSky" size="md" label="TRIMP" value={trimp != null ? `${trimp}` : '—'} unit="Edwards" explainerKey="trimp" />
                            </div>

                            {/* KAMU VS KAMU DULU — inline in hero */}
                            {pastYou && (
                                <div className="mt-5 flex items-center justify-between gap-3 rounded-xl border border-cream/15 bg-cream/[0.08] px-4 py-3 backdrop-blur-sm">
                                    <div className="min-w-0">
                                        <div className="font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-cream/60">
                                            Kamu vs {pastYou.days_ago} hari lalu
                                        </div>
                                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-cream/90">
                                            <span className={cn('font-bold tabular-nums', pastYou.pace_diff_sec > 0 ? 'text-leaf' : 'text-citrus')}>
                                                {Math.abs(Math.round(pastYou.pace_diff_sec))}d/km {pastYou.pace_diff_sec > 0 ? 'lebih cepat' : 'lebih lambat'}
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
                                            className="focus-ring-on-sky shrink-0 rounded-full border border-cream/20 px-3 py-1.5 font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-cream/70 transition hover:border-cream/40 hover:text-cream"
                                        >
                                            Lihat →
                                        </Link>
                                    )}
                                </div>
                            )}
                        </div>
                    </HeroPanel>
                    <MapWeatherPanel detail={detail} />
                </section>

                {/* KATA TEMARI (70%) + KARTU (30%) */}
                <section className="mt-8 grid gap-6 lg:grid-cols-[7fr_3fr]">
                    <div>
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
                    </div>

                    {/* Kartu sidebar — on a sky hero panel so the card pops, mirroring
                        the Koleksi detail page. The glow is a blurred backdrop blob,
                        not a filter on the card, so the card itself stays crisp. */}
                    <div className="flex items-center justify-center">
                        {card && (
                            <div
                                className="relative flex w-full items-center justify-center overflow-hidden rounded-3xl px-6 py-10"
                                style={{ background: 'linear-gradient(165deg, var(--color-sky-deep), var(--color-sky-2))' }}
                            >
                                <span
                                    aria-hidden
                                    className="pointer-events-none absolute inset-x-0 bottom-1/4 mx-auto h-56 w-56 rounded-full"
                                    style={{
                                        background: 'radial-gradient(circle, oklch(82% 0.14 55 / 0.4), transparent 60%)',
                                        filter: 'blur(12px)',
                                    }}
                                />
                                <Link
                                    href={kartuUrl(card)}
                                    className="focus-ring relative block w-full max-w-[280px] rounded-2xl"
                                >
                                    <Kartu
                                        name={card.special_move}
                                        km={kartuProps.km}
                                        durasi={kartuProps.durasi}
                                        trimp={kartuProps.trimp}
                                        rarity={card.rarity}
                                        mood={mood}
                                        badges={(card.badges ?? []).slice(0, 3)}
                                        stats={kartuProps.stats}
                                        zonePct={kartuProps.zonePct}
                                        polyline={detail.summary_polyline}
                                        paceShape={kartuProps.paceShape}
                                        size="md"
                                    />
                                </Link>
                            </div>
                        )}
                    </div>
                </section>

                {/* DETAIL TILES */}
                <section className="mt-10">
                    <DetailTiles detail={detail} summary={summary} />
                </section>

                {/* SPLITS */}
                {perKm.length > 0 && <SplitsTable rows={perKm} className="mt-10" />}

                <footer className="mt-8 font-mono font-bold text-[11px] uppercase tracking-[0.1em] text-ink-3">
                    Tersambung otomatis dari Strava · {formatIdDate(activity.analyzed_at ?? null, 'long')}
                </footer>
            </PageContainer>
        </AppShell>
    );
}

function MapWeatherPanel({ detail }: Readonly<{ detail: DetailedActivityDetail }>) {
    const temp = detail.weather_temp_c;
    const humidity = detail.weather_humidity_pct;
    const location = detail.location_name;
    const hasPolyline = detail.summary_polyline != null && detail.summary_polyline.length > 0;
    const windSpeed = detail.weather_wind_speed_kmh;
    const gust = detail.weather_wind_gust_kmh;
    const direction = detail.weather_wind_direction_deg;
    const showGust = gust != null && windSpeed != null && gust - windSpeed >= 8;

    return (
        <div className="relative flex flex-col gap-4 overflow-hidden rounded-2xl bg-sky px-5 py-4 text-cream">
            {(temp != null || location != null) && (
                <div className="flex items-baseline gap-3">
                    {temp != null && (
                        <div>
                            <div className="font-sans text-2xl font-bold leading-none">
                                {Math.round(temp)}°<span className="text-sm font-medium">C</span>
                            </div>
                            {humidity != null && (
                                <div className="mt-1 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-on-sky">
                                    {Math.round(humidity)}% lembab
                                </div>
                            )}
                            {windSpeed != null && (
                                <div className="mt-0.5 flex items-center gap-1 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-on-sky">
                                    <Icon icon="mdi:weather-windy" width={11} height={11} aria-hidden />
                                    {Math.round(windSpeed)} km/j
                                    {showGust && <span>· gust {Math.round(gust)}</span>}
                                    {direction != null && (
                                        <Icon
                                            icon="mdi:navigation"
                                            width={10}
                                            height={10}
                                            aria-hidden
                                            style={{ transform: `rotate(${direction}deg)` }}
                                            className="text-horizon"
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                    {location != null && (
                        <div className="min-w-0 flex-1 border-l border-cream/15 pl-3">
                            <div className="truncate font-display text-base leading-tight tracking-[-0.005em]">{location}</div>
                        </div>
                    )}
                </div>
            )}
            {hasPolyline && (
                <div className="mt-3 overflow-hidden rounded-xl bg-cream/[0.04]">
                    <Suspense fallback={<div className="h-[180px] animate-pulse" />}>
                        <RouteMap polyline={detail.summary_polyline ?? ''} distanceKm={formatKm(detail.distance)} />
                    </Suspense>
                </div>
            )}
        </div>
    );
}

interface DetailTile {
    label: string;
    value: string;
    sub?: string;
    warn?: boolean;
    wide?: boolean;
    metricKey?: MetricKey;
}

function DetailTiles({
    detail,
    summary,
}: Readonly<{ detail: DetailedActivityDetail; summary: Record<string, unknown> }>) {
    const tiles: DetailTile[] = [];

    if (detail.average_heartrate != null) {
        tiles.push({ label: 'AVG HR', value: `${Math.round(detail.average_heartrate)}`, sub: 'bpm' });
    }
    if (detail.max_heartrate != null) {
        tiles.push({ label: 'MAX HR', value: `${detail.max_heartrate}`, sub: 'bpm' });
    }
    if (detail.average_cadence != null) {
        tiles.push({ label: 'CADENCE', value: `${Math.round(detail.average_cadence * 2)}`, sub: 'spm avg', metricKey: 'cadence' });
    }
    const ascent = Number(summary.ascent_m);
    if (summary.ascent_m != null && Number.isFinite(ascent)) {
        tiles.push({ label: 'ASCENT', value: `${ascent}`, sub: 'm', metricKey: 'ascent' });
    }
    const decoupling = Number(summary.decoupling_pct);
    if (summary.decoupling_pct != null && Number.isFinite(decoupling)) {
        tiles.push({
            label: 'DECOUPLING',
            value: `${decoupling >= 0 ? '+' : ''}${decoupling.toFixed(1)}%`,
            sub: 'napas melar di paruh kedua',
            warn: Math.abs(decoupling) > 8,
            wide: true,
            metricKey: 'decoupling',
        });
    }

    if (tiles.length === 0) {
        return (
            <Card tone="empty" padding="lg" className="text-center font-display text-base italic text-ink-2">
                Detail teknis-nya belum kebaca.
            </Card>
        );
    }

    return (
        <div className="grid grid-cols-2 gap-2.5">
            {tiles.map((t) => (
                <div
                    key={t.label}
                    className={cn(
                        'rounded-xl border border-cream-deep bg-cream px-4 py-3.5',
                        t.wide && 'col-span-2',
                    )}
                >
                    <div className="mb-1.5 inline-flex items-center gap-1 font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">
                        {t.label}
                        {t.metricKey && <MetricExplainer metricKey={t.metricKey} size="xs" />}
                    </div>
                    <div
                        className={cn(
                            'font-sans font-bold leading-none tabular-nums tracking-[-0.01em]',
                            t.wide ? 'text-[28px]' : 'text-[22px]',
                            t.warn ? 'text-ember' : 'text-ink',
                        )}
                    >
                        {t.value}
                    </div>
                    {t.sub && (
                        <div className="mt-1.5 font-sans text-[11px] leading-snug text-ink-3">{t.sub}</div>
                    )}
                </div>
            ))}
        </div>
    );
}

function SplitsTable({ rows, className }: Readonly<{ rows: PerKmRow[]; className?: string }>) {
    const paces = rows
        .map((r) => paceSecOf(r))
        .filter((s): s is number => s != null && Number.isFinite(s));
    const fastest = paces.length > 0 ? Math.min(...paces) : null;
    const fastestKm = fastest != null ? rows.find((r) => paceSecOf(r) === fastest)?.km ?? null : null;
    const slowestSec = paces.length > 0 ? Math.max(...paces) : null;

    return (
        <Card as="section" padding="lg" className={className}>
            <header className="mb-1.5 flex flex-wrap items-baseline justify-between gap-3">
                <SectionLabel>Splits per km</SectionLabel>
                {fastest != null && fastestKm != null && (
                    <p className="font-display text-sm italic text-ink-2">
                        Paling kenceng di km {fastestKm},{' '}
                        <span className="font-semibold text-horizon-deep">{formatPace(fastest)}/km</span>
                    </p>
                )}
            </header>
            {/* One dense chart at every width (HR + cadence columns fold away on phones);
                the binary bar color needs a one-line key once the card affordance is gone. */}
            <p className="mb-3 text-label-micro text-ink-3">Batang oranye = km tercepat, gelap = lainnya.</p>

            <div className="flex flex-col gap-1">
                {rows.map((row, idx) => {
                    const sec = paceSecOf(row);
                    const isFast = sec != null && sec === fastest;
                    const pctWidth = computeBarWidth(sec, fastest, slowestSec);
                    const rowFill = splitRowFill(isFast, idx);
                    return (
                        <div
                            key={row.km ?? `row-${idx}`}
                            className={cn(
                                'grid grid-cols-[34px_1fr_56px] items-center gap-2.5 lg:grid-cols-[40px_1fr_70px_70px_70px] lg:gap-3',
                                // Every row gets the same rounded background box + -mx-3/px-3
                                // bleed-and-realign so the fast row's alignment isn't special —
                                // only the bar color should differ (see computeBarWidth caller).
                                '-mx-3 rounded-lg px-3 py-2 lg:py-2.5',
                                rowFill,
                            )}
                        >
                            <div className="font-mono text-[11px] uppercase tracking-[0.1em] text-ink-2">
                                KM {row.km ?? '?'}
                            </div>
                            <div className="h-2.5 overflow-hidden rounded bg-sky/[0.06] lg:h-3">
                                <div
                                    className={cn('h-full rounded', isFast ? 'bg-horizon' : 'bg-sky')}
                                    style={{ width: `${pctWidth}%` }}
                                />
                            </div>
                            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink">
                                {row.pace ?? '—'}
                            </div>
                            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-2 lg:block">
                                ♡ {row.avg_hr ?? '—'}
                            </div>
                            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-2 lg:block">
                                ↻ {row.avg_cadence_spm ?? '—'}
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

// Every splits row shares the same rounded box (see SplitsTable); only this
// fill differs — horizon tint for the fastest km, a faint zebra stripe otherwise.
function splitRowFill(isFast: boolean, idx: number): string {
    if (isFast) return 'bg-horizon/[0.08]';
    if (idx % 2 === 1) return 'bg-cream-deep/30';
    return 'bg-sky/[0.03]';
}

function paceSecOf(row: PerKmRow): number | null {
    if (typeof row.pace_sec === 'number') return row.pace_sec;
    if (typeof row.pace === 'string') {
        const sec = parsePaceSec(row.pace);
        if (Number.isFinite(sec)) return sec;
    }
    return null;
}

// Per-km spread (seconds) at which the bar-width band reaches its full 50-point swing.
const FULL_SPREAD_SEC = 30;

function computeBarWidth(sec: number | null, fastest: number | null, slowest: number | null): number {
    if (sec == null || fastest == null || slowest == null || slowest === fastest) return 60;
    // Faster pace = wider bar, anchored at 90% for the fastest km. The band amplitude
    // scales with the ABSOLUTE split spread, so a run where every km is within a second
    // or two renders as near-equal full-width bars ("konsisten") instead of a misleading
    // 40→90 swing that contradicts the "pacing sangat konsisten" narration above it.
    const spread = slowest - fastest;
    const amplitude = Math.min(spread / FULL_SPREAD_SEC, 1) * 50;
    const t = (slowest - sec) / spread; // 0 (slowest) .. 1 (fastest)
    return Math.round(90 - (1 - t) * amplitude);
}

