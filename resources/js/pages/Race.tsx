import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { type FormEvent, useState } from 'react';

import PlanRaceTabs from '@/components/race/PlanRaceTabs';
import ProjectionGauge from '@/components/race/ProjectionGauge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import EmptyPanel from '@/components/ui/EmptyPanel';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { daysUntilId, formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';
import {
    ambitiousGoalWarning,
    earliestRaceDate,
    goalTimeError,
    impossiblePaceWarning,
} from '@/lib/raceGoal';
import { inputVariants, outlineChipVariants } from '@/lib/variants';

interface RacePayload {
    id: number;
    race_date: string;
    distance_m: number;
    goal_time_sec: number;
    name: string | null;
}

interface ProjectionPayload {
    predicted_sec: number;
    low_sec: number;
    high_sec: number;
    exponent: number;
    sample_size: number;
    confidence: 'low' | 'medium' | 'high';
}

interface RaceProps {
    race: RacePayload | null;
    projection: ProjectionPayload | null;
}

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'Half', km: 21.1 },
    { label: 'Marathon', km: 42.2 },
] as const;

const CONFIDENCE_COPY: Record<ProjectionPayload['confidence'], string> = {
    low: 'wide range, thin PR sample',
    medium: 'moderate range',
    high: 'narrow range, well-fitted',
};

export default function Race({ race, projection }: Readonly<RaceProps>) {
    const [raceDate, setRaceDate] = useState(race?.race_date ?? '');
    const [distanceKm, setDistanceKm] = useState(
        race ? race.distance_m / 1000 : 10,
    );
    const [hours, setHours] = useState(
        race ? Math.floor(race.goal_time_sec / 3600) : 0,
    );
    const [minutes, setMinutes] = useState(
        race ? Math.floor((race.goal_time_sec % 3600) / 60) : 50,
    );
    const [seconds, setSeconds] = useState(race ? race.goal_time_sec % 60 : 0);
    const [name, setName] = useState(race?.name ?? '');
    const [processing, setProcessing] = useState(false);

    const daysUntilCount = useCountUp(race ? daysUntilId(race.race_date) : 0);
    const predictedSecCount = useCountUp(projection?.predicted_sec ?? 0);

    const goalTimeSec = hours * 3_600 + minutes * 60 + seconds;
    const goalTimeIssue = goalTimeError(goalTimeSec);
    // `projection` is always computed server-side from the saved race's own
    // distance, never the form's live distance state - so that's the only
    // distance a client-side warning can compare the form against.
    const projectionForWarning =
        race && projection
            ? {
                  distanceKm: race.distance_m / 1_000,
                  lowSec: projection.low_sec,
                  highSec: projection.high_sec,
              }
            : null;
    const goalTimeWarning =
        impossiblePaceWarning(distanceKm, goalTimeSec) ??
        ambitiousGoalWarning(distanceKm, goalTimeSec, projectionForWarning);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            '/race',
            {
                race_date: raceDate,
                distance_m: Math.round(distanceKm * 1000),
                goal_time_sec: goalTimeSec,
                name: name.trim() === '' ? null : name.trim(),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Race" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PlanRaceTabs active="race" />
                    <div>
                        <PageHero eyebrow="Race" size="quote-lg" italic>
                            {race ? (
                                <>
                                    your race,
                                    <br />
                                    <em className="italic text-icon-accent">
                                        on the calendar.
                                    </em>
                                </>
                            ) : (
                                <>
                                    give the plan
                                    <br />
                                    <em className="italic text-icon-accent">
                                        something to aim at.
                                    </em>
                                </>
                            )}
                        </PageHero>
                        <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-text-2">
                            Set a race and Temari projects a realistic finish
                            time from your own PRs, then tracks your fitness
                            trend against it.
                        </p>
                    </div>
                </header>

                {race && (
                    <section
                        className="mt-8 flex flex-col gap-3"
                        data-coachmark="race-goal"
                    >
                        <Card className="px-6 py-6">
                            <SectionLabel>
                                <span className="inline-flex items-center gap-1.5">
                                    <Icon
                                        icon="mdi:flag-checkered"
                                        width={14}
                                        height={14}
                                        className="text-icon-accent"
                                        aria-hidden
                                    />
                                    {race.name ?? 'Your race'}
                                </span>
                            </SectionLabel>
                            <p className="font-serif text-headline-sm text-foreground">
                                {formatNaiveIdDate(race.race_date, 'long')}
                            </p>
                            <p className="mt-1 text-sm text-text-2">
                                {Math.round(daysUntilCount)} days to go
                            </p>
                            <div className="mt-4 flex flex-wrap gap-4 border-t border-border pt-4">
                                <StatTile
                                    tone="sunken"
                                    size="sm"
                                    label="Distance"
                                    value={(race.distance_m / 1000).toFixed(1)}
                                    unit="km"
                                />
                                <StatTile
                                    tone="sunken"
                                    size="sm"
                                    label="Goal time"
                                    value={formatDurationHMS(
                                        race.goal_time_sec,
                                    )}
                                />
                            </div>
                        </Card>

                        <Card className="px-6 py-6">
                            {projection ? (
                                <motion.div
                                    initial="hidden"
                                    animate="visible"
                                    variants={fadeInUp}
                                >
                                    <SectionLabel size="micro">
                                        Projected finish
                                    </SectionLabel>
                                    <div className="mt-2 flex justify-center">
                                        <ProjectionGauge
                                            lowSec={projection.low_sec}
                                            predictedSec={
                                                projection.predicted_sec
                                            }
                                            highSec={projection.high_sec}
                                        />
                                    </div>
                                    <p className="mt-1 text-center font-serif text-headline-sm text-icon-accent">
                                        {formatDurationHMS(
                                            Math.round(predictedSecCount),
                                        )}
                                    </p>
                                    <p className="mt-2 text-center text-sm text-text-2">
                                        Best estimate, from{' '}
                                        {projection.sample_size === 1
                                            ? '1 PR'
                                            : `${projection.sample_size} PRs`}{' '}
                                        (
                                        {CONFIDENCE_COPY[projection.confidence]}
                                        ).
                                    </p>
                                </motion.div>
                            ) : (
                                <p className="text-sm text-text-2">
                                    No personal record yet to project a finish
                                    time from. Set one on a run and it shows up
                                    here.
                                </p>
                            )}
                        </Card>
                    </section>
                )}

                {!race && (
                    <EmptyPanel
                        face
                        title="No race on the calendar yet."
                        body="Set one below and Temari will start projecting your finish time."
                        className="mt-8"
                    />
                )}

                <section className="mt-10" data-coachmark="race-form">
                    <SectionLabel>
                        {race ? 'Edit your race' : 'Set your race'}
                    </SectionLabel>
                    <Card className="mt-3 px-6 py-6">
                        <form
                            onSubmit={submit}
                            className="grid grid-cols-1 gap-5"
                        >
                            <div>
                                <label
                                    htmlFor="race_name"
                                    className="text-label-micro text-text-3"
                                >
                                    Name (optional)
                                </label>
                                <input
                                    id="race_name"
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    maxLength={120}
                                    placeholder="Jakarta Half 2026"
                                    className={cn(inputVariants(), 'mt-1.5')}
                                />
                            </div>
                            <div>
                                <label
                                    htmlFor="race_date"
                                    className="text-label-micro text-text-3"
                                >
                                    Race day
                                </label>
                                <input
                                    id="race_date"
                                    type="date"
                                    required
                                    min={earliestRaceDate()}
                                    value={raceDate}
                                    onChange={(e) =>
                                        setRaceDate(e.target.value)
                                    }
                                    className={cn(inputVariants(), 'mt-1.5')}
                                />
                            </div>

                            <div>
                                <span className="text-label-micro text-text-3">
                                    Distance
                                </span>
                                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                    {DISTANCE_PRESETS.map((preset) => (
                                        <button
                                            key={preset.label}
                                            type="button"
                                            onClick={() =>
                                                setDistanceKm(preset.km)
                                            }
                                            className={outlineChipVariants({
                                                selected:
                                                    distanceKm === preset.km,
                                            })}
                                        >
                                            {preset.label}
                                        </button>
                                    ))}
                                    <div className="flex items-center gap-1.5">
                                        <input
                                            type="number"
                                            min={1}
                                            max={300}
                                            step={0.1}
                                            required
                                            value={distanceKm}
                                            onChange={(e) =>
                                                setDistanceKm(
                                                    Number(e.target.value),
                                                )
                                            }
                                            aria-label="Custom distance in kilometers"
                                            className={cn(
                                                inputVariants({ size: 'sm' }),
                                                'w-20',
                                            )}
                                        />
                                        <span className="text-sm text-text-3">
                                            km
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <span className="text-label-micro text-text-3">
                                    Goal time
                                </span>
                                <div className="mt-1.5 flex items-center gap-1.5">
                                    <input
                                        type="number"
                                        min={0}
                                        max={71}
                                        value={hours}
                                        onChange={(e) =>
                                            setHours(Number(e.target.value))
                                        }
                                        aria-label="Hours"
                                        className={cn(
                                            inputVariants({ size: 'sm' }),
                                            'w-16',
                                        )}
                                    />
                                    <span className="text-sm text-text-3">
                                        hr
                                    </span>
                                    <input
                                        type="number"
                                        min={0}
                                        max={59}
                                        value={minutes}
                                        onChange={(e) =>
                                            setMinutes(Number(e.target.value))
                                        }
                                        aria-label="Minutes"
                                        className={cn(
                                            inputVariants({ size: 'sm' }),
                                            'w-16',
                                        )}
                                    />
                                    <span className="text-sm text-text-3">
                                        min
                                    </span>
                                    <input
                                        type="number"
                                        min={0}
                                        max={59}
                                        value={seconds}
                                        onChange={(e) =>
                                            setSeconds(Number(e.target.value))
                                        }
                                        aria-label="Seconds"
                                        className={cn(
                                            inputVariants({ size: 'sm' }),
                                            'w-16',
                                        )}
                                    />
                                    <span className="text-sm text-text-3">
                                        sec
                                    </span>
                                </div>
                                {goalTimeIssue && (
                                    <p
                                        role="alert"
                                        className="mt-1.5 font-sans text-xs text-ember-ink"
                                    >
                                        {goalTimeIssue}
                                    </p>
                                )}
                                {!goalTimeIssue && goalTimeWarning && (
                                    <p
                                        role="alert"
                                        className="mt-2 flex items-start gap-1.5 rounded-sm bg-ember/8 px-2.5 py-2 font-sans text-xs leading-relaxed text-ember-ink"
                                    >
                                        <Icon
                                            icon="mdi:alert-circle-outline"
                                            width={14}
                                            height={14}
                                            className="mt-0.5 shrink-0"
                                            aria-hidden
                                        />
                                        <span>{goalTimeWarning}</span>
                                    </p>
                                )}
                            </div>

                            <div>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || goalTimeIssue !== null
                                    }
                                >
                                    {processing
                                        ? 'Saving…'
                                        : race
                                          ? 'Update race'
                                          : 'Set race'}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </section>
            </PageContainer>
        </>
    );
}

Race.layout = appLayout;
