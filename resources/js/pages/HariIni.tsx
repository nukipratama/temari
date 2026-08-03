import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';

import type {
    ActivityDetail,
    BriefingResult,
    Mood,
    SharedProps,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

import FeaturedKartuPanel from '@/components/dashboard/FeaturedKartuPanel';
import GoalsCard from '@/components/dashboard/GoalsCard';
import KataTemariCard from '@/components/dashboard/KataTemariCard';
import KondisiCard from '@/components/dashboard/KondisiCard';
import LastLariCard, {
    type LastRunNote,
} from '@/components/dashboard/LastLariCard';
import VitalChips from '@/components/dashboard/VitalChips';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import { type TemariPose } from '@/components/temari/TemariProto';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { formatTimeId, formatWeekdayDateId } from '@/lib/pace';
import { VIBE_TO_POSE, poseForRun } from '@/lib/temariPose';

import { featuredCardFor, vibeSubtitleFor } from './HariIni/helpers';

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
    const pose: TemariPose =
        VIBE_TO_POSE[briefing.vibeState] ?? 'observational';

    const featured = featuredCardFor(
        recentRuns,
        briefing.featuredCardId,
        recentMoods,
    );
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
                <header className="mb-8">
                    <Eyebrow token="hero" tone="ink-2" className="mb-3.5">
                        {dateLine}
                    </Eyebrow>
                    <h1 className="font-display text-display-2xl text-ink">
                        Halo, {firstName}
                        <br />
                        <span className="italic text-horizon">
                            {vibeSubtitle}
                        </span>
                    </h1>
                </header>

                {recentRuns.length === 0 ? (
                    <>
                        <KataTemariCard
                            briefing={briefing}
                            pose={pose}
                            lastRun={lastRun}
                        />
                        <div className="mt-6">
                            <EmptyRunsState />
                        </div>
                    </>
                ) : (
                    <>
                        {/* HERO KARTU */}
                        {featured && (
                            <FeaturedKartuPanel
                                featured={featured}
                                featuredKartuVoice={briefing.featuredKartuVoice}
                            />
                        )}

                        {/* VITAL CHIPS — below hero, full width 3-up */}
                        <section className="mt-6">
                            <VitalChips briefing={briefing} load={load} />
                        </section>

                        {/* 3-UP */}
                        <section className="mt-8 grid gap-4 lg:grid-cols-3">
                            {lastRun && (
                                <LastLariCard
                                    run={lastRun}
                                    pose={poseForRun(
                                        lastRun,
                                        recentMoods[lastRun.activity_id] ??
                                            null,
                                    )}
                                    note={lastRunNote}
                                />
                            )}
                            <KataTemariCard
                                briefing={briefing}
                                pose={pose}
                                lastRun={lastRun}
                            />
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
