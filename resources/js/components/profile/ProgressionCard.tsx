import { useState } from 'react';

import JourneyChart from '@/components/profile/JourneyChart';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import LegacyCard from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';
import { formatDurationHMS } from '@/lib/pace';
import { PR_CATEGORY_LABELS } from '@/lib/pr';
import { outlineChipVariants } from '@/lib/variants';

export interface ProgressionSeries {
    category: string;
    weeks: string[];
    times_sec: Array<number | null>;
    goal_sec: number | null;
}

const TABS = ['5km', '10km', 'half_marathon', 'marathon'] as const;
const TAB_LABEL: Record<(typeof TABS)[number], string> = {
    '5km': '5K',
    '10km': '10K',
    half_marathon: 'HM',
    marathon: 'FM',
};

/**
 * How one distance has moved over the season: a then/now headline, the gap in
 * words, and the journey chart underneath. The distance pills only offer
 * distances the athlete actually has times at — the prototype draws all four
 * because it has no data to be missing.
 */
export default function ProgressionCard({
    byCategory,
}: Readonly<{ byCategory: Record<string, ProgressionSeries> }>) {
    const tabs = TABS.filter((c) => byCategory[c]);
    const [selected, setSelected] = useState<string>(tabs.at(-1) ?? tabs[0]);
    const series = byCategory[selected] ?? byCategory[tabs[0]];

    const times = series.times_sec.filter((t): t is number => t != null);
    const worst = times.length > 0 ? Math.max(...times) : 0;
    const best = times.length > 0 ? Math.min(...times) : 0;
    const delta = Math.max(0, worst - best);
    const label = PR_CATEGORY_LABELS[series.category] ?? series.category;

    const worstCount = useCountUp(worst);
    const bestCount = useCountUp(best);
    const deltaCount = useCountUp(delta);

    return (
        <LegacyCard as="section">
            {tabs.length > 1 && (
                <div
                    className="mb-3.5 flex flex-wrap gap-1.5"
                    role="tablist"
                    aria-label="Choose distance"
                >
                    {tabs.map((c) => (
                        <button
                            key={c}
                            type="button"
                            role="tab"
                            aria-selected={c === selected}
                            onClick={() => setSelected(c)}
                            className={outlineChipVariants({
                                selected: c === selected,
                            })}
                        >
                            {TAB_LABEL[c]}
                        </button>
                    ))}
                </div>
            )}

            <Eyebrow token="micro" tone="ink-3">
                {`Journey · ${label}`}
            </Eyebrow>
            <p className="mt-1 font-serif text-headline-sm text-foreground">
                Then{' '}
                <em className="italic">
                    {formatDurationHMS(Math.round(worstCount))}
                </em>
                , now{' '}
                <em className="italic text-horizon-ink">
                    {formatDurationHMS(Math.round(bestCount))}
                </em>
            </p>
            {delta > 0 && (
                <p className="mt-2 text-sm leading-relaxed text-text-2">
                    {`“${formatDurationHMS(Math.round(deltaCount))} faster over ${series.weeks.length} weeks.”`}
                </p>
            )}
            <div className="mt-2.5 flex flex-wrap gap-1.5">
                <Chip>{`−${formatDurationHMS(Math.round(deltaCount))} total`}</Chip>
                {series.goal_sec != null && (
                    <Chip tone="horizon">{`goal: sub-${formatDurationHMS(series.goal_sec)}`}</Chip>
                )}
            </div>

            <JourneyChart
                key={selected}
                weeks={series.weeks}
                timesSec={series.times_sec}
            />
        </LegacyCard>
    );
}
