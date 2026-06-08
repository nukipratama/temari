import { Icon } from '@iconify/react';
import LinkCard from '@/components/ui/LinkCard';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import { renderBold } from '@/lib/richText';
import { aktivitasUrl } from '@/lib/routes';
import { formatKm, formatPace, formatRelativeId, paceSecPerKm } from '@/lib/pace';
import { MOOD_UPPER, formatIdDateUpper, formatWeather, shortenLocation } from '@/pages/HariIni/helpers';
import type { ActivityDetail, Mood } from '@/types/inertia';

export interface LastRunNote {
    oneline: string;
    mood: Mood;
}

export default function LastLariCard({ run, pose, note }: Readonly<{ run: ActivityDetail; pose: TemariPose; note?: LastRunNote | null }>) {
    const km = formatKm(run.distance);
    const paceSec = paceSecPerKm(run.moving_time, run.distance);
    const trimp = run.trimp_edwards != null ? Math.round(run.trimp_edwards) : null;
    const dateLabel = formatRelativeId(run.start_date_local);
    const locationShort = shortenLocation(run.location_name ?? null);
    const weatherLabel = formatWeather(run.weather_temp_c ?? null, run.weather_humidity_pct ?? null, run.weather_rain_detected ?? null);

    const dateUpper = formatIdDateUpper(run.start_date_local);
    const subline = [dateUpper, note ? MOOD_UPPER[note.mood] : null].filter(Boolean).join(' · ');

    return (
        <LinkCard href={aktivitasUrl(run)} padding="md" className="flex h-full flex-col gap-3">
            <SectionLabel dot className="mb-0">Lari terakhir · {dateLabel}</SectionLabel>
            <div className="flex items-start gap-3">
                <Temari pose={pose} size={48} />
                <div className="min-w-0 flex-1">
                    <div className="font-display text-2xl leading-tight tracking-[-0.01em] text-ink">
                        {run.name ?? 'Lari'}
                    </div>
                    {subline !== '' && (
                        <div className="mt-1 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                            {subline}
                        </div>
                    )}
                    {(locationShort || weatherLabel) && (
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono font-bold text-[11px] uppercase tracking-[0.1em] text-ink-2">
                            {locationShort && (
                                <span className="inline-flex items-center gap-1">
                                    <Icon icon="mdi:map-marker-outline" width={11} height={11} aria-hidden />
                                    {locationShort}
                                </span>
                            )}
                            {locationShort && weatherLabel && <span aria-hidden>·</span>}
                            {weatherLabel && <span>{weatherLabel}</span>}
                        </div>
                    )}
                </div>
            </div>
            <div className="grid grid-cols-3 gap-3">
                <StatTile tone="plain" size="lg" align="center" label="KM" value={km} valueClassName="font-black tracking-tight text-ink" />
                <StatTile tone="plain" size="lg" align="center" label="PACE" value={paceSec != null ? `${formatPace(paceSec)}/km` : '—'} valueClassName="font-black tracking-tight text-ink" />
                <StatTile tone="plain" size="lg" align="center" label="TRIMP" value={trimp != null ? String(trimp) : '—'} valueClassName="font-black tracking-tight text-ink" />
            </div>
            {note && (
                <div className="flex flex-1 items-center gap-2 px-3 text-sm leading-relaxed text-ink-2">
                    <Icon
                        icon="mdi:comment-quote-outline"
                        width={14}
                        height={14}
                        aria-hidden
                        className="mt-0.5 shrink-0 text-leaf-deep"
                    />
                    <p className="min-w-0">{renderBold(note.oneline)}</p>
                </div>
            )}
            <span className="mt-auto font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep">
                Lihat detail lari →
            </span>
        </LinkCard>
    );
}
