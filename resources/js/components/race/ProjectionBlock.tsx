import { motion } from 'framer-motion';

import ProjectionGauge from '@/components/race/ProjectionGauge';
import FaceIcon from '@/components/temari/FaceIcon';
import Eyebrow from '@/components/ui/Eyebrow';
import Card from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formatDurationHMS } from '@/lib/pace';

export interface RaceProjection {
    predicted_sec: number;
    low_sec: number;
    high_sec: number;
    sample_size: number;
    confidence: 'low' | 'medium' | 'high';
}

interface ProjectionBlockProps {
    projection: RaceProjection | null;
    className?: string;
}

const CONFIDENCE_COPY: Record<RaceProjection['confidence'], string> = {
    low: 'wide range, thin PR sample',
    medium: 'moderate range',
    high: 'narrow range, well-fitted',
};

const GLOW =
    'radial-gradient(circle, color-mix(in oklab, var(--color-horizon) 45%, transparent) 0%, color-mix(in oklab, var(--color-horizon) 18%, transparent) 45%, transparent 70%)';

/**
 * The prototype's projected-finish block: a horizon glow behind the arc gauge,
 * the predicted time, and how much of the athlete's own PR history it rests on.
 * A user with no personal record yet has nothing to anchor a Riegel fit to, so
 * the block explains that instead of drawing an empty gauge.
 */
export default function ProjectionBlock({
    projection,
    className,
}: Readonly<ProjectionBlockProps>) {
    const predictedSec = useCountUp(projection?.predicted_sec ?? 0);

    if (projection === null) {
        return (
            <Card className={className}>
                <p className="text-sm leading-relaxed text-text-2">
                    No personal record yet to project a finish time from. Set
                    one on a run and it shows up here.
                </p>
            </Card>
        );
    }

    const prLabel =
        projection.sample_size === 1 ? '1 PR' : `${projection.sample_size} PRs`;

    return (
        <Card className={cn('relative overflow-hidden', className)}>
            <span
                aria-hidden
                className="pointer-events-none absolute -top-10 -right-10 size-[160px] rounded-full"
                style={{ background: GLOW }}
            />
            <motion.div initial="hidden" animate="visible" variants={fadeInUp}>
                <div className="relative flex items-center gap-1.5">
                    <Eyebrow token="micro" tone="ink-2">
                        Projected finish
                    </Eyebrow>
                    <FaceIcon size={18} />
                </div>
                <div className="relative mt-2 flex justify-center">
                    <ProjectionGauge
                        lowSec={projection.low_sec}
                        predictedSec={projection.predicted_sec}
                        highSec={projection.high_sec}
                    />
                </div>
                <p className="relative mt-1 text-center font-mono text-xl font-extrabold tabular-nums text-icon-accent">
                    {formatDurationHMS(Math.round(predictedSec))}
                </p>
                <p className="relative mt-1.5 text-center text-xs leading-relaxed text-text-2">
                    Best estimate, from {prLabel} (
                    {CONFIDENCE_COPY[projection.confidence]}).
                </p>
            </motion.div>
        </Card>
    );
}
