import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';

import type { AnalysisPayload } from '@/types/inertia';

import NarrationHeadline from '@/components/trends/NarrationHeadline';
import FitnessTrend, {
    type BadgeMilestone,
    type FitnessTrendPoint,
} from '@/components/trends/panels/FitnessTrend';
import RangeToggle, { type TrendRange } from '@/components/trends/RangeToggle';
import StreakBadge, {
    type StreakSummaryLike,
} from '@/components/trends/StreakBadge';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';

interface TrendsProps {
    ctlTrend: FitnessTrendPoint[];
    badgeMilestones: BadgeMilestone[];
    streak: StreakSummaryLike;
    narration: Record<TrendRange, AnalysisPayload>;
}

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
                <motion.div
                    initial="hidden"
                    animate="visible"
                    variants={staggerContainer}
                    className="flex flex-col gap-8"
                >
                    <motion.div variants={fadeInUp}>
                        <PageHero eyebrow="Trends" size="quote-lg" italic>
                            how things
                            <br />
                            <em className="italic text-icon-accent">
                                are going.
                            </em>
                        </PageHero>
                        <p className="mt-2 max-w-prose text-sm text-text-2">
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
                            <span className="text-label-micro text-text-3">
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
                        <StreakBadge streak={streak} />
                    </motion.div>
                </motion.div>
            </PageContainer>
        </>
    );
}

Trends.layout = appLayout;
