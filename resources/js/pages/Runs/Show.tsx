import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import AktivitasDetailPane, {
    type DetailedActivity,
    type DetailedActivityDetail,
    type PastYouMatch,
} from '@/components/aktivitas/AktivitasDetailPane';
import { fadeInUp } from '@/lib/motion';
import type { AnalysisPayload, RunCard as RunCardModel, StoryLine } from '@/types/inertia';

interface RunsShowProps {
    activity: DetailedActivity;
    detail: DetailedActivityDetail;
    card: RunCardModel | null;
    storyLine: StoryLine | null;
    speechAnalysis: AnalysisPayload;
    insightTechnical: AnalysisPayload;
    insightSplits: AnalysisPayload;
    insightZones: AnalysisPayload;
    pastYou: PastYouMatch | null;
}

export default function RunsShow({
    activity,
    detail,
    card,
    storyLine,
    speechAnalysis,
    insightTechnical,
    insightSplits,
    insightZones,
    pastYou,
}: Readonly<RunsShowProps>) {
    return (
        <AppShell>
            <Head title={detail.name ?? 'Run'} />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-6 py-10"
            >
                <Link
                    href="/aktivitas"
                    className="mb-4 inline-flex items-center gap-1 text-sm text-ink-meta transition hover:text-brand-600"
                >
                    <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
                    Semua aktivitas
                </Link>

                <AktivitasDetailPane
                    activity={activity}
                    detail={detail}
                    card={card}
                    storyLine={storyLine}
                    speechAnalysis={speechAnalysis}
                    insightTechnical={insightTechnical}
                    insightSplits={insightSplits}
                    insightZones={insightZones}
                    pastYou={pastYou}
                />
            </motion.main>
        </AppShell>
    );
}
