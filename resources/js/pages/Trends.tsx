import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';

import type { AnalysisPayload } from '@/types/inertia';

import NarrationHeadline from '@/components/trends/NarrationHeadline';
import FitnessTrend, {
    type BadgeMilestone,
    type FitnessTrendPoint,
} from '@/components/trends/panels/FitnessTrend';
import LoadTrend, {
    type LoadTrendPoint,
} from '@/components/trends/panels/LoadTrend';
import PaceConsistencyTrend, {
    type PaceConsistencyPoint,
} from '@/components/trends/panels/PaceConsistencyTrend';
import PersonalBests, {
    type DistanceRecord,
    type PaceRecord,
} from '@/components/trends/panels/PersonalBests';
import VdotTrend, {
    type VdotHistoryPoint,
} from '@/components/trends/panels/VdotTrend';
import RangeToggle, { type TrendRange } from '@/components/trends/RangeToggle';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';

interface TrendsProps {
    ctlTrend: FitnessTrendPoint[];
    loadTrend: LoadTrendPoint[];
    vdotHistory: VdotHistoryPoint[];
    vdotSourceCategory: string | null;
    paceConsistencyHistory: PaceConsistencyPoint[];
    distanceRecords: DistanceRecord[];
    paceRecords: PaceRecord[];
    badgeMilestones: BadgeMilestone[];
    narration: Record<TrendRange, AnalysisPayload>;
}

export default function Trends({
    ctlTrend,
    loadTrend,
    vdotHistory,
    vdotSourceCategory,
    paceConsistencyHistory,
    distanceRecords,
    paceRecords,
    badgeMilestones,
    narration,
}: Readonly<TrendsProps>) {
    const [range, setRange] = useState<TrendRange>('12mo');

    return (
        <>
            <Head title="Trends" />
            <PageContainer>
                <motion.div
                    initial="hidden"
                    animate="visible"
                    variants={staggerContainer}
                    className="flex flex-col gap-8"
                >
                    <motion.div variants={fadeInUp}>
                        <PageHero eyebrow="Trends">
                            How things are going
                        </PageHero>
                        <p className="mt-2 max-w-prose text-sm text-ink-2">
                            A year of running, read as lines rather than a list.
                            Everything on this page is your own history, never a
                            comparison with anyone else.
                        </p>
                    </motion.div>

                    <motion.div
                        variants={fadeInUp}
                        className="flex flex-col gap-3"
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="text-label-micro text-ink-3">
                                Range
                            </span>
                            <RangeToggle value={range} onChange={setRange} />
                        </div>
                        <NarrationHeadline analysis={narration[range]} />
                    </motion.div>

                    <motion.div variants={fadeInUp}>
                        <FitnessTrend
                            trend={ctlTrend}
                            milestones={badgeMilestones}
                            range={range}
                        />
                    </motion.div>

                    <motion.div variants={fadeInUp}>
                        <LoadTrend trend={loadTrend} range={range} />
                    </motion.div>

                    <motion.div variants={fadeInUp}>
                        <VdotTrend
                            trend={vdotHistory}
                            sourceCategory={vdotSourceCategory}
                            range={range}
                        />
                    </motion.div>

                    <motion.div variants={fadeInUp}>
                        <PaceConsistencyTrend
                            trend={paceConsistencyHistory}
                            range={range}
                        />
                    </motion.div>

                    <motion.div
                        variants={fadeInUp}
                        className="flex flex-col gap-1"
                    >
                        <span className="text-label-micro text-ink-3">
                            Always full history
                        </span>
                        <p className="text-xs text-ink-3">
                            Personal bests don&apos;t change with the range
                            above — they&apos;re your all-time numbers.
                        </p>
                    </motion.div>

                    <motion.div variants={fadeInUp}>
                        <PersonalBests
                            distanceRecords={distanceRecords}
                            paceRecords={paceRecords}
                        />
                    </motion.div>
                </motion.div>
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
