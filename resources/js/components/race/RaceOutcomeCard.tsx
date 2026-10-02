import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import type { PastRace, RaceOutcomeState } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import PillButton from '@/components/ui/PillButton';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatKm, formatNaiveIdDate } from '@/lib/pace';
import { inputVariants } from '@/lib/variants';

interface RaceOutcomeCardProps {
    race: PastRace;
    className?: string;
}

const STATE_COPY: Record<RaceOutcomeState, string> = {
    pending: 'waiting for you to say how it went',
    confirmed: 'result saved',
    did_not_run: 'marked as not run',
    cancelled: 'marked as called off',
};

const FIELD_LABEL = 'text-label-micro text-text-2';

/**
 * A passed race and what became of it. Nothing is counted until the athlete
 * confirms a run, enters a time, or says they did not run; any answer can be
 * changed later.
 */
export default function RaceOutcomeCard({
    race,
    className,
}: Readonly<RaceOutcomeCardProps>) {
    const { outcome } = race;
    const [changing, setChanging] = useState(false);
    const [hours, setHours] = useState(0);
    const [minutes, setMinutes] = useState(0);
    const [seconds, setSeconds] = useState(0);
    const [processing, setProcessing] = useState(false);

    const timeSec = hours * 3_600 + minutes * 60 + seconds;
    const open = outcome.state === 'pending' || changing;
    const title = race.name ?? `${formatKm(race.distance_m, 1)} km`;

    const post = (payload: Record<string, number | string>) => {
        router.post(`/race/${race.id}/outcome`, payload, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () => setChanging(false),
            onFinish: () => setProcessing(false),
        });
    };

    const saveTime = (event: FormEvent) => {
        event.preventDefault();
        post({ outcome: 'confirmed', finish_time_sec: timeSec });
    };

    return (
        <section
            aria-label={`${title} outcome`}
            className={cn(
                'rounded-lg border border-border bg-card p-4',
                className,
            )}
        >
            <Eyebrow token="micro" tone="ink-2">
                {formatNaiveIdDate(race.race_date)}
            </Eyebrow>
            <h2 className="mt-1 font-serif text-quote-lg text-foreground italic">
                {title}
            </h2>
            <p className="mt-1 text-sm text-text-2">
                {STATE_COPY[outcome.state]}
                {outcome.state === 'confirmed' &&
                    outcome.finish_time_sec !== null &&
                    ` · ${formatDurationHMS(outcome.finish_time_sec)}`}
            </p>

            {outcome.state !== 'pending' && !changing && (
                <button
                    type="button"
                    onClick={() => setChanging(true)}
                    className="focus-ring mt-2 rounded p-1 text-xs font-bold text-horizon-ink"
                >
                    change
                </button>
            )}

            {open && (
                <div className="mt-3 flex flex-col gap-3">
                    {outcome.suggestion && (
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-sm bg-muted px-3 py-2">
                            <span className="text-sm text-foreground">
                                {outcome.suggestion.name ?? 'run'}
                                {' · '}
                                {formatKm(outcome.suggestion.distance_m)} km
                                {' · '}
                                {formatDurationHMS(
                                    outcome.suggestion.elapsed_time_sec,
                                )}
                            </span>
                            <PillButton
                                tone="horizon"
                                size="sm"
                                disabled={processing}
                                onClick={() =>
                                    post({
                                        outcome: 'confirmed',
                                        activity_id:
                                            outcome.suggestion?.activity_id ??
                                            0,
                                    })
                                }
                            >
                                confirm this run
                            </PillButton>
                        </div>
                    )}

                    <form onSubmit={saveTime} className="flex flex-col gap-1.5">
                        <span className={FIELD_LABEL}>or enter your time</span>
                        <div className="flex flex-wrap items-center gap-1.5">
                            <input
                                type="number"
                                min={0}
                                max={71}
                                value={hours}
                                onChange={(e) =>
                                    setHours(Number(e.target.value))
                                }
                                aria-label="Finish hours"
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
                                onChange={(e) =>
                                    setMinutes(Number(e.target.value))
                                }
                                aria-label="Finish minutes"
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
                                onChange={(e) =>
                                    setSeconds(Number(e.target.value))
                                }
                                aria-label="Finish seconds"
                                className={cn(
                                    inputVariants({ size: 'sm' }),
                                    'w-16 text-center',
                                )}
                            />
                            <span className={FIELD_LABEL}>sec</span>
                            <PillButton
                                type="submit"
                                tone="outline"
                                size="sm"
                                disabled={processing || timeSec < 300}
                            >
                                save time
                            </PillButton>
                        </div>
                    </form>

                    <div className="flex flex-wrap gap-2 border-t border-dashed border-border pt-3">
                        <PillButton
                            tone="ghost"
                            size="sm"
                            disabled={processing}
                            onClick={() => post({ outcome: 'did_not_run' })}
                        >
                            i did not run it
                        </PillButton>
                        {changing && (
                            <PillButton
                                tone="ghost"
                                size="sm"
                                onClick={() => setChanging(false)}
                            >
                                keep as is
                            </PillButton>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
