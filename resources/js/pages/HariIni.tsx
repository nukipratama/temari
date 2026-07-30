import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { appLayout } from '@/layouts/appLayout';
import { type TemariPose } from '@/components/temari/TemariProto';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import Eyebrow from '@/components/ui/Eyebrow';
import HeroPanel from '@/components/ui/HeroPanel';
import PageContainer from '@/components/ui/PageContainer';
import KataTemariCard from '@/components/dashboard/KataTemariCard';
import VitalChips from '@/components/dashboard/VitalChips';
import FeaturedKartuPanel from '@/components/dashboard/FeaturedKartuPanel';
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
                {/* HEADLINE + KATA TEMARI — one hero, matching Rekor/Aku/Aksesori's
                    pattern of pairing a headline with its companion Temari voice
                    inside a single panel instead of two disconnected boxes. */}
                <HeroPanel className="lg:px-14 lg:py-12">
                    <div className="grid grid-cols-1 items-center gap-8 lg:grid-cols-[1fr_minmax(320px,_360px)] lg:gap-12">
                        <div>
                            <Eyebrow token="hero" tone="ink-on-sky" className="mb-3.5">
                                {dateLine}
                            </Eyebrow>
                            <h1 className="font-display text-display-2xl text-cream">
                                Halo, {firstName}<br />
                                <span className="italic text-horizon">{vibeSubtitle}</span>
                            </h1>
                        </div>
                        <KataTemariCard briefing={briefing} pose={pose} lastRun={lastRun} />
                    </div>
                </HeroPanel>

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

                        {/* 2-UP */}
                        <section className="mt-8 grid gap-4 lg:grid-cols-2">
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
