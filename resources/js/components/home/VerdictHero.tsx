import type { PastYouTrend, TrendVerdict } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import { verdictHeadline, verdictSupport } from '@/lib/verdict';

/** The three outcomes the window can actually call. `not_enough_history` renders as an empty state instead. */
export type JudgedVerdict = Exclude<TrendVerdict, 'not_enough_history'>;

/** The prototype draws only the improving case, on `icon-accent`. The other
 *  two are real states it never had to render; they keep a tone that does not
 *  read as a celebration. */
const TONE: Record<JudgedVerdict, string> = {
    improving: 'text-icon-accent',
    plateaued: 'text-foreground',
    slipped: 'text-ember-ink',
};

/**
 * The prototype's "you vs past you" block: a mono eyebrow, the call itself as
 * a serif accent headline, and the aggregate that backs it. The matched pairs
 * it was computed from render beneath it as evidence.
 */
export default function VerdictHero({
    trend,
    verdict,
}: Readonly<{ trend: PastYouTrend; verdict: JudgedVerdict }>) {
    return (
        <section>
            <Eyebrow token="micro" className="text-foreground">
                You vs Past You · Last {trend.window_days} Days
            </Eyebrow>

            <h2
                className={cn(
                    'mt-2 text-[1.5625rem] font-semibold leading-tight',
                    TONE[verdict],
                )}
            >
                {verdictHeadline(trend)}
            </h2>

            <p className="mt-2 font-sans text-[0.8125rem] leading-relaxed text-foreground">
                {verdictSupport(trend)}
            </p>
        </section>
    );
}
