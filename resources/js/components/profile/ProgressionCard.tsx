import { useState } from 'react';

import JourneyChart from '@/components/profile/JourneyChart';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useCountUp } from '@/hooks/useCountUp';
import { formatDurationHMS } from '@/lib/pace';
import { PR_CATEGORY_LABELS } from '@/lib/pr';

export interface ProgressionProgress {
    relation: 'faster' | 'slower' | 'flat';
    delta_sec: number;
    from_sec: number;
    to_sec: number;
    weeks: number;
}

export interface ProgressionSeries {
    category: string;
    weeks: string[];
    times_sec: Array<number | null>;
    activity_ids: Array<number | null>;
    goal_sec: number | null;
    progress: ProgressionProgress | null;
}

const TOTAL_SIGN: Record<ProgressionProgress['relation'], string | null> = {
    faster: '−',
    slower: '+',
    flat: null,
};

function gapLine(progress: ProgressionProgress, delta: string): string {
    return progress.relation === 'flat'
        ? `“holding steady over ${progress.weeks} weeks.”`
        : `“${delta} ${progress.relation} over ${progress.weeks} weeks.”`;
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
    const [selected, setSelected] = useState<string>(
        () =>
            tabs.find((c) => byCategory[c].goal_sec != null) ??
            tabs.filter((c) => byCategory[c].progress != null).at(-1) ??
            tabs.at(-1) ??
            tabs[0],
    );
    const series = byCategory[selected] ?? byCategory[tabs[0]];

    const progress = series.progress;
    const totalSign = progress ? TOTAL_SIGN[progress.relation] : null;
    const label = PR_CATEGORY_LABELS[series.category] ?? series.category;

    const fromCount = useCountUp(progress?.from_sec ?? 0);
    const toCount = useCountUp(progress?.to_sec ?? 0);
    const deltaCount = useCountUp(progress?.delta_sec ?? 0);
    const delta = formatDurationHMS(Math.round(deltaCount));

    return (
        <section>
            {tabs.length > 1 && (
                <ToggleGroup
                    value={selected}
                    onValueChange={setSelected}
                    aria-label="Choose distance"
                    className="mb-3.5"
                >
                    {tabs.map((c) => (
                        <ToggleGroupItem key={c} value={c}>
                            {TAB_LABEL[c]}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            )}

            <Eyebrow token="micro" tone="ink-3">
                {`Journey · ${label}`}
            </Eyebrow>
            {progress && (
                <>
                    <p className="mt-1 flex items-baseline gap-1.5">
                        <span className="text-stat">
                            {formatDurationHMS(Math.round(toCount))}
                        </span>
                        <span className="text-meta">
                            from {formatDurationHMS(Math.round(fromCount))}
                        </span>
                    </p>
                    <p className="mt-2 text-sm leading-relaxed text-text-2">
                        {gapLine(progress, delta)}
                    </p>
                </>
            )}
            {(totalSign != null || series.goal_sec != null) && (
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                    {totalSign != null && (
                        <Chip>{`${totalSign}${delta} total`}</Chip>
                    )}
                    {series.goal_sec != null && (
                        <Chip tone="horizon">{`goal: sub-${formatDurationHMS(series.goal_sec)}`}</Chip>
                    )}
                </div>
            )}

            <JourneyChart
                key={selected}
                weeks={series.weeks}
                timesSec={series.times_sec}
                activityIds={series.activity_ids}
            />
        </section>
    );
}
