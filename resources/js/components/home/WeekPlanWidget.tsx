import type { WeekPlan, WeekPlanDay } from '@/types/inertia';

import Chip, { type ChipTone } from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formatPace, parseNaiveLocalDate, todayLocalIso } from '@/lib/pace';

const SESSION_TYPE_LABEL: Record<string, string> = {
    easy: 'Easy',
    long: 'Long run',
    tempo: 'Tempo',
    interval: 'Interval',
    rest: 'Rest',
};

const PHASE_LABEL: Record<string, string> = {
    base: 'Base',
    build: 'Build',
    peak: 'Peak',
    taper: 'Taper',
    deload: 'Deload',
};

const PHASE_TONE: Record<string, ChipTone> = {
    base: 'neutral',
    build: 'sky',
    peak: 'horizon',
    taper: 'horizon',
    deload: 'neutral',
};

const STATUS_LABEL: Record<string, string> = {
    planned: 'Upcoming',
    done: 'Done',
    partial: 'Partial',
    missed: 'Missed',
    overreached: 'Overreached',
    skip: 'Skipped',
};

/** Same shape-per-intensity vocabulary as the frozen prototype's TodayScreen:
 *  quality/hard days read as a flame, easy/long days as a feather, rest as a
 *  bed. This is the day's `session_type`, independent of how it went. */
const TYPE_ICON: Record<string, string> = {
    tempo: 'mdi:fire',
    interval: 'mdi:fire',
    easy: 'mdi:feather',
    long: 'mdi:feather',
    rest: 'mdi:bed',
};

/** Compliance-v2's six statuses, colored distinctly so "did more than asked"
 *  (overreached) never reads the same as "hit it exactly" (done), and a
 *  `skip` (explicitly excused) never reads as a `missed` (didn't happen). */
const STATUS_TONE: Record<string, string> = {
    done: 'text-leaf-ink',
    partial: 'text-leaf-ink opacity-60',
    overreached: 'text-horizon-ink',
    missed: 'text-ember-ink opacity-40',
    skip: 'text-text-3',
};

const RING_SIZE = 104;
const RING_STROKE = 10;

function weekdayAbbr(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null
        ? ''
        : date.toLocaleDateString('en-US', { weekday: 'short' });
}

/** Native-tooltip + accessible detail for a day cell: status, the 0-100
 *  compliance score when one exists, and whether a rest day got run anyway. */
function dayDetail(day: WeekPlanDay): string {
    const parts = [STATUS_LABEL[day.status] ?? day.status];
    if (day.compliance_score !== null) {
        parts.push(`${day.compliance_score}%`);
    }
    if (day.ran_anyway) {
        parts.push('Ran anyway');
    }
    return parts.join(' · ');
}

function SessionsRing({ value }: Readonly<{ value: number }>) {
    const radius = (RING_SIZE - RING_STROKE) / 2;
    const circumference = 2 * Math.PI * radius;
    const clamped = Math.min(1, Math.max(0, value));
    const tweenedPct = useCountUp(clamped * 100) / 100;
    const offset = circumference * (1 - tweenedPct);

    return (
        <svg
            width={RING_SIZE}
            height={RING_SIZE}
            viewBox={`0 0 ${RING_SIZE} ${RING_SIZE}`}
            className="-rotate-90"
            aria-hidden
        >
            <circle
                cx={RING_SIZE / 2}
                cy={RING_SIZE / 2}
                r={radius}
                fill="none"
                stroke="var(--color-surface-sunken)"
                strokeWidth={RING_STROKE}
            />
            <circle
                cx={RING_SIZE / 2}
                cy={RING_SIZE / 2}
                r={radius}
                fill="none"
                stroke="var(--color-horizon-ink)"
                strokeWidth={RING_STROKE}
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={offset}
            />
        </svg>
    );
}

function DayGlyph({ day }: Readonly<{ day: WeekPlanDay }>) {
    return (
        <Icon
            icon={TYPE_ICON[day.session_type] ?? 'mdi:fire'}
            width={16}
            height={16}
            className={STATUS_TONE[day.status] ?? 'text-text-3'}
            aria-hidden
        />
    );
}

/**
 * "This week's plan" — the widget Home leads with. Fields are exactly
 * `CurrentWeekPlanBuilder::forUser()`'s shape, the same computation Plan's
 * own week rows use, so nothing shown here can drift from Plan.
 */
export default function WeekPlanWidget({
    weekPlan,
}: Readonly<{ weekPlan: WeekPlan }>) {
    const todayIso = todayLocalIso();
    const today = weekPlan.days.find((d) => d.date === todayIso) ?? null;
    const todayCorePaceSecPerKm =
        today?.segments.find((s) => s.key === 'main' || s.key === 'interval')
            ?.pace_sec_per_km ?? null;
    const creditedTweened = useCountUp(weekPlan.credited_this_week);
    const kmTweened = useCountUp(weekPlan.planned_km_this_week);

    return (
        <Card as="section" padding="hero">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <SectionLabel dot dotClass="bg-leaf" className="mb-0">
                    This week&apos;s plan
                </SectionLabel>
                <div className="flex items-center gap-2">
                    {weekPlan.streak_days > 0 && (
                        <span className="text-label-micro text-text-3">
                            {weekPlan.streak_days} Credited In A Row
                        </span>
                    )}
                    <Chip tone={PHASE_TONE[weekPlan.phase] ?? 'neutral'}>
                        {PHASE_LABEL[weekPlan.phase] ?? weekPlan.phase}
                    </Chip>
                </div>
            </div>

            <div className="mt-4 flex flex-col items-center gap-4">
                <SessionsRing
                    value={
                        weekPlan.sessions_per_week > 0
                            ? weekPlan.credited_this_week /
                              weekPlan.sessions_per_week
                            : 0
                    }
                />
                <div className="grid flex-1 grid-cols-2 gap-3">
                    <StatTile
                        label="Sessions"
                        value={`${Math.round(creditedTweened)}/${weekPlan.sessions_per_week}`}
                        tone="sunken"
                        size="sm"
                    />
                    <StatTile
                        label="Distance"
                        value={kmTweened.toFixed(1)}
                        unit="km"
                        tone="sunken"
                        size="sm"
                    />
                </div>
            </div>

            <ul className="mt-4 grid grid-cols-7 gap-1.5">
                {weekPlan.days.map((day) => (
                    <li
                        key={day.date}
                        title={dayDetail(day)}
                        className={cn(
                            'flex flex-col items-center gap-1 rounded-lg bg-muted p-2 text-center',
                            day.date === todayIso && 'ring-2 ring-horizon-ink',
                        )}
                    >
                        <span className="text-label-micro text-text-3">
                            {weekdayAbbr(day.date)}
                        </span>
                        <DayGlyph day={day} />
                        <span className="text-[11px] font-semibold text-foreground">
                            {day.session_type === 'rest'
                                ? day.ran_anyway
                                    ? 'Ran Anyway'
                                    : 'Rest'
                                : `${day.distance_km}k`}
                        </span>
                        {day.pinned && (
                            <Icon
                                icon="mdi:pin"
                                width={9}
                                height={9}
                                className="text-text-3"
                                aria-label="Pinned"
                            />
                        )}
                    </li>
                ))}
            </ul>

            {today !== null && (
                <div className="mt-4 rounded-lg bg-accent p-3">
                    <span className="text-sm font-bold text-foreground">
                        Today ·{' '}
                        {SESSION_TYPE_LABEL[today.session_type] ??
                            today.session_type}
                        {today.session_type !== 'rest' &&
                            ` · ${today.distance_km} km`}
                        {todayCorePaceSecPerKm !== null &&
                            ` · ${formatPace(todayCorePaceSecPerKm)}/km`}
                    </span>
                    {today.clamp_note !== null && (
                        <p className="mt-1 text-xs italic text-text-3">
                            {today.clamp_note}
                        </p>
                    )}
                </div>
            )}
        </Card>
    );
}
