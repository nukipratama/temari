import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';
import { daysUntilId, formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';

interface RaceCardProps {
    name: string | null;
    raceDate: string;
    distanceM: number;
    goalTimeSec: number;
    className?: string;
}

function RaceStat({
    value,
    label,
}: Readonly<{ value: string; label: string }>) {
    return (
        <div>
            <b className="block font-mono text-base font-extrabold tabular-nums text-foreground">
                {value}
            </b>
            <span className="text-label-micro text-text-2">{label}</span>
        </div>
    );
}

/** The prototype's race summary: flag + name, the countdown line, two figures. */
export default function RaceCard({
    name,
    raceDate,
    distanceM,
    goalTimeSec,
    className,
}: Readonly<RaceCardProps>) {
    const daysToGo = useCountUp(daysUntilId(raceDate));

    return (
        <Card className={className}>
            <div className="flex items-center gap-2">
                <Icon
                    icon="mdi:flag-checkered"
                    width={15}
                    height={15}
                    className="flex-none text-icon-accent"
                    aria-hidden
                />
                <b className="text-sm font-bold text-foreground">
                    {name ?? 'your race'}
                </b>
            </div>
            <p className="mt-1 font-mono text-xs tabular-nums text-text-2">
                {formatNaiveIdDate(raceDate, 'long')} · {Math.round(daysToGo)}{' '}
                days to go
            </p>
            <div className="mt-3 flex gap-6">
                <RaceStat
                    value={`${(distanceM / 1_000).toFixed(1)} km`}
                    label="Distance"
                />
                <RaceStat
                    value={formatDurationHMS(goalTimeSec)}
                    label="Goal time"
                />
            </div>
        </Card>
    );
}
