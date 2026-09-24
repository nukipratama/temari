import ProjectionRangeBar from '@/components/race/ProjectionRangeBar';
import MascotWatermark from '@/components/temari/MascotWatermark';
import Eyebrow from '@/components/ui/Eyebrow';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { daysUntilId, formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';
import { type GoalGapVerdict, goalGap, goalGapPose } from '@/lib/raceGoal';

export interface RaceProjection {
    predicted_sec: number;
    low_sec: number;
    high_sec: number;
    sample_size: number;
    confidence: 'low' | 'medium' | 'high';
    window: 'recent' | 'all';
}

interface RaceDuelProps {
    race: {
        name: string | null;
        race_date: string;
        goal_time_sec: number;
    };
    projection: RaceProjection | null;
    className?: string;
}

const CONFIDENCE_COPY: Record<RaceProjection['confidence'], string> = {
    low: 'wide range, thin PR sample',
    medium: 'moderate range',
    high: 'narrow range, well-fitted',
};

const WINDOW_COPY: Record<RaceProjection['window'], string> = {
    recent: 'in the last 4 months',
    all: 'across your whole record',
};

const GAP_PILL: Record<GoalGapVerdict, string> = {
    behind: 'bg-ember/15 text-ember-ink',
    ahead: 'bg-leaf/15 text-leaf-ink',
    on: 'bg-muted text-foreground',
};

const TIME = 'mt-1 font-mono text-headline-xs font-extrabold tabular-nums';

/** The race's goal time facing the projected finish, with the gap between them in words. */
export default function RaceDuel({
    race,
    projection,
    className,
}: Readonly<RaceDuelProps>) {
    const goalSec = race.goal_time_sec;
    const gap = projection && goalGap(goalSec, projection.predicted_sec);
    const daysToGo = daysUntilId(race.race_date);

    return (
        <Card
            as="section"
            padding="hero"
            className={cn('relative isolate overflow-hidden', className)}
        >
            <MascotWatermark
                pose={
                    projection
                        ? goalGapPose(goalSec, projection.predicted_sec)
                        : 'neutral'
                }
                className="-right-14 -bottom-15"
            />
            <div className="grid grid-cols-[1fr_auto_1fr] items-end gap-2">
                <div className="min-w-0">
                    <Eyebrow token="micro" tone="ink-2">
                        your goal
                    </Eyebrow>
                    <p className={cn(TIME, 'text-foreground')}>
                        {formatDurationHMS(goalSec)}
                    </p>
                </div>
                {projection && gap ? (
                    <>
                        <span
                            className={cn(
                                'mb-0.5 rounded-full pad-chip font-mono text-xs font-bold whitespace-nowrap tabular-nums',
                                GAP_PILL[gap.verdict],
                            )}
                        >
                            {gap.label}
                        </span>
                        <div className="min-w-0 text-right">
                            <Eyebrow
                                token="micro"
                                tone="ink-2"
                                className="whitespace-nowrap"
                            >
                                on track for
                            </Eyebrow>
                            <p className={cn(TIME, 'text-icon-accent')}>
                                {formatDurationHMS(projection.predicted_sec)}
                            </p>
                        </div>
                    </>
                ) : (
                    <p className="col-span-2 self-end text-right text-xs leading-relaxed text-text-2">
                        not enough recent runs to project yet
                    </p>
                )}
            </div>

            {projection && (
                <div className="mt-5">
                    <ProjectionRangeBar
                        goalSec={goalSec}
                        lowSec={projection.low_sec}
                        predictedSec={projection.predicted_sec}
                        highSec={projection.high_sec}
                    />
                </div>
            )}

            <p className="mt-5 font-mono text-xs tabular-nums text-text-2">
                <span className="font-sans text-sm font-semibold text-foreground">
                    {race.name ?? 'your race'}
                </span>
                {' · '}
                {formatNaiveIdDate(race.race_date)} · {daysToGo}{' '}
                {daysToGo === 1 ? 'day' : 'days'} to go
            </p>
            {projection && (
                <p className="mt-1 text-xs leading-relaxed text-text-2">
                    best estimate from{' '}
                    {projection.sample_size === 1
                        ? '1 PR'
                        : `${projection.sample_size} PRs`}{' '}
                    {WINDOW_COPY[projection.window]} (
                    {CONFIDENCE_COPY[projection.confidence]}).
                </p>
            )}
        </Card>
    );
}
