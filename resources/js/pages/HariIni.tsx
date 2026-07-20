import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { appLayout } from '@/layouts/appLayout';
import { type TemariPose } from '@/components/temari/TemariProto';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import PageContainer from '@/components/ui/PageContainer';
import KataTemariCompact from '@/components/dashboard/KataTemariCompact';
import VitalChips from '@/components/dashboard/VitalChips';
import FeaturedKartuPanel from '@/components/dashboard/FeaturedKartuPanel';
import SuggestionCard from '@/components/dashboard/SuggestionCard';
import LastLariCard, { type LastRunNote } from '@/components/dashboard/LastLariCard';
import KondisiCard from '@/components/dashboard/KondisiCard';
import GoalsCard from '@/components/dashboard/GoalsCard';

import { VIBE_TO_POSE, poseForRun } from '@/lib/temariPose';
import { featuredCardFor, vibeSubtitleFor } from './HariIni/helpers';
import { formatTimeId, formatWeekdayDateId } from '@/lib/pace';
import type {
    ActivityDetail,
    BriefingResult,
    Mood,
    SharedProps,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

interface HariIniProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    lastRunNote?: LastRunNote | null;
    recentMoods?: Record<number, Mood>;
}

export default function HariIni({
    briefing,
    load,
    snapshot,
    recentRuns,
    lastRunNote = null,
    recentMoods = {},
}: Readonly<HariIniProps>) {
    const { props } = usePage<SharedProps & HariIniProps>();
    const firstName = props.auth.user?.first_name ?? '';
    const pose: TemariPose = VIBE_TO_POSE[briefing.vibeState] ?? 'observational';

    const featured = featuredCardFor(recentRuns, briefing.featuredCardId, recentMoods);
    const lastRun = recentRuns[0] ?? null;

    // Freeze the date/time line at mount (lazy init) so it isn't recomputed impurely on every render.
    const [now] = useState(() => new Date());
    const dateLine = `${formatWeekdayDateId(now)} · ${formatTimeId(now)} · ${briefing.vibeLabel}`;
    const vibeSubtitle = vibeSubtitleFor(briefing.vibeLabel);

    return (
        <>
            <Head title="Hari Ini" />
            <PageContainer>
                {/* HEADLINE */}
                <header className="grid grid-cols-1 items-end gap-9 lg:grid-cols-[1.4fr_1fr]">
                    <div>
                        <div className="mb-3.5 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-ink-2">
                            {dateLine}
                        </div>
                        <h1 className="font-display text-display-2xl text-ink">
                            Halo, {firstName}<br />
                            <span className="italic text-horizon">{vibeSubtitle}</span>
                        </h1>
                    </div>
                    <aside className="pb-3.5">
                        <KataTemariCompact briefing={briefing} pose={pose} />
                    </aside>
                </header>

                {recentRuns.length === 0 ? (
                    <EmptyRunsState />
                ) : (
                    <>
                        {/* HERO KARTU */}
                        {featured && <FeaturedKartuPanel featured={featured} featuredKartuVoice={briefing.featuredKartuVoice} />}

                        {/* VITAL CHIPS — below hero, full width 3-up */}
                        <section className="mt-6">
                            <VitalChips briefing={briefing} load={load} />
                        </section>

                        {/* 3-UP */}
                        <section className="mt-8 grid gap-4 lg:grid-cols-3">
                            <SuggestionCard suggestion={briefing.suggestion} lastRun={lastRun} />
                            {lastRun && <LastLariCard run={lastRun} pose={poseForRun(lastRun, recentMoods[lastRun.activity_id] ?? null)} note={lastRunNote} />}
                            <KondisiCard load={load} snapshot={snapshot} />
                        </section>

                        {/* TARGET TERDEKAT */}
                        <GoalsCard />
                    </>
                )}
            </PageContainer>
        </>
    );
}

HariIni.layout = appLayout;
