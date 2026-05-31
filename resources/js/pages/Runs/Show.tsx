import { lazy, Suspense } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import FourLensGrid from '@/components/run/FourLensGrid';
import HeroPanel from '@/components/ui/HeroPanel';
import Kartu from '@/components/card/Kartu';
import MoodChip from '@/components/ui/MoodChip';
import SectionLabel from '@/components/ui/SectionLabel';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import PastYouStrip from '@/components/run/PastYouStrip';
import { cn } from '@/lib/cn';
import PageContainer from '@/components/ui/PageContainer';
import { moodFromActivity } from '@/lib/moodFromActivity';
import { formatDurationHMS, formatIdDate, formatKm, formatPace, paceSecPerKm } from '@/lib/pace';
import { RARITY_LABELS, prettyBadge } from '@/lib/runcard';
import { emberGlowStyle } from '@/lib/styles';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    Mood,
    RunCard as RunCardModel,
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
    strava_external_id?: string | number | null;
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
    pastYou,
}: Readonly<ShowProps>) {
    const summary = (detail.stream_summary ?? {}) as Record<string, unknown>;
    const perKm = (summary.per_km as PerKmRow[] | undefined) ?? [];

    const mood: Mood = storyLine?.mood ?? moodFromActivity(detail);
    const pose: TemariPose = MOOD_TO_POSE[mood];

    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.moving_time, detail.distance);
    const pace = paceSec != null ? formatPace(paceSec) : '—';
    const hr = detail.average_heartrate != null ? Math.round(detail.average_heartrate) : null;
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const duration = formatDurationHMS(detail.moving_time);

    return (
        <AppShell>
            <Head title={detail.name ?? 'Run'} />
            <PageContainer>
                <Link
                    href="/aktivitas"
                    className="mb-5 inline-flex items-center gap-1 font-mono text-xs uppercase tracking-[0.14em] text-ink-3 transition hover:text-horizon-deep"
                >
                    <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
                    Riwayat · Jejak
                </Link>

                {/* HERO + EMBEDDED KARTU */}
                <section className="grid items-stretch gap-4 lg:grid-cols-[1.5fr_1fr]">
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
                                            {formatIdDate(detail.start_date_local, 'long')}
                                        </span>
                                    </div>
                                    <h1 className="font-display text-display-sm text-cream">
                                        {detail.name ?? 'Lari'}
                                    </h1>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-5 sm:grid-cols-4">
                                <HeroStat label="JARAK" value={km} unit="km" />
                                <HeroStat label="PACE" value={pace} unit="/km" />
                                <HeroStat label="HR" value={hr != null ? `${hr}` : '—'} unit="bpm" />
                                <HeroStat label="TRIMP" value={trimp != null ? `${trimp}` : '—'} unit="Edwards" />
                            </div>
                        </div>
                    </HeroPanel>

                    {/* EMBEDDED KARTU */}
                    <Card as="aside" padding="lg" className="flex flex-col gap-3.5">
                        <SectionLabel>Kartu buat lari ini</SectionLabel>
                        {card ? (
                            <Link
                                href={`/kartu/${card.id}`}
                                className="block"
                            >
                                <Kartu
                                    name={card.special_move}
                                    subtitle={`${detail.name ?? 'Lari'} · ${formatIdDate(detail.start_date_local, 'short')}`}
                                    km={km}
                                    durasi={duration === '—' ? '—' : duration}
                                    trimp={trimp != null ? trimp : '—'}
                                    rarity={card.rarity}
                                    tags={(card.badges ?? []).slice(0, 2).map(prettyBadge)}
                                    size="md"
                                />
                            </Link>
                        ) : (
                            <p className="font-display text-base italic text-ink-3">
                                Belum ada kartu buat lari ini.
                            </p>
                        )}
                        {card && (
                            <p className="border-t border-dashed border-cream-deep pt-3 font-display text-sm italic leading-relaxed text-ink-2">
                                “{RARITY_LABELS[card.rarity]}, aku catat karena {detail.name ?? 'lari ini'} layak.”
                            </p>
                        )}
                    </Card>
                </section>

                {/* KATA TEMARI header + 4-LENS GRID */}
                <section className="mt-10">
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
                    />
                </section>

                {/* MAP + WEATHER + DETAIL TILES */}
                <section className="mt-10 grid gap-3.5 lg:grid-cols-[1.4fr_1fr]">
                    <MapWeatherPanel detail={detail} />
                    <DetailTiles detail={detail} summary={summary} />
                </section>

                {/* KAMU VS KAMU DULU */}
                {pastYou && (
                    <section className="mt-10">
                        <PastYouStrip match={pastYou} currentDistance={detail.distance} />
                    </section>
                )}

                {/* SPLITS */}
                {perKm.length > 0 && <SplitsTable rows={perKm} className="mt-10" />}

                <footer className="mt-8 font-mono text-[11px] uppercase tracking-[0.1em] text-ink-3">
                    Strava activity {activity.strava_external_id ?? '—'} · ingested{' '}
                    {formatIdDate(activity.analyzed_at ?? null, 'long')}
                </footer>
            </PageContainer>
        </AppShell>
    );
}

function HeroStat({ label, value, unit }: Readonly<{ label: string; value: string; unit?: string }>) {
    return (
        <div>
            <div className="mb-1.5 font-mono text-[11px] uppercase tracking-[0.14em] text-ink-on-sky">{label}</div>
            <div className="font-sans text-3xl font-bold leading-none tabular-nums tracking-[-0.02em] text-cream sm:text-4xl">
                {value}
            </div>
            {unit && (
                <div className="mt-1 font-mono text-[11px] uppercase tracking-[0.12em] text-ink-on-sky">{unit}</div>
            )}
        </div>
    );
}

function MapWeatherPanel({ detail }: Readonly<{ detail: DetailedActivityDetail }>) {
    const temp = detail.weather_temp_c;
    const humidity = detail.weather_humidity_pct;
    const location = detail.location_name;
    const hasPolyline = detail.summary_polyline != null && detail.summary_polyline.length > 0;

    return (
        <div className="relative flex flex-col gap-5 overflow-hidden rounded-2xl bg-sky px-6 py-5 text-cream">
            <SectionLabel onSky>Rute lari</SectionLabel>
            {(temp != null || location != null) && (
                <div className="flex items-baseline gap-4">
                    {temp != null && (
                        <div>
                            <div className="font-sans text-4xl font-bold leading-none">
                                {Math.round(temp)}°<span className="text-lg font-medium">C</span>
                            </div>
                            {humidity != null && (
                                <div className="mt-1.5 font-mono text-[11px] uppercase tracking-[0.14em] text-ink-on-sky">
                                    {Math.round(humidity)}% LEMBAB
                                </div>
                            )}
                        </div>
                    )}
                    {location != null && (
                        <div className="flex-1 border-l border-cream/15 pl-4">
                            <div className="font-display text-lg leading-tight tracking-[-0.005em]">{location}</div>
                        </div>
                    )}
                </div>
            )}
            {hasPolyline && (
                <div className="mt-3 overflow-hidden rounded-xl bg-cream/[0.04]">
                    <Suspense fallback={<div className="h-[180px] animate-pulse" />}>
                        <RouteMap polyline={detail.summary_polyline ?? ''} />
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
        tiles.push({ label: 'CADENCE', value: `${Math.round(detail.average_cadence * 2)}`, sub: 'spm avg' });
    }
    if (summary.ascent_m != null) {
        tiles.push({ label: 'ASCENT', value: `${Number(summary.ascent_m)}`, sub: 'm' });
    }
    if (summary.decoupling_pct != null) {
        const v = Number(summary.decoupling_pct);
        tiles.push({
            label: 'DECOUPLING',
            value: `${v >= 0 ? '+' : ''}${v.toFixed(1)}%`,
            sub: 'napas melar di paruh kedua',
            warn: Math.abs(v) > 8,
            wide: true,
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
                    <div className="mb-1.5 font-mono text-[11px] uppercase tracking-[0.14em] text-ink-3">{t.label}</div>
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
    const fastestLabel = fastest != null ? `${formatPace(fastest)} di km ${fastestKm ?? '?'}` : null;

    return (
        <Card as="section" padding="lg" className={className}>
            <header className="mb-4 flex flex-wrap items-baseline justify-between gap-3">
                <SectionLabel>Splits per km</SectionLabel>
                {fastestLabel && (
                    <p className="font-display text-sm italic text-ink-2">
                        Pace paling kenceng:{' '}
                        <strong className="font-semibold not-italic text-horizon-deep">{fastestLabel}</strong>
                    </p>
                )}
            </header>

            {/* Mobile: per-km card stack. Pace is the visual lead. */}
            <div className="flex flex-col gap-2 lg:hidden">
                {rows.map((row, idx) => {
                    const sec = paceSecOf(row);
                    const isFast = sec != null && sec === fastest;
                    const pctWidth = computeBarWidth(sec, fastest, slowestSec);
                    return (
                        <div
                            key={row.km ?? `row-${idx}`}
                            className={cn(
                                'rounded-xl border px-3.5 py-3',
                                isFast
                                    ? 'border-horizon/40 bg-horizon/[0.08]'
                                    : 'border-cream-deep bg-cream',
                            )}
                        >
                            <div className="flex items-baseline justify-between gap-3">
                                <div className="font-mono text-[12px] font-semibold uppercase tracking-[0.12em] text-ink-2">
                                    KM {row.km ?? '?'}
                                </div>
                                <div className="font-mono text-2xl font-bold tabular-nums leading-none text-ink">
                                    {row.pace ?? '—'}
                                    <span className="ml-1 font-mono text-[11px] font-medium text-ink-3">/km</span>
                                </div>
                            </div>
                            <div className="mt-2 h-1.5 overflow-hidden rounded bg-sky/[0.06]">
                                <div
                                    className={cn('h-full rounded', isFast ? 'bg-horizon' : 'bg-sky')}
                                    style={{ width: `${pctWidth}%` }}
                                />
                            </div>
                            <div className="mt-2 flex items-center gap-4 font-sans text-xs tabular-nums text-ink-2">
                                <span>♡ {row.avg_hr ?? '—'}</span>
                                <span>↻ {row.avg_cadence_spm ?? '—'}</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Desktop: dense grid table. */}
            <div className="hidden flex-col lg:flex">
                {rows.map((row, idx) => {
                    const sec = paceSecOf(row);
                    const isFast = sec != null && sec === fastest;
                    const pctWidth = computeBarWidth(sec, fastest, slowestSec);
                    return (
                        <div
                            key={row.km ?? `row-${idx}`}
                            className={cn(
                                'grid grid-cols-[40px_1fr_70px_70px_70px] items-center gap-3',
                                idx > 0 && !isFast && 'border-t border-cream-deep',
                                isFast ? 'rounded-lg bg-horizon/[0.08] px-3 py-2.5' : 'px-0 py-2.5',
                            )}
                        >
                            <div className="font-mono text-[12px] uppercase tracking-[0.1em] text-ink-2">
                                KM {row.km ?? '?'}
                            </div>
                            <div className="h-2 overflow-hidden rounded bg-sky/[0.06]">
                                <div
                                    className={cn('h-full rounded', isFast ? 'bg-horizon' : 'bg-sky')}
                                    style={{ width: `${pctWidth}%` }}
                                />
                            </div>
                            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink">
                                {row.pace ?? '—'}
                            </div>
                            <div className="text-right font-sans text-xs tabular-nums text-ink-2">
                                ♡ {row.avg_hr ?? '—'}
                            </div>
                            <div className="text-right font-sans text-xs tabular-nums text-ink-2">
                                ↻ {row.avg_cadence_spm ?? '—'}
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

function paceSecOf(row: PerKmRow): number | null {
    if (typeof row.pace_sec === 'number') return row.pace_sec;
    if (typeof row.pace === 'string') {
        const parts = row.pace.split(':');
        if (parts.length === 2) {
            const m = Number(parts[0]);
            const s = Number(parts[1]);
            if (Number.isFinite(m) && Number.isFinite(s)) return m * 60 + s;
        }
    }
    return null;
}

function computeBarWidth(sec: number | null, fastest: number | null, slowest: number | null): number {
    if (sec == null || fastest == null || slowest == null || slowest === fastest) return 60;
    // Faster pace = wider bar. Map [slowest..fastest] → [40..90].
    const t = (slowest - sec) / (slowest - fastest);
    return Math.round(40 + t * 50);
}

