import type { RaceAmbition, RaceSupport } from '@/types/inertia';

import MascotWatermark from '@/components/temari/MascotWatermark';
import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import {
    daysUntilId,
    formatDurationHMS,
    formatNaiveIdDate,
    useTodayIso,
} from '@/lib/pace';
import {
    ambitionNote,
    type GoalGapVerdict,
    goalGap,
    goalGapPose,
    supportedEyebrow,
} from '@/lib/raceGoal';

interface RaceDuelProps {
    race: {
        name: string | null;
        race_date: string;
        goal_time_sec: number;
    };
    ambition: RaceAmbition;
    support: RaceSupport;
    className?: string;
}

const GAP_PILL: Record<GoalGapVerdict, string> = {
    behind: 'bg-ember/15 text-ember-ink',
    ahead: 'bg-leaf/15 text-leaf-ink',
    on: 'bg-muted text-foreground',
};

const SUPPORTED_TONE: Record<GoalGapVerdict, string> = {
    behind: 'text-ember-ink',
    ahead: 'text-leaf-ink',
    on: 'text-foreground',
};

const TIME =
    'mt-1 font-mono text-headline-xs font-extrabold break-all tabular-nums';

/** The race's target facing the time recent runs support, with the gap and the ambition band in words. */
export default function RaceDuel({
    race,
    ambition,
    support,
    className,
}: Readonly<RaceDuelProps>) {
    const goalSec = race.goal_time_sec;
    const supportedSec = ambition.supported_time_sec;
    const banded =
        supportedSec !== null &&
        ambition.state !== 'unknown' &&
        ambition.state !== 'low_evidence';
    const gap = banded ? goalGap(goalSec, supportedSec) : null;
    const tone: GoalGapVerdict =
        gap === null ||
        (ambition.state === 'on_track' && gap.verdict === 'behind')
            ? 'on'
            : gap.verdict;
    const daysToGo = daysUntilId(race.race_date, useTodayIso());

    return (
        <section className={cn('relative isolate overflow-hidden', className)}>
            <MascotWatermark
                pose={banded ? goalGapPose(goalSec, supportedSec) : 'neutral'}
                className="-right-14 -bottom-15"
            />
            <div className="grid grid-cols-[1fr_auto_1fr] items-end gap-2">
                <div className="min-w-0">
                    <Eyebrow token="micro" tone="ink-2">
                        your target
                    </Eyebrow>
                    <p className={cn(TIME, 'text-foreground')}>
                        {formatDurationHMS(goalSec)}
                    </p>
                </div>
                {gap ? (
                    <span
                        className={cn(
                            'mb-0.5 rounded-full pad-chip font-mono text-xs font-bold whitespace-nowrap tabular-nums',
                            GAP_PILL[tone],
                        )}
                    >
                        {gap.label}
                    </span>
                ) : (
                    <span aria-hidden />
                )}
                {supportedSec !== null && (
                    <div className="min-w-0 text-right">
                        <Eyebrow token="micro" tone="ink-2">
                            {supportedEyebrow(ambition)}
                        </Eyebrow>
                        <p className={cn(TIME, SUPPORTED_TONE[tone])}>
                            {formatDurationHMS(supportedSec)}
                        </p>
                    </div>
                )}
            </div>

            <p className="mt-4 text-xs leading-relaxed text-text-2">
                {ambitionNote(ambition, support)}
            </p>

            <p className="mt-4 font-mono text-xs tabular-nums text-text-2">
                <span className="font-sans text-sm font-semibold text-foreground">
                    {race.name ?? 'your race'}
                </span>
                {' · '}
                {formatNaiveIdDate(race.race_date)} · {daysToGo}{' '}
                {daysToGo === 1 ? 'day' : 'days'} to go
            </p>
        </section>
    );
}
