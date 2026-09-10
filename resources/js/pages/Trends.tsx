import { Deferred, Head } from '@inertiajs/react';
import { useState } from 'react';

import type {
    AnalysisPayload,
    BriefingResult,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import TrainingLoadCard from '@/components/dashboard/TrainingLoadCard';
import VitalBars from '@/components/dashboard/VitalBars';
import NarrationCard from '@/components/trends/NarrationCard';
import FitnessPanel, {
    type BadgeMilestone,
    type FitnessTrendPoint,
    type StreakSummaryLike,
} from '@/components/trends/panels/FitnessPanel';
import RangeToggle, { type TrendRange } from '@/components/trends/RangeToggle';
import Eyebrow from '@/components/ui/Eyebrow';
import Card from '@/components/ui/LegacyCard';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import {
    SkeletonChart,
    SkeletonProse,
    SkeletonStats,
} from '@/components/ui/Skeleton';
import { appLayout } from '@/layouts/appLayout';

interface TrendsProps {
    ctlTrend?: FitnessTrendPoint[];
    badgeMilestones?: BadgeMilestone[];
    streak?: StreakSummaryLike;
    narration?: Record<TrendRange, AnalysisPayload>;
    briefing?: BriefingResult;
    load?: TrainingLoad | null;
    snapshot?: WeeklySnapshot | null;
}

/**
 * Trends, on the frozen prototype's `TrendsScreen`: the headline, the load
 * section (vitals and condition, which used to sit behind Home's stats
 * disclosure), the range tabs, Temari's read, and one fitness panel. The tabs
 * really select the window every block below them reads (P3) — the load
 * section sits above them and always reads the last 7 days.
 */
export default function Trends({
    ctlTrend,
    badgeMilestones,
    streak,
    narration,
    briefing,
    load = null,
    snapshot = null,
}: Readonly<TrendsProps>) {
    const [range, setRange] = useState<TrendRange>('30d');

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

                <Deferred
                    data={['briefing', 'load', 'snapshot']}
                    fallback={
                        <Card as="section" className="mt-4">
                            <SkeletonStats className="mt-3.5" />
                        </Card>
                    }
                >
                    {() => (
                        <section className="mt-4">
                            <Eyebrow token="micro" className="text-foreground">
                                load
                            </Eyebrow>
                            <div className="mt-2 grid gap-2 md:grid-cols-2">
                                <Card padding="panel">
                                    <VitalBars
                                        briefing={briefing!}
                                        load={load}
                                    />
                                </Card>
                                <TrainingLoadCard
                                    load={load}
                                    snapshot={snapshot}
                                />
                            </div>
                        </section>
                    )}
                </Deferred>

                <RangeToggle
                    value={range}
                    onChange={setRange}
                    className="mt-4"
                />

                <Deferred
                    data="narration"
                    fallback={
                        <Card as="section" tone="narration" className="mt-4">
                            <SkeletonProse />
                        </Card>
                    }
                >
                    {() => (
                        <NarrationCard
                            analysis={narration![range]}
                            className="mt-4"
                        />
                    )}
                </Deferred>

                <Deferred
                    data={['ctlTrend', 'badgeMilestones', 'streak']}
                    fallback={
                        <Card as="section" className="mt-4">
                            <SkeletonStats className="mt-3.5" />
                            <SkeletonChart className="mt-3.5 h-[168px]" />
                        </Card>
                    }
                >
                    {() => (
                        <FitnessPanel
                            trend={ctlTrend!}
                            milestones={badgeMilestones!}
                            streak={streak!}
                            range={range}
                            className="mt-4"
                        />
                    )}
                </Deferred>
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
