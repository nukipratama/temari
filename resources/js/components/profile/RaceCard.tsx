import type { ActiveRace } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import LinkCard from '@/components/ui/LinkCard';
import { daysUntilId, formatShortDateId } from '@/lib/pace';

/**
 * The race the athlete is training for, or the prompt to set one. Both states
 * are the same link into `/race`, which is where either is acted on.
 */
export default function RaceCard({
    race,
}: Readonly<{ race: ActiveRace | null }>) {
    if (race === null) {
        return (
            <LinkCard
                href="/race"
                className="pressable flex items-center justify-between gap-2.5 transition hover:border-horizon/60"
            >
                <span className="flex items-center gap-2 text-sm font-bold text-foreground">
                    <Icon
                        icon="mdi:flag-checkered"
                        width={15}
                        height={15}
                        aria-hidden
                    />
                    Got a race coming up?
                </span>
                <span className="text-label-micro text-text-3">
                    Set your race &rarr;
                </span>
            </LinkCard>
        );
    }

    const days = daysUntilId(race.race_date);

    return (
        <LinkCard
            href="/race"
            className="pressable flex items-center gap-3 transition hover:border-horizon/60"
        >
            <Icon
                icon="mdi:flag-checkered"
                width={20}
                height={20}
                className="flex-none text-horizon-ink"
                aria-hidden
            />
            <div className="min-w-0 flex-1">
                <b className="block truncate text-sm font-bold text-foreground">
                    {race.name ?? 'Your race'}
                </b>
                <span className="mt-0.5 block text-label-micro text-text-2">
                    {(race.distance_m / 1000).toFixed(1)} km ·{' '}
                    {formatShortDateId(race.race_date)}
                </span>
            </div>
            <div className="flex-none text-center">
                <b className="block font-mono text-lg font-bold leading-none tabular-nums text-horizon-ink">
                    {days}
                </b>
                <span className="mt-0.5 block text-label-micro text-text-2">
                    {days === 1 ? 'day' : 'days'}
                </span>
            </div>
        </LinkCard>
    );
}
