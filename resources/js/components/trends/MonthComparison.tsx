import Card from '@/components/ui/LegacyCard';
import { ctlDaysAgo, ctlNow, ctlPeak } from '@/lib/trends';

import FitnessPanel, {
    type FitnessChartAnnotations,
    type FitnessTrendPoint,
} from './panels/FitnessPanel';
import { Stat, StatDelta } from './Stat';

const CTL_MEANING =
    'your training load averaged over six weeks. up means the load you absorb is growing.';
const DAYS_AGO = 30;
const CLIMB_THRESHOLD = 2;

function trendWord(delta: number): string {
    if (delta >= CLIMB_THRESHOLD) return 'climbing';
    if (delta <= -CLIMB_THRESHOLD) return 'falling';
    return 'flat';
}

interface MonthComparisonProps {
    trend: ReadonlyArray<FitnessTrendPoint>;
    annotations?: FitnessChartAnnotations;
    className?: string;
}

/**
 * "vs a month ago" owns the fitness chart: fitness-now and a-month-ago are
 * read off the same 365-day series the chart plots, so the card and the
 * line can never disagree (direction A, #967).
 */
export default function MonthComparison({
    trend,
    annotations,
    className,
}: Readonly<MonthComparisonProps>) {
    const now = ctlNow(trend);
    const monthAgo = ctlDaysAgo(trend, DAYS_AGO);
    const peak = ctlPeak(trend);
    const delta = now !== null && monthAgo !== null ? now - monthAgo : null;

    return (
        <section className={className}>
            <h2 className="font-serif text-headline-sm text-foreground">
                vs a month ago
            </h2>
            <p className="mt-1 text-xs text-text-3">{CTL_MEANING}</p>
            <Card className="mt-2.5">
                <FitnessPanel
                    trend={trend}
                    annotations={annotations}
                    highlightDays={DAYS_AGO}
                />
                <hr className="my-4 border-border" />
                <div className="grid grid-cols-2 gap-3">
                    <Stat
                        label="fitness now"
                        value={now !== null ? now.toFixed(1) : '—'}
                        delta={
                            delta !== null ? (
                                <StatDelta value={delta} />
                            ) : undefined
                        }
                        sub={
                            monthAgo !== null
                                ? `${monthAgo.toFixed(1)} a month ago · ${trendWord(delta ?? 0)}`
                                : undefined
                        }
                    />
                    <Stat
                        label="best this year"
                        value={peak !== null ? peak.toFixed(1) : '—'}
                        delta={
                            now !== null && peak !== null ? (
                                <StatDelta value={now - peak} />
                            ) : undefined
                        }
                        sub={
                            now !== null && peak !== null
                                ? now >= peak
                                    ? 'today is the high point'
                                    : 'where today sits against it'
                                : undefined
                        }
                    />
                </div>
            </Card>
        </section>
    );
}
