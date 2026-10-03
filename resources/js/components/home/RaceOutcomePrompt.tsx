import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import { Icon } from '@/components/ui/Icon';
import { formatNaiveIdDate } from '@/lib/pace';

export interface PendingRaceOutcome {
    id: number;
    name: string | null;
    race_date: string;
}

/**
 * A passed race nobody has confirmed yet: nothing is graded or counted as a
 * miss until the athlete says how it went, so Home asks once, plainly.
 */
export default function RaceOutcomePrompt({
    race,
}: Readonly<{ race: PendingRaceOutcome }>) {
    return (
        <section>
            <p className="text-base font-semibold text-foreground">
                how did {race.name ?? 'your race'} go?
            </p>
            <p className="mt-1 mb-2.5 text-xs leading-relaxed text-text-2">
                {formatNaiveIdDate(race.race_date)} has passed. confirm the run
                or enter your time, so recovery follows the race you ran. until
                then nothing counts as a miss.
            </p>
            <Link
                href="/race"
                className="focus-ring inline-flex items-center gap-1 rounded-xs text-[0.71875rem] font-bold text-icon-accent"
            >
                tell temari
                <Icon icon={ArrowRight} width={12} height={12} aria-hidden />
            </Link>
        </section>
    );
}
