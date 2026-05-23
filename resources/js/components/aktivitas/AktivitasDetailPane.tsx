import { lazy, Suspense } from 'react';
import { formatDurationHMS, formatIdDate, formatPace } from '@/lib/pace';
import TemariThread, { type ThreadEntry } from '@/components/temari/TemariThread';
import KpiTile from '@/components/dashboard/KpiTile';
import RunCard from '@/components/card/RunCard';
import PastYouStrip from '@/components/run/PastYouStrip';
import HrZoneCard, { type ZonePct } from '@/components/aktivitas/HrZoneCard';
import WeatherHero from '@/components/aktivitas/WeatherHero';
import type { Activity, ActivityDetail, AnalysisPayload, Mood, RunCard as RunCardModel, StoryLine } from '@/types/inertia';

// Leaflet + react-leaflet ~120KB combined. Defer until the page actually
// has a polyline to render.
const RouteMap = lazy(() => import('@/components/run/RouteMap'));

interface PerKmRow {
    km?: string | number;
    pace?: string;
    avg_hr?: number;
    avg_cadence_spm?: number;
}

export type DetailedActivity = Activity & {
    strava_external_id?: string | number | null;
    analyzed_at?: string | null;
    detail: ActivityDetail & {
        stream_summary?: Record<string, unknown> | null;
        max_heartrate?: number | null;
        average_cadence?: number | null;
        weather_temp_c?: number | null;
        weather_humidity_pct?: number | null;
        weather_rain_detected?: boolean | null;
    };
};

export type DetailedActivityDetail = DetailedActivity['detail'];

export interface PastYouMatch {
    past: {
        start_date_local: string | null;
        activity_id?: number | null;
        name?: string | null;
    };
    pace_diff_sec: number;
    hr_diff_bpm: number | null;
    days_ago: number;
}

interface AktivitasDetailPaneProps {
    activity: DetailedActivity;
    detail: DetailedActivityDetail;
    card: RunCardModel | null;
    storyLine: StoryLine | null;
    speechAnalysis: AnalysisPayload;
    insightTechnical: AnalysisPayload;
    insightSplits: AnalysisPayload;
    insightZones: AnalysisPayload;
    pastYou: PastYouMatch | null;
    inertiaReloadProps?: string[];
}

const DEFAULT_RELOAD_PROPS = ['speechAnalysis', 'insightTechnical', 'insightSplits', 'insightZones'];

export default function AktivitasDetailPane({
    activity,
    detail,
    card,
    storyLine,
    speechAnalysis,
    insightTechnical,
    insightSplits,
    insightZones,
    pastYou,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
}: Readonly<AktivitasDetailPaneProps>) {
    const summary = (detail.stream_summary ?? {}) as Record<string, unknown>;
    const zonePct = (summary.zone_pct as ZonePct | undefined) ?? {};
    const perKm = (summary.per_km as PerKmRow[] | undefined) ?? [];

    const paceSec =
        detail.moving_time != null && detail.distance != null && detail.distance > 0
            ? detail.moving_time / (detail.distance / 1000)
            : null;
    const paceLabel = paceSec != null ? formatPace(paceSec) : '—';

    const durationLabel = formatDurationHMS(detail.moving_time);
    const km = detail.distance != null ? (detail.distance / 1000).toFixed(2) : '—';

    const mood: Mood = storyLine?.mood ?? 'adem';

    const threadEntries: ThreadEntry[] = [
        {
            id: 'speech',
            icon: 'mdi:chat-outline',
            label: 'Cerita lari ini',
            analysis: speechAnalysis,
            tone: 'brand',
        },
        {
            id: 'technical',
            icon: 'mdi:stethoscope',
            label: 'Terjemahan teknis',
            analysis: insightTechnical,
            tone: 'accent',
        },
        {
            id: 'splits',
            icon: 'mdi:timer-outline',
            label: 'Split highlight',
            analysis: insightSplits,
            tone: 'pop',
        },
        {
            id: 'zones',
            icon: 'mdi:heart-pulse',
            label: 'HR zone',
            analysis: insightZones,
            tone: 'mood',
        },
    ];

    const hasZones = Object.keys(zonePct).length > 0;

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="grid gap-3 sm:gap-4 lg:grid-cols-5 lg:items-stretch">
                <div className="min-w-0 lg:col-span-2">
                    <DetailHeader detail={detail} />
                </div>
                <div className="grid min-w-0 grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-3 lg:col-span-3">
                    <KpiTile label="Jarak" value={km} sub="km" />
                    <KpiTile label="Pace" value={paceLabel} sub="per km" />
                    <KpiTile label="Durasi" value={durationLabel} sub="moving" />
                    <KpiTile
                        label="TRIMP"
                        value={detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : '—'}
                        sub="Edwards"
                        explainerKey="edwards_trimp"
                    />
                </div>
            </div>

            <div className="grid items-start gap-4 sm:gap-6 lg:grid-cols-5">
                <div className="min-w-0 space-y-6 lg:col-span-2">
                    {card !== null && <RunCard card={card} detail={detail} />}
                    {hasZones && <HrZoneCard zonePct={zonePct} />}
                    <PastYouStrip match={pastYou} currentDistance={detail.distance} />
                    <TemariThread
                        mood={mood}
                        moodLabel={mood}
                        entries={threadEntries}
                        inertiaReloadProps={inertiaReloadProps}
                    />
                </div>

                <div className="min-w-0 space-y-6 lg:col-span-3">
                    <WeatherHero detail={detail} />
                    {detail.summary_polyline != null && detail.summary_polyline.length > 0 && (
                        <Suspense
                            fallback={<div className="h-[280px] animate-pulse rounded-2xl bg-line/40" />}
                        >
                            <RouteMap polyline={detail.summary_polyline} />
                        </Suspense>
                    )}

                    <TechnicalSection
                        detail={detail}
                        summary={summary}
                        perKm={perKm}
                        activity={activity}
                    />
                </div>
            </div>
        </div>
    );
}

function DetailHeader({ detail }: Readonly<{ detail: DetailedActivityDetail }>) {
    const startDateLabel = formatStartDateLabel(detail.start_date_local);
    return (
        <div className="flex h-full flex-col justify-center rounded-2xl border border-line bg-surface-elev px-5 py-3 shadow-sm">
            <h1 className="text-xl font-semibold tracking-tight text-ink">{detail.name ?? 'Run'}</h1>
            <p className="mt-0.5 text-xs text-ink-3">{startDateLabel}</p>
        </div>
    );
}

interface TechnicalSectionProps {
    detail: DetailedActivityDetail;
    summary: Record<string, unknown>;
    perKm: PerKmRow[];
    activity: DetailedActivity;
}

function TechnicalSection({
    detail,
    summary,
    perKm,
    activity,
}: Readonly<TechnicalSectionProps>) {
    return (
        <section
            aria-labelledby="detail-teknis-heading"
            className="rounded-2xl border border-line bg-surface-elev"
        >
            <h2
                id="detail-teknis-heading"
                className="border-b border-line px-4 py-3 text-xs font-semibold uppercase tracking-wider text-ink-3 sm:px-5 sm:py-4"
            >
                Detail Teknis
            </h2>
            <div className="space-y-6 px-4 py-4 sm:px-5 sm:py-5">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {detail.average_heartrate != null && (
                        <KpiTile label="Avg HR" value={Math.round(detail.average_heartrate)} sub="bpm" tone="alert" />
                    )}
                    {detail.max_heartrate != null && (
                        <KpiTile label="Max HR" value={detail.max_heartrate} sub="bpm" tone="alert" />
                    )}
                    {detail.average_cadence != null && (
                        <KpiTile
                            label="Cadence"
                            value={Math.round(detail.average_cadence * 2)}
                            sub="spm avg"
                            explainerKey="cadence"
                        />
                    )}
                    {summary.decoupling_pct != null && (
                        <KpiTile
                            label="Decoupling"
                            value={`${Number(summary.decoupling_pct) >= 0 ? '+' : ''}${Number(summary.decoupling_pct).toFixed(1)}%`}
                            sub="aerobic drift"
                            explainerKey="decoupling"
                        />
                    )}
                    {summary.ascent_m != null && <KpiTile label="Ascent" value={Number(summary.ascent_m)} sub="m" />}
                    {summary.stopped_time_sec != null && (
                        <KpiTile
                            label="Stopped"
                            value={`${Number(summary.stopped_time_sec)}s`}
                            sub={`${Number(summary.stop_count ?? 0)}x`}
                        />
                    )}
                </div>

                {perKm.length > 0 && (
                    <div>
                        <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-3">Splits per KM</h3>
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full min-w-[420px] text-sm tabular-nums">
                                <thead>
                                    <tr className="text-left text-xs text-ink-3">
                                        <th className="py-2 pr-3 font-semibold">KM</th>
                                        <th className="py-2 pr-3 font-semibold">Pace</th>
                                        <th className="py-2 pr-3 font-semibold">HR</th>
                                        <th className="py-2 pr-3 font-semibold">Cadence</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {perKm.map((row) => (
                                        <tr key={row.km ?? `${row.pace}-${row.avg_hr}`} className="border-t border-line">
                                            <td className="py-1.5 pr-3 font-medium text-ink">{row.km ?? '—'}</td>
                                            <td className="py-1.5 pr-3 text-ink">{row.pace ?? '—'}</td>
                                            <td className="py-1.5 pr-3 text-ink">{row.avg_hr ?? '—'}</td>
                                            <td className="py-1.5 pr-3 text-ink">{row.avg_cadence_spm ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <p className="text-[11px] text-ink-3">
                    Strava activity ID {activity.strava_external_id ?? '—'} · ingested{' '}
                    {formatIdDate(activity.analyzed_at ?? null, 'long')}
                </p>
            </div>
        </section>
    );
}

function formatStartDateLabel(iso: string | null): string {
    if (iso === null) return '—';
    const d = new Date(iso);
    const date = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    const time = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    return `${date} · ${time}`;
}
