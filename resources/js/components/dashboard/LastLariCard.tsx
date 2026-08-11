import { Icon } from '@iconify/react';

import type { ActivityDetail, Mood } from '@/types/inertia';

import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import Eyebrow from '@/components/ui/Eyebrow';
import LinkCard from '@/components/ui/LinkCard';
import MoodChip from '@/components/ui/MoodChip';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import {
    formatKm,
    formatPace,
    formatNaiveRelativeId,
    formatNaiveTimeId,
    paceSecPerKm,
} from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { activityUrl } from '@/lib/routes';
import {
    formatIdDateUpper,
    formatWeather,
    shortenLocation,
} from '@/pages/Today/helpers';

export interface LastRunNote {
    oneline: string;
    mood: Mood;
}

export default function LastLariCard({
    run,
    pose,
    note,
}: Readonly<{
    run: ActivityDetail;
    pose: TemariPose;
    note?: LastRunNote | null;
}>) {
    const km = formatKm(run.distance);
    const paceSec = paceSecPerKm(run.elapsed_time, run.distance);
    const trimp =
        run.trimp_edwards != null ? Math.round(run.trimp_edwards) : null;
    const dateLabel = formatNaiveRelativeId(run.start_date_local);
    const locationShort = shortenLocation(run.location_name ?? null);
    const weatherLabel = formatWeather(
        run.weather_temp_c ?? null,
        run.weather_humidity_pct ?? null,
        run.weather_rain_detected ?? null,
    );

    const dateUpper = formatIdDateUpper(run.start_date_local);
    const timeLabel = formatNaiveTimeId(run.start_date_local);

    return (
        <LinkCard
            href={activityUrl(run)}
            padding="md"
            className="flex h-full flex-col gap-3"
        >
            <SectionLabel dot className="mb-0">
                Last run · {dateLabel}
            </SectionLabel>
            <div className="flex items-start gap-3">
                <Temari pose={pose} size={48} />
                <div className="min-w-0 flex-1">
                    <div className="font-display text-2xl leading-tight tracking-[-0.01em] text-ink">
                        {run.name ?? 'Run'}
                    </div>
                    {dateUpper && (
                        <Eyebrow
                            token="micro"
                            tone="ink-2"
                            className="mt-1 flex flex-wrap items-center gap-x-2"
                        >
                            <span>{dateUpper}</span>
                            {timeLabel && (
                                <>
                                    <span aria-hidden>·</span>
                                    <span>{timeLabel}</span>
                                </>
                            )}
                            {note && (
                                <>
                                    <span aria-hidden>·</span>
                                    <MoodChip mood={note.mood} size="sm" />
                                </>
                            )}
                        </Eyebrow>
                    )}
                    {(locationShort || weatherLabel) && (
                        <Eyebrow
                            token="micro"
                            tone="ink-2"
                            className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5"
                        >
                            {locationShort && (
                                <span className="inline-flex items-center gap-1">
                                    <Icon
                                        icon="mdi:map-marker-outline"
                                        width={11}
                                        height={11}
                                        aria-hidden
                                    />
                                    {locationShort}
                                </span>
                            )}
                            {locationShort && weatherLabel && (
                                <span aria-hidden>·</span>
                            )}
                            {weatherLabel && <span>{weatherLabel}</span>}
                        </Eyebrow>
                    )}
                </div>
            </div>
            <div className="grid grid-cols-3 gap-3">
                <StatTile
                    tone="plain"
                    size="lg"
                    align="center"
                    label="KM"
                    value={km}
                    valueClassName="font-black tracking-tight text-ink"
                />
                <StatTile
                    tone="plain"
                    size="lg"
                    align="center"
                    label="PACE"
                    value={paceSec != null ? `${formatPace(paceSec)}/km` : '—'}
                    valueClassName="font-black tracking-tight text-ink"
                />
                <StatTile
                    tone="plain"
                    size="lg"
                    align="center"
                    label="TRIMP"
                    value={trimp != null ? String(trimp) : '—'}
                    explainerKey="trimp"
                    valueClassName="font-black tracking-tight text-ink"
                />
            </div>
            {note && (
                <div className="flex items-start gap-2 px-3 text-sm leading-relaxed text-ink-2">
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
            <Eyebrow
                as="span"
                token="micro"
                tone="horizon-deep"
                className="mt-auto"
            >
                View run detail →
            </Eyebrow>
        </LinkCard>
    );
}
