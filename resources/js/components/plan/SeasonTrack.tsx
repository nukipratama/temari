import { Link } from '@inertiajs/react';

import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formatNaiveIdDate } from '@/lib/pace';

interface SeasonTrackProps {
    /** Tiers earned in the live season: one per completed season goal. */
    earned: number;
    total: number;
    endsAt: string;
    tiersKeptFromPastSeasons: number;
}

/** The live season's reward rail, plus what a season boundary does and does not take back. */
export default function SeasonTrack({
    earned,
    total,
    endsAt,
    tiersKeptFromPastSeasons,
}: Readonly<SeasonTrackProps>) {
    const remaining = Math.max(0, total - earned);

    return (
        <Card padding="card">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <SectionLabel dot dotClass="bg-horizon" className="mb-0">
                    Season Track
                </SectionLabel>
                <p className="font-mono text-sm tabular-nums text-ink">
                    {earned}
                    <span className="text-ink-3">/{total}</span>{' '}
                    <span className="text-ink-3">tiers</span>
                </p>
            </div>

            <div
                role="img"
                aria-label={`Season track: ${earned} of ${total} tiers earned`}
                className="mt-3 flex gap-1.5"
            >
                {Array.from({ length: total }, (_, index) => (
                    <span
                        key={index}
                        className={cn(
                            'h-2 flex-1 rounded-full',
                            index < earned ? 'bg-horizon' : 'bg-sky/[0.1]',
                        )}
                    />
                ))}
            </div>

            <p className="mt-3 text-sm leading-relaxed text-ink-2">
                {remaining === 0
                    ? 'Every goal below is done, so the whole track is yours.'
                    : `One tier per goal below. ${remaining} still out there.`}{' '}
                Resets to zero on {formatNaiveIdDate(endsAt, 'short')}. Your
                cards, accessories and badges do not.{' '}
                <Link
                    href="/accessories"
                    className="focus-ring underline underline-offset-2 hover:text-ink"
                >
                    See what is still missing
                </Link>
            </p>
            {tiersKeptFromPastSeasons > 0 && (
                <Eyebrow token="micro" tone="ink-3" className="mt-3">
                    {tiersKeptFromPastSeasons} tiers kept from earlier seasons
                </Eyebrow>
            )}
        </Card>
    );
}
