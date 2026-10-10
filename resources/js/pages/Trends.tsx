import { Deferred, Head, usePage } from '@inertiajs/react';

import type {
    FitnessChartAnnotations,
    FitnessTrendPoint,
} from '@/components/trends/panels/FitnessPanel';
import type { SupportedHistory } from '@/components/trends/SupportedOverTime';
import type {
    AnalysisPayload,
    RaceAmbition,
    RaceSupport,
    SharedProps,
    TrainingLoad,
    WeekComparison as WeekComparisonPayload,
} from '@/types/inertia';

import AiOutageBanner from '@/components/AiOutageBanner';
import MonthComparison from '@/components/trends/MonthComparison';
import NarrationCard from '@/components/trends/NarrationCard';
import RaceComparison from '@/components/trends/RaceComparison';
import SupportedOverTime from '@/components/trends/SupportedOverTime';
import TrendsHeading from '@/components/trends/TrendsHeading';
import { TrendsSectionSkeleton } from '@/components/trends/TrendsSkeleton';
import WeekComparison from '@/components/trends/WeekComparison';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import { SkeletonProse } from '@/components/ui/Skeleton';
import { appLayout } from '@/layouts/appLayout';

interface TrendsProps {
    ctlTrend?: FitnessTrendPoint[];
    narration?: AnalysisPayload;
    weekComparison?: WeekComparisonPayload;
    load?: TrainingLoad | null;
    chartAnnotations?: FitnessChartAnnotations;
    raceOutlook?: { ambition: RaceAmbition; support: RaceSupport } | null;
    supportedHistory?: SupportedHistory | null;
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
    supportedHistory,
}: Readonly<TrendsProps>) {
    const { activeRace } = usePage<SharedProps>().props;

    return (
        <>
            <Head title="Trends" />
            <AiOutageBanner />
            <PageContainer>
                <TrendsHeading />

                <LaneStack className="mt-6">
                    <Deferred data="narration" fallback={<SkeletonProse />}>
                        {() => <NarrationCard analysis={narration!} />}
                    </Deferred>

                    <Deferred
                        data={['weekComparison', 'load']}
                        fallback={<TrendsSectionSkeleton />}
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
                        fallback={<TrendsSectionSkeleton chart />}
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
                        fallback={<TrendsSectionSkeleton />}
                    >
                        {() => (
                            <RaceComparison
                                activeRace={activeRace ?? null}
                                outlook={raceOutlook ?? null}
                                load={load ?? null}
                            />
                        )}
                    </Deferred>

                    <Deferred data="supportedHistory" fallback={<></>}>
                        {() => (
                            <SupportedOverTime
                                history={supportedHistory ?? null}
                            />
                        )}
                    </Deferred>
                </LaneStack>
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
