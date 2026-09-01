import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import type { RaceProjection } from '@/components/race/ProjectionBlock';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import PillButton from '@/components/ui/PillButton';
import { cn } from '@/lib/cn';
import {
    ambitiousGoalWarning,
    earliestRaceDate,
    goalTimeError,
    impossiblePaceWarning,
} from '@/lib/raceGoal';
import { inputVariants, outlineChipVariants } from '@/lib/variants';

interface RaceGoalFormProps {
    race: {
        race_date: string;
        distance_m: number;
        goal_time_sec: number;
        name: string | null;
    } | null;
    projection: RaceProjection | null;
    className?: string;
}

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'Half', km: 21.1 },
    { label: 'Marathon', km: 42.2 },
] as const;

const FIELD_LABEL = 'text-label-micro text-text-2';

/**
 * The prototype's `RaceGoalForm`. Every control the mockup leaves dead — the
 * name, the race day and the save trigger — is wired to `POST /race` here, and
 * the two warnings are derived rather than staged.
 */
export default function RaceGoalForm({
    race,
    projection,
    className,
}: Readonly<RaceGoalFormProps>) {
    const [raceDate, setRaceDate] = useState(race?.race_date ?? '');
    const [distanceKm, setDistanceKm] = useState(
        race ? race.distance_m / 1_000 : 10,
    );
    const [hours, setHours] = useState(
        race ? Math.floor(race.goal_time_sec / 3_600) : 0,
    );
    const [minutes, setMinutes] = useState(
        race ? Math.floor((race.goal_time_sec % 3_600) / 60) : 50,
    );
    const [seconds, setSeconds] = useState(race ? race.goal_time_sec % 60 : 0);
    const [name, setName] = useState(race?.name ?? '');
    const [processing, setProcessing] = useState(false);

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
                distance_m: Math.round(distanceKm * 1_000),
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
        <Card className={className}>
            <Eyebrow token="micro" tone="ink-2">
                {race ? 'Edit your race' : 'Set your race'}
            </Eyebrow>
            <form onSubmit={submit} className="mt-3.5 flex flex-col gap-3.5">
                <div>
                    <label htmlFor="race_name" className={FIELD_LABEL}>
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
                    <label htmlFor="race_date" className={FIELD_LABEL}>
                        Race day
                    </label>
                    <input
                        id="race_date"
                        type="date"
                        required
                        min={earliestRaceDate()}
                        value={raceDate}
                        onChange={(e) => setRaceDate(e.target.value)}
                        className={cn(inputVariants(), 'mt-1.5')}
                    />
                </div>

                <div>
                    <span className={FIELD_LABEL}>Distance</span>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {DISTANCE_PRESETS.map((preset) => (
                            <button
                                key={preset.label}
                                type="button"
                                onClick={() => setDistanceKm(preset.km)}
                                className={outlineChipVariants({
                                    selected: distanceKm === preset.km,
                                })}
                            >
                                {preset.label}
                            </button>
                        ))}
                    </div>
                    <div className="mt-1.5 flex items-center gap-1.5">
                        <span className={FIELD_LABEL}>Custom</span>
                        <input
                            type="number"
                            min={1}
                            max={300}
                            step={0.1}
                            required
                            value={distanceKm}
                            onChange={(e) =>
                                setDistanceKm(Number(e.target.value))
                            }
                            aria-label="Custom distance in kilometers"
                            className={cn(
                                inputVariants({ size: 'sm' }),
                                'w-20',
                            )}
                        />
                        <span className={FIELD_LABEL}>km</span>
                    </div>
                </div>

                <div>
                    <span className={FIELD_LABEL}>Goal time</span>
                    <div className="mt-1.5 flex items-center gap-1.5">
                        <input
                            type="number"
                            min={0}
                            max={71}
                            value={hours}
                            onChange={(e) => setHours(Number(e.target.value))}
                            aria-label="Hours"
                            className={cn(
                                inputVariants({ size: 'sm' }),
                                'w-16 text-center',
                            )}
                        />
                        <span className={FIELD_LABEL}>hr</span>
                        <input
                            type="number"
                            min={0}
                            max={59}
                            value={minutes}
                            onChange={(e) => setMinutes(Number(e.target.value))}
                            aria-label="Minutes"
                            className={cn(
                                inputVariants({ size: 'sm' }),
                                'w-16 text-center',
                            )}
                        />
                        <span className={FIELD_LABEL}>min</span>
                        <input
                            type="number"
                            min={0}
                            max={59}
                            value={seconds}
                            onChange={(e) => setSeconds(Number(e.target.value))}
                            aria-label="Seconds"
                            className={cn(
                                inputVariants({ size: 'sm' }),
                                'w-16 text-center',
                            )}
                        />
                        <span className={FIELD_LABEL}>sec</span>
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
                                icon="mdi:alert-outline"
                                width={14}
                                height={14}
                                className="mt-0.5 shrink-0"
                                aria-hidden
                            />
                            <span>{goalTimeWarning}</span>
                        </p>
                    )}
                </div>

                <PillButton
                    type="submit"
                    tone="horizon"
                    disabled={processing || goalTimeIssue !== null}
                    className="mt-0.5 w-full justify-center"
                >
                    {processing ? 'Saving…' : race ? 'Update race' : 'Set race'}
                </PillButton>
            </form>
        </Card>
    );
}
