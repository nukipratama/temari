import type {
    PlanDay,
    PlanNarration,
    PlanWeek,
    SeasonSummaryWeek,
} from '@/lib/plan';

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import SeasonHeaderCard from '@/components/plan/SeasonHeaderCard';
import SeasonTimeline from '@/components/plan/SeasonTimeline';
import PlanRaceTabs from '@/components/race/PlanRaceTabs';
import Card from '@/components/ui/LegacyCard';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { appLayout } from '@/layouts/appLayout';
import { formatDurationHMS, formatNaiveIdDate, todayLocalIso } from '@/lib/pace';

interface SeasonSummary {
    starts_at: string;
    ends_at: string;
    week_index: number;
    total_weeks: number;
    is_race_oriented: boolean;
}

interface PlanAdaptation {
    reason: string;
    headline: string;
    detail: string;
    deload: boolean;
}

interface PlanProps {
    race: { race_date: string; name: string | null } | null;
    sessionsPerWeek: number;
    weeks: PlanWeek[];
    season: SeasonSummary | null;
    seasonSummary?: SeasonSummaryWeek[];
    seasonAdherencePct?: number | null;
    adaptation: PlanAdaptation | null;
    /** Served from App\Support\TrainingDisclaimer, shared with the legal pages. */
    disclaimerHeadline: string;
    disclaimer: string;
    planNarration?: PlanNarration;
    /** Seconds left before Regenerate may run again, or null when it's free to click. */
    regenerateCooldownSeconds?: number | null;
}

const PLAN_NARRATION_DEFAULT: PlanNarration = {
    days: {},
    week: null,
    season: null,
};

export default function Plan({
    race,
    sessionsPerWeek,
    weeks,
    season,
    seasonSummary = [],
    seasonAdherencePct = null,
    adaptation,
    disclaimerHeadline,
    disclaimer,
    planNarration = PLAN_NARRATION_DEFAULT,
    regenerateCooldownSeconds = null,
}: Readonly<PlanProps>) {
    const [regenerating, setRegenerating] = useState(false);
    const today = todayLocalIso();
    const regenerateCooldown = useCooldownCountdown(regenerateCooldownSeconds);
    const regenerateCooling = regenerateCooldown > 0;

    const regenerate = () => {
        router.post(
            '/plan/regenerate',
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
            },
        );
    };

    const moveSession = (day: PlanDay, toDate: string) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { date: toDate },
            { preserveScroll: true },
        );
    };

    const skipSession = (day: PlanDay) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { skipped: true },
            { preserveScroll: true },
        );
    };

    const detailByWeekStart = Object.fromEntries(
        weeks.map((week) => [week.week_start, week]),
    );

    return (
        <>
            <Head title="Plan" />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Plan
                </Eyebrow>
                <div className="mt-2 mb-3 flex items-start justify-between gap-3">
                    <h1 className="font-serif text-quote-lg text-foreground italic">
                        the weeks
                        <br />
                        <em className="text-horizon-ink">ahead.</em>
                    </h1>
                    <PillButton
                        tone="ghost"
                        size="sm"
                        className="mt-1 flex-none"
                        onClick={regenerate}
                        disabled={regenerating || regenerateCooling}
                    >
                        <Icon
                            icon={
                                regenerateCooling
                                    ? 'mdi:clock-outline'
                                    : 'mdi:sync'
                            }
                            className="size-3"
                            aria-hidden
                        />
                        {regenerating
                            ? 'Replanning…'
                            : regenerateCooling
                              ? `Next in ${formatDurationHMS(regenerateCooldown)}`
                              : 'Regenerate'}
                    </PillButton>
                </div>
                <p className="mb-4 text-sm leading-relaxed text-text-2">
                    {race
                        ? `Built around ${race.name ?? 'your race'} on ${formatNaiveIdDate(race.race_date, 'long')}, about ${sessionsPerWeek} sessions a week.`
                        : `No race set yet, so this cycles a steady build-and-deload rhythm, about ${sessionsPerWeek} sessions a week.`}{' '}
                    <Link
                        href="/race"
                        className="focus-ring inline-flex items-center gap-0.5 font-semibold text-horizon-ink"
                    >
                        {race ? 'Change your race' : 'Set a race'}
                        <Icon
                            icon="mdi:arrow-right"
                            className="size-3"
                            aria-hidden
                        />
                    </Link>
                </p>

                <PlanRaceTabs active="plan" className="mb-4" />

                {weeks.length === 0 || season === null ? (
                    <EmptyPanel
                        face
                        title="No plan yet."
                        body="Hit Regenerate and Temari will lay out the weeks ahead."
                        className="mt-6"
                    />
                ) : (
                    <>
                        <SeasonHeaderCard
                            weekIndex={season.week_index}
                            totalWeeks={season.total_weeks}
                            startsAt={season.starts_at}
                            endsAt={season.ends_at}
                            adherencePct={seasonAdherencePct}
                            weeks={seasonSummary}
                            narration={planNarration.season}
                        />
                        <SeasonTimeline
                            weeks={seasonSummary}
                            detailByWeekStart={detailByWeekStart}
                            today={today}
                            weekFocus={adaptation}
                            weekNarration={planNarration.week}
                            dayNarration={planNarration.days}
                            onMove={moveSession}
                            onSkip={skipSession}
                        />
                    </>
                )}

                <Card padding="panel" className="mt-6">
                    <p className="text-label-micro text-text-2">
                        {disclaimerHeadline}
                    </p>
                    <p className="mt-2 text-sm leading-relaxed text-text-2">
                        {disclaimer}
                    </p>
                    <Link
                        href="/training-disclaimer"
                        className="focus-ring mt-2 inline-block text-sm text-text-2 underline underline-offset-2 hover:text-foreground"
                    >
                        What the plan can and cannot see
                    </Link>
                </Card>
            </PageContainer>
        </>
    );
}

Plan.layout = appLayout;
