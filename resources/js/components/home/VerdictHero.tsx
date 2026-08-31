import type { PastYouTrend, TrendVerdict } from '@/types/inertia';

import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { verdictHeadline, verdictSupport } from '@/lib/verdict';

/** The three outcomes the window can actually call. `not_enough_history` renders as an empty state instead. */
export type JudgedVerdict = Exclude<TrendVerdict, 'not_enough_history'>;

const TONE: Record<JudgedVerdict, string> = {
    improving: 'text-leaf-ink',
    plateaued: 'text-foreground',
    slipped: 'text-ember-ink',
};

const VERDICT_POSE: Record<JudgedVerdict, TemariPose> = {
    improving: 'proud',
    plateaued: 'observational',
    slipped: 'wobble',
};

/**
 * The home screen's answer to "am I getting better?" — the call itself, the
 * aggregate that backs it, and Temari's byline so the sentence reads as hers.
 * The matched pairs it was computed from render beneath it as evidence.
 */
export default function VerdictHero({
    trend,
    verdict,
}: Readonly<{ trend: PastYouTrend; verdict: JudgedVerdict }>) {
    return (
        <section>
            <SectionLabel dot dotClass="bg-horizon">
                You vs Past You · Last {trend.window_days} Days
            </SectionLabel>

            <p
                className={cn(
                    'font-serif italic text-quote-lg leading-tight',
                    TONE[verdict],
                )}
            >
                {verdictHeadline(trend)}
            </p>

            <p className="mt-3 font-sans text-sm leading-relaxed text-text-2">
                {verdictSupport(trend)}
            </p>

            <div className="mt-4 flex items-center gap-2">
                <Temari
                    pose={VERDICT_POSE[verdict]}
                    size={34}
                    animate={false}
                />
                <span className="font-mono text-[11px] font-semibold tracking-[0.06em] text-text-3">
                    temari
                </span>
            </div>
        </section>
    );
}
