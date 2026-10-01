import { Deferred, Head, usePage } from '@inertiajs/react';

import type {
    FitnessChartAnnotations,
    FitnessTrendPoint,
} from '@/components/trends/panels/FitnessPanel';
import type {
    AnalysisPayload,
    RaceAmbition,
    RaceSupport,
    SharedProps,
    TrainingLoad,
    WeekComparison as WeekComparisonPayload,
} from '@/types/inertia';

import MonthComparison from '@/components/trends/MonthComparison';
import NarrationCard from '@/components/trends/NarrationCard';
import RaceComparison from '@/components/trends/RaceComparison';
import WeekComparison from '@/components/trends/WeekComparison';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import {
    SkeletonChart,
    SkeletonProse,
    SkeletonStats,
} from '@/components/ui/Skeleton';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { laneStack } from '@/lib/variants';

interface TrendsProps {
    ctlTrend?: FitnessTrendPoint[];
    narration?: AnalysisPayload;
    weekComparison?: WeekComparisonPayload;
    load?: TrainingLoad | null;
    chartAnnotations?: FitnessChartAnnotations;
    raceOutlook?: { ambition: RaceAmbition; support: RaceSupport } | null;
}

/**
 * Trends answers one question: "am I getting fitter, and at what cost?"
 * Temari's 7-day verdict runs first — the only place a call is stated —
 * then three stacked comparisons carry the evidence, in the order a runner
 * would ask for it: vs last week, long-term load (the CTL chart lives
 * here), then vs race day (or, with no race, vs the athlete's own year).
 * Direction A of the #914 design round, filed as #967. Replaces the range
 * toggle, badges and streak (profile and run pages keep those) and the ATL
 * line. Laid out on MASTER.md's lane-divided sections, mirroring Home and
 * Plan.
 */
export default function Trends({
    ctlTrend,
    narration,
    weekComparison,
    load,
    chartAnnotations,
    raceOutlook,
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

                <div className={cn('mt-6', laneStack)}>
                    <Deferred data="narration" fallback={<SkeletonProse />}>
                        {() => <NarrationCard analysis={narration!} />}
                    </Deferred>

                    <Deferred
                        data={['weekComparison', 'load']}
                        fallback={
                            <div>
                                <div className="h-4 w-32 rounded bg-muted" />
                                <SkeletonStats className="mt-2.5" />
                            </div>
                        }
                    >
                        {() => (
                            <WeekComparison
                                weekComparison={weekComparison!}
                                load={load ?? null}
                            />
                        )}
                    </Deferred>

                    <Deferred
                        data={['ctlTrend', 'chartAnnotations']}
                        fallback={
                            <div>
                                <div className="h-4 w-32 rounded bg-muted" />
                                <SkeletonChart className="mt-2.5 h-[168px]" />
                            </div>
                        }
                    >
                        {() => (
                            <MonthComparison
                                trend={ctlTrend!}
                                annotations={chartAnnotations}
                            />
                        )}
                    </Deferred>

                    <Deferred
                        data={['load', 'raceOutlook']}
                        fallback={
                            <div>
                                <div className="h-4 w-32 rounded bg-muted" />
                                <SkeletonStats className="mt-2.5" />
                            </div>
                        }
                    >
                        {() => (
                            <RaceComparison
                                activeRace={activeRace ?? null}
                                outlook={raceOutlook ?? null}
                                load={load ?? null}
                            />
                        )}
                    </Deferred>
                </div>
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
