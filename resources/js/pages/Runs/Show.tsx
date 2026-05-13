import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatIdDate, formatPace } from '@/lib/pace';
import AppShell from '@/layouts/AppShell';
import TemariBubble from '@/components/temari/TemariBubble';
import KpiTile from '@/components/dashboard/KpiTile';
import RunCard from '@/components/card/RunCard';
import PastYouStrip from '@/components/run/PastYouStrip';
import type { Activity, ActivityDetail, RunCard as RunCardModel, StoryLine } from '@/types/inertia';

interface RunsShowProps {
    activity: Activity & {
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
    detail: ActivityDetail & {
        stream_summary?: Record<string, unknown> | null;
        max_heartrate?: number | null;
        average_cadence?: number | null;
        weather_temp_c?: number | null;
        weather_humidity_pct?: number | null;
        weather_rain_detected?: boolean | null;
    };
    card: RunCardModel | null;
    storyLine: StoryLine | null;
    storyVariations: string[];
    pastYou: {
        past: { start_date_local: string | null };
        pace_diff_sec: number;
        hr_diff_bpm: number | null;
        days_ago: number;
    } | null;
}

interface ZonePct {
    Z1?: number;
    Z2?: number;
    Z3?: number;
    Z4?: number;
    Z5?: number;
}

interface PerKmRow {
    km?: string | number;
    pace?: string;
    avg_hr?: number;
    avg_cadence_spm?: number;
}

export default function RunsShow({ activity, detail, card, storyLine, storyVariations, pastYou }: Readonly<RunsShowProps>) {
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
    const startDateLabel = formatStartDateLabel(detail.start_date_local);

    return (
        <AppShell>
            <Head title={detail.name ?? 'Run'} />
            <main className="mx-auto max-w-4xl px-6 py-10">
                <div className="mb-4">
                    <Link
                        href="/runs"
                        className="inline-flex items-center gap-1 text-sm text-ink-soft transition hover:text-brand-600 dark:text-ink-soft-dark"
                    >
                        <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
                        Semua aktivitas
                    </Link>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">{detail.name ?? 'Run'}</h1>
                    <p className="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{startDateLabel}</p>
                    {(detail.weather_temp_c != null || detail.weather_rain_detected === true) && (
                        <div className="mt-2 inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-soft dark:text-ink-soft-dark">
                            {detail.weather_temp_c != null && (
                                <span
                                    className={cn(
                                        'inline-flex items-center gap-1',
                                        detail.weather_temp_c >= 31 ? 'font-semibold text-mood-squished' : '',
                                    )}
                                >
                                    <Icon icon="mdi:thermometer" width={14} height={14} aria-hidden />
                                    {detail.weather_temp_c}°C
                                </span>
                            )}
                            {detail.weather_humidity_pct != null && (
                                <span className="inline-flex items-center gap-1">
                                    <Icon icon="mdi:water-percent" width={14} height={14} aria-hidden />
                                    {detail.weather_humidity_pct}%
                                </span>
                            )}
                            {detail.weather_rain_detected === true && (
                                <span className="inline-flex items-center gap-1 font-semibold text-mood-spinning">
                                    <Icon icon="mdi:weather-rainy" width={14} height={14} aria-hidden />
                                    Hujan
                                </span>
                            )}
                        </div>
                    )}
                </div>

                <TemariBubble line={storyLine} variations={storyVariations} size="lg" className="mb-6" />

                <section className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                    <KpiTile label="Jarak" value={km} sub="km" />
                    <KpiTile label="Pace" value={paceLabel} sub="per km" />
                    <KpiTile label="Durasi" value={durationLabel} sub="moving" />
                    <KpiTile
                        label="TRIMP"
                        value={detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : '—'}
                        sub="Edwards"
                    />
                </section>

                <section className="mb-6 grid gap-4 md:grid-cols-2">
                    {card !== null && <RunCard card={card} detail={detail} />}
                    <PastYouStrip match={pastYou} currentDistance={detail.distance} />
                </section>

                <details className="rounded-2xl border border-line bg-surface-elev dark:border-line-dark dark:bg-surface-dark-elev">
                    <summary className="flex cursor-pointer items-center justify-between gap-2 px-5 py-4 text-sm font-semibold text-ink dark:text-ink-dark">
                        <span>Detail teknis</span>
                        <Icon icon="mdi:chevron-down" width={20} height={20} aria-hidden />
                    </summary>
                    <div className="space-y-6 border-t border-line px-5 py-5 dark:border-line-dark">
                        {Object.keys(zonePct).length > 0 && (
                            <div>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                                    HR Zones
                                </h3>
                                <div className="mt-3 flex h-3 overflow-hidden rounded-full">
                                    {(['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const).map((zone) => {
                                        const width = Number(zonePct[zone] ?? 0);
                                        if (width <= 0) return null;
                                        const colors: Record<typeof zone, string> = {
                                            Z1: '#5b9c7c',
                                            Z2: '#a3e635',
                                            Z3: '#f4a93b',
                                            Z4: '#e2783c',
                                            Z5: '#c84f4f',
                                        };
                                        return (
                                            <div
                                                key={zone}
                                                style={{ width: `${width}%`, background: colors[zone] }}
                                                title={`${zone}: ${width}%`}
                                            />
                                        );
                                    })}
                                </div>
                                <dl className="mt-3 grid grid-cols-5 gap-2 text-xs tabular-nums">
                                    {(['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const).map((zone) => (
                                        <div key={zone}>
                                            <dt className="text-ink-soft dark:text-ink-soft-dark">{zone}</dt>
                                            <dd className="font-semibold text-ink dark:text-ink-dark">
                                                {zonePct[zone] ?? 0}%
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        )}

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {detail.average_heartrate != null && (
                                <KpiTile
                                    label="Avg HR"
                                    value={Math.round(detail.average_heartrate)}
                                    sub="bpm"
                                    tone="alert"
                                />
                            )}
                            {detail.max_heartrate != null && (
                                <KpiTile label="Max HR" value={detail.max_heartrate} sub="bpm" tone="alert" />
                            )}
                            {detail.average_cadence != null && (
                                <KpiTile
                                    label="Cadence"
                                    value={Math.round(detail.average_cadence * 2)}
                                    sub="spm avg"
                                />
                            )}
                            {summary.decoupling_pct != null && (
                                <KpiTile
                                    label="Decoupling"
                                    value={`${(Number(summary.decoupling_pct) >= 0 ? '+' : '')}${Number(summary.decoupling_pct).toFixed(1)}%`}
                                    sub="aerobic drift"
                                />
                            )}
                            {summary.ascent_m != null && (
                                <KpiTile label="Ascent" value={Number(summary.ascent_m)} sub="m" />
                            )}
                            {summary.stopped_time_sec != null && (
                                <KpiTile
                                    label="Stopped"
                                    value={`${Number(summary.stopped_time_sec)}s`}
                                    sub={`${Number(summary.stop_count ?? 0)}x`}
                                />
                            )}
                            {detail.weather_temp_c != null && (
                                <KpiTile
                                    label="Cuaca"
                                    value={`${detail.weather_temp_c}°C`}
                                    sub={`${detail.weather_humidity_pct ?? '—'}% humidity`}
                                />
                            )}
                        </div>

                        {perKm.length > 0 && (
                            <div>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                                    Splits per KM
                                </h3>
                                <table className="mt-3 w-full text-sm tabular-nums">
                                    <thead>
                                        <tr className="text-left text-xs text-ink-soft dark:text-ink-soft-dark">
                                            <th className="py-2 pr-3 font-semibold">KM</th>
                                            <th className="py-2 pr-3 font-semibold">Pace</th>
                                            <th className="py-2 pr-3 font-semibold">HR</th>
                                            <th className="py-2 pr-3 font-semibold">Cadence</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {perKm.map((row, i) => (
                                            <tr key={i} className="border-t border-line dark:border-line-dark">
                                                <td className="py-1.5 pr-3 font-medium text-ink dark:text-ink-dark">{row.km ?? '—'}</td>
                                                <td className="py-1.5 pr-3 text-ink dark:text-ink-dark">{row.pace ?? '—'}</td>
                                                <td className="py-1.5 pr-3 text-ink dark:text-ink-dark">{row.avg_hr ?? '—'}</td>
                                                <td className="py-1.5 pr-3 text-ink dark:text-ink-dark">{row.avg_cadence_spm ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <p className="text-[11px] text-ink-soft dark:text-ink-soft-dark">
                            Strava activity ID {activity.strava_external_id ?? '—'} · ingested {formatIdDate(activity.analyzed_at ?? null, 'long')}
                        </p>
                    </div>
                </details>
            </main>
        </AppShell>
    );
}

function formatStartDateLabel(iso: string | null): string {
    if (iso === null) return '—';
    const d = new Date(iso);
    const date = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    const time = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    return `${date} · ${time}`;
}
