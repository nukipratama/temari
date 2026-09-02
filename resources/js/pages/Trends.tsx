import { Head } from '@inertiajs/react';
import { useState } from 'react';

import type { AnalysisPayload } from '@/types/inertia';

import NarrationCard from '@/components/trends/NarrationCard';
import FitnessPanel, {
    type BadgeMilestone,
    type FitnessTrendPoint,
    type StreakSummaryLike,
} from '@/components/trends/panels/FitnessPanel';
import RangeToggle, { type TrendRange } from '@/components/trends/RangeToggle';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';

interface TrendsProps {
    ctlTrend: FitnessTrendPoint[];
    badgeMilestones: BadgeMilestone[];
    streak: StreakSummaryLike;
    narration: Record<TrendRange, AnalysisPayload>;
}

/**
 * Trends, on the frozen prototype's `TrendsScreen`: four blocks only (P25) —
 * the headline, the range tabs, Temari's read, and one fitness panel. The tabs
 * really select the window every block below them reads (P3).
 */
export default function Trends({
    ctlTrend,
    badgeMilestones,
    streak,
    narration,
}: Readonly<TrendsProps>) {
    const [range, setRange] = useState<TrendRange>('12mo');

    return (
        <>
            <Head title="Trends" />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Trends
                </Eyebrow>
                <PageHero size="quote-lg" italic className="mt-2">
                    how things
                    <br />
                    <em className="italic text-icon-accent">are going.</em>
                </PageHero>
                <p className="mt-2 text-xs leading-relaxed text-text-2">
                    A year of running, read as lines rather than a list.
                </p>

                <RangeToggle
                    value={range}
                    onChange={setRange}
                    className="mt-4"
                />

                <NarrationCard analysis={narration[range]} className="mt-4" />

                <FitnessPanel
                    trend={ctlTrend}
                    milestones={badgeMilestones}
                    streak={streak}
                    range={range}
                    className="mt-4"
                />
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
