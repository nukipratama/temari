import { Head } from '@inertiajs/react';
import { useState } from 'react';

import type {
    ActivityDetail,
    BriefingResult,
    PastYouTrend,
    TrainingLoad,
    WeekPlan,
    WeeklySnapshot,
} from '@/types/inertia';

import KpiTile from '@/components/dashboard/KpiTile';
import LastRunCard, {
    type LastRunNote,
} from '@/components/dashboard/LastRunCard';
import TrainingLoadCard from '@/components/dashboard/TrainingLoadCard';
import VitalChips from '@/components/dashboard/VitalChips';
import EvidenceList from '@/components/home/EvidenceList';
import NoVerdictPanel from '@/components/home/NoVerdictPanel';
import TodaySession from '@/components/home/TodaySession';
import VerdictHero from '@/components/home/VerdictHero';
import WeekPlanWidget from '@/components/home/WeekPlanWidget';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';

import { weekRangeLabel } from './Home/helpers';

interface HomeProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    lastRunNote?: LastRunNote | null;
    pastYouTrend?: PastYouTrend | null;
    weekPlan?: WeekPlan | null;
}

/**
 * The home screen answers one question: am I getting better? The verdict and
 * the matched pairs behind it lead; today's session follows; everything the
 * dashboard used to open with sits below as supporting detail.
 */
export default function Home({
    briefing,
    load,
    snapshot,
    recentRuns,
    lastRunNote = null,
    pastYouTrend = null,
    weekPlan = null,
}: Readonly<HomeProps>) {
    const lastRun = recentRuns[0] ?? null;

    // Frozen at mount (lazy init) so the week label isn't recomputed impurely on every render.
    const [now] = useState(() => new Date());

    const weekRuns = useCountUp(snapshot?.runs ?? 0);
    const weekKm = useCountUp(snapshot?.distance_km ?? 0);
    const weekTrimp = useCountUp(snapshot?.weekly_trimp ?? 0);
    const weekRunsDisplay = snapshot ? Math.round(weekRuns).toString() : '—';
    const weekKmDisplay = snapshot ? weekKm.toFixed(1) : '—';
    const weekTrimpDisplay =
        snapshot?.weekly_trimp != null ? Math.round(weekTrimp).toString() : '—';

    const hasRuns = recentRuns.length > 0;
    const judged =
        pastYouTrend !== null && pastYouTrend.verdict !== 'not_enough_history'
            ? pastYouTrend.verdict
            : null;

    return (
        <>
            <Head title="Home" />
            <PageContainer>
                {!hasRuns ? (
                    <EmptyRunsState />
                ) : (
                    <>
                        {weekPlan !== null && (
                            <WeekPlanWidget weekPlan={weekPlan} />
                        )}

                        {pastYouTrend !== null && (
                            <div
                                className={cn(
                                    'flex flex-col gap-4',
                                    weekPlan !== null && 'mt-6',
                                )}
                            >
                                {judged !== null ? (
                                    <>
                                        <VerdictHero
                                            trend={pastYouTrend}
                                            verdict={judged}
                                        />
                                        <EvidenceList trend={pastYouTrend} />
                                    </>
                                ) : (
                                    <NoVerdictPanel trend={pastYouTrend} />
                                )}
                            </div>
                        )}

                        <div className="mt-6">
                            <TodaySession briefing={briefing} />
                        </div>

                        <div className="mt-10 flex flex-col gap-6">
                            <section>
                                <Collapsible defaultOpen>
                                    <CollapsibleTrigger
                                        render={<div />}
                                        role="button"
                                        tabIndex={0}
                                        className="group focus-ring flex w-full cursor-pointer items-center justify-between gap-2 rounded"
                                    >
                                        <SectionLabel
                                            dot
                                            dotClass="bg-leaf"
                                            className="mb-0"
                                        >
                                            This week · {weekRangeLabel(now)}
                                        </SectionLabel>
                                        <Icon
                                            icon="mdi:chevron-down"
                                            width={18}
                                            height={18}
                                            className="text-text-3 transition-transform group-aria-expanded:rotate-180"
                                            aria-hidden
                                        />
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="mt-3.5 flex flex-col gap-6">
                                        <div className="grid grid-cols-3 gap-3">
                                            <KpiTile
                                                label="Runs"
                                                value={weekRunsDisplay}
                                            />
                                            <KpiTile
                                                label="KM"
                                                value={weekKmDisplay}
                                            />
                                            <KpiTile
                                                label="TRIMP"
                                                value={weekTrimpDisplay}
                                                explainerKey="trimp"
                                            />
                                        </div>

                                        <VitalChips
                                            briefing={briefing}
                                            load={load}
                                        />

                                        <div className="grid gap-4">
                                            {lastRun && (
                                                <LastRunCard
                                                    run={lastRun}
                                                    note={lastRunNote}
                                                />
                                            )}
                                            <TrainingLoadCard
                                                load={load}
                                                snapshot={snapshot}
                                            />
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>
                            </section>
                        </div>
                    </>
                )}
            </PageContainer>
        </>
    );
}

Home.layout = appLayout;
