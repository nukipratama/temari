import { Link } from '@inertiajs/react';

import type {
    PastYouComparison,
    PastYouTrend,
    TrendVerdict,
} from '@/types/inertia';

import Card from '@/components/ui/Card';
import EmptyPanel from '@/components/ui/EmptyPanel';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formatShortDateId } from '@/lib/pace';
import { activityUrl } from '@/lib/routes';

const HEADLINE: Record<Exclude<TrendVerdict, 'not_enough_history'>, string> = {
    improving: 'You are getting faster',
    plateaued: 'You are holding steady',
    slipped: 'You have slipped a little',
};

const TONE: Record<Exclude<TrendVerdict, 'not_enough_history'>, string> = {
    improving: 'text-leaf-deep',
    plateaued: 'text-ink-2',
    slipped: 'text-ember-deep',
};

function paceLine(comparison: PastYouComparison): string {
    const seconds = Math.abs(Math.round(comparison.pace_delta_sec));
    if (seconds === 0) {
        return 'same pace';
    }
    return `${seconds} sec/km ${comparison.pace_delta_sec > 0 ? 'faster' : 'slower'}`;
}

function heartRateLine(comparison: PastYouComparison): string | null {
    if (comparison.hr_delta_bpm === null) {
        return null;
    }
    const beats = Math.abs(Math.round(comparison.hr_delta_bpm));
    if (beats === 0) {
        return null;
    }
    return `${beats} bpm ${comparison.hr_delta_bpm < 0 ? 'lower' : 'higher'}`;
}

const DIRECTION_DOT: Record<PastYouComparison['direction'], string> = {
    better: 'bg-leaf',
    flat: 'bg-stone',
    worse: 'bg-ember',
};

export default function PastYouTrendCard({
    trend,
}: Readonly<{ trend: PastYouTrend }>) {
    if (trend.verdict === 'not_enough_history') {
        return (
            <EmptyPanel
                className="mt-0"
                pose="reading"
                title="No comparable run yet"
                body={`Past You needs two similar runs at least ${trend.window_days} days apart before it will call anything. Keep running and it will fill in.`}
            />
        );
    }

    const meanPace = trend.mean_pace_delta_sec;

    return (
        <Card as="section" padding="lg">
            <SectionLabel dot dotClass="bg-horizon">
                You vs Past You · last {trend.window_days} days
            </SectionLabel>

            <p
                className={cn(
                    'font-display italic text-headline-sm',
                    TONE[trend.verdict],
                )}
            >
                {HEADLINE[trend.verdict]}
            </p>

            {meanPace !== null && (
                <p className="mt-2 font-sans text-sm leading-relaxed text-ink-2">
                    Across {trend.comparison_count} comparable runs you averaged{' '}
                    <span className="tabular-nums">
                        {Math.abs(meanPace).toFixed(1)}
                    </span>{' '}
                    sec/km {meanPace >= 0 ? 'faster' : 'slower'} than the runs
                    they were matched against.
                </p>
            )}

            <ul className="mt-4 flex flex-col gap-2">
                {trend.comparisons.map((comparison) => {
                    const hr = heartRateLine(comparison);
                    return (
                        <li key={comparison.current.activity_id}>
                            <Link
                                href={activityUrl(comparison.current)}
                                className="flex items-center gap-3 rounded-xl bg-surface-sunken px-3 py-2"
                            >
                                <span
                                    className={cn(
                                        'size-2 shrink-0 rounded-full',
                                        DIRECTION_DOT[comparison.direction],
                                    )}
                                    aria-hidden="true"
                                />
                                <span className="font-mono text-xs uppercase tracking-wider text-ink-3">
                                    {formatShortDateId(comparison.current.date)}
                                </span>
                                <span className="ml-auto text-right font-sans text-sm text-ink tabular-nums">
                                    {paceLine(comparison)}
                                    {hr && (
                                        <span className="block text-xs text-ink-3">
                                            {hr}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>

            <p className="mt-3 text-xs text-ink-3">
                Matched against your own runs from{' '}
                {trend.comparisons
                    .map((comparison) =>
                        formatShortDateId(comparison.past.date),
                    )
                    .join(', ')}
                .
            </p>
        </Card>
    );
}
