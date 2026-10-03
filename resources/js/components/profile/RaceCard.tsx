import { Link } from '@inertiajs/react';
import { Flag } from 'lucide-react';

import type { ActiveRace } from '@/types/inertia';

import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import { daysUntilId, formatShortDateId, useTodayIso } from '@/lib/pace';

/**
 * The race the athlete is training for, or the prompt to set one. Both states
 * are the same link into `/race`, which is where either is acted on.
 */
export default function RaceCard({
    race,
}: Readonly<{ race: ActiveRace | null }>) {
    const today = useTodayIso();

    if (race === null) {
        return (
            <Card
                render={<Link href="/race" />}
                className="pressable focus-ring flex items-center justify-between gap-2.5 transition hover:border-horizon/60"
            >
                <span className="flex items-center gap-2 text-sm font-bold text-foreground">
                    <Icon icon={Flag} width={15} height={15} aria-hidden />
                    got a race coming up?
                </span>
                <span className="text-label-micro text-text-3">
                    Set your race &rarr;
                </span>
            </Card>
        );
    }

    const days = daysUntilId(race.race_date, today);

    return (
        <Card
            render={<Link href="/race" />}
            className="pressable focus-ring flex items-center gap-3 transition hover:border-horizon/60"
        >
            <Icon
                icon={Flag}
                width={20}
                height={20}
                className="flex-none text-horizon-ink"
                aria-hidden
            />
            <div className="min-w-0 flex-1">
                <b className="block truncate text-sm font-bold text-foreground">
                    {race.name ?? 'your race'}
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
        </Card>
    );
}
