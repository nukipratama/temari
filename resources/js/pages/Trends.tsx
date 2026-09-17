import { Deferred, Head, usePage } from '@inertiajs/react';

import type {
    FitnessChartAnnotations,
    FitnessTrendPoint,
} from '@/components/trends/panels/FitnessPanel';
import type {
    AnalysisPayload,
    SharedProps,
    TrainingLoad,
    WeekComparison as WeekComparisonPayload,
} from '@/types/inertia';

import MonthComparison from '@/components/trends/MonthComparison';
import NarrationCard from '@/components/trends/NarrationCard';
import RaceComparison from '@/components/trends/RaceComparison';
import WeekComparison from '@/components/trends/WeekComparison';
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
    narration?: AnalysisPayload;
    weekComparison?: WeekComparisonPayload;
    load?: TrainingLoad | null;
    chartAnnotations?: FitnessChartAnnotations;
}

/**
 * Trends answers one question: "am I getting fitter, and at what cost?"
 * Temari's 7-day verdict runs first — the only place a call is stated —
 * then three stacked comparisons carry the evidence, in the order a runner
 * would ask for it: vs last week, vs a month ago (the fitness chart lives
 * here), then vs race day (or, with no race, vs the athlete's own year).
 * Direction A of the #914 design round, filed as #967. Replaces the range
 * toggle, badges and streak (profile and run pages keep those) and the ATL
 * line.
 */
export default function Trends({
    ctlTrend,
    narration,
    weekComparison,
    load,
    chartAnnotations,
}: Readonly<TrendsProps>) {
    const { activeRace } = usePage<SharedProps>().props;

    return (
        <>
            <Head title="Trends" />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Trends
                </Eyebrow>
                <PageHero size="quote-lg" italic className="mt-2">
                    am I getting fitter,
                    <br />
                    <em className="italic text-icon-accent">
                        and at what cost?
                    </em>
                </PageHero>

                <Deferred
                    data="narration"
                    fallback={
                        <Card as="section" tone="narration" className="mt-4">
                            <SkeletonProse />
                        </Card>
                    }
                >
                    {() => (
                        <NarrationCard analysis={narration!} className="mt-4" />
                    )}
                </Deferred>

                <Deferred
                    data={['weekComparison', 'load']}
                    fallback={
                        <div className="mt-7">
                            <div className="h-4 w-32 rounded bg-muted" />
                            <Card as="section" className="mt-2.5">
                                <SkeletonStats className="mt-1" />
                            </Card>
                        </div>
                    }
                >
                    {() => (
                        <WeekComparison
                            weekComparison={weekComparison!}
                            load={load ?? null}
                            className="mt-7"
                        />
                    )}
                </Deferred>

                <Deferred
                    data={['ctlTrend', 'chartAnnotations']}
                    fallback={
                        <div className="mt-7">
                            <div className="h-4 w-32 rounded bg-muted" />
                            <Card as="section" className="mt-2.5">
                                <SkeletonChart className="mt-1 h-[168px]" />
                            </Card>
                        </div>
                    }
                >
                    {() => (
                        <MonthComparison
                            trend={ctlTrend!}
                            annotations={chartAnnotations}
                            className="mt-7"
                        />
                    )}
                </Deferred>

                <Deferred
                    data={['ctlTrend', 'load']}
                    fallback={
                        <div className="mt-7">
                            <div className="h-4 w-32 rounded bg-muted" />
                            <Card as="section" className="mt-2.5">
                                <SkeletonStats className="mt-1" />
                            </Card>
                        </div>
                    }
                >
                    {() => (
                        <RaceComparison
                            activeRace={activeRace ?? null}
                            trend={ctlTrend!}
                            load={load ?? null}
                            className="mt-7"
                        />
                    )}
                </Deferred>
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
