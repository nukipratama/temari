import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import CtlTrendChart, {
    type CtlTrendPoint,
} from '@/components/race/CtlTrendChart';
import Card from '@/components/ui/Card';
import EmptyPanel from '@/components/ui/EmptyPanel';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';

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
    ctlTrend: CtlTrendPoint[];
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

function daysUntil(dateStr: string): number {
    const target = new Date(`${dateStr}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((target.getTime() - today.getTime()) / 86_400_000);
}

export default function Race({
    race,
    projection,
    ctlTrend,
}: Readonly<RaceProps>) {
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

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            '/race',
            {
                race_date: raceDate,
                distance_m: Math.round(distanceKm * 1000),
                goal_time_sec: hours * 3_600 + minutes * 60 + seconds,
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
                <header>
                    <PageHero eyebrow="Race">
                        {race
                            ? 'Your race, on the calendar.'
                            : 'Give the plan something to aim at.'}
                    </PageHero>
                    <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-ink-2">
                        Set a race and Temari projects a realistic finish time
                        from your own PRs, then tracks your fitness trend
                        against it.
                    </p>
                </header>

                {race && (
                    <section className="mt-8">
                        <Card padding="lg">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <SectionLabel>
                                        {race.name ?? 'Your race'}
                                    </SectionLabel>
                                    <p className="font-display text-headline-sm text-ink">
                                        {formatNaiveIdDate(
                                            race.race_date,
                                            'long',
                                        )}
                                    </p>
                                    <p className="mt-1 text-sm text-ink-2">
                                        {daysUntil(race.race_date)} days to go
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-4">
                                    <StatTile
                                        tone="sunken"
                                        size="sm"
                                        label="Distance"
                                        value={(race.distance_m / 1000).toFixed(
                                            1,
                                        )}
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
                            </div>

                            {projection && (
                                <div className="mt-6 border-t border-line pt-6">
                                    <SectionLabel size="micro">
                                        Projected finish
                                    </SectionLabel>
                                    <p className="font-display text-headline-sm text-ink">
                                        {formatDurationHMS(projection.low_sec)}{' '}
                                        &ndash;{' '}
                                        <em className="italic text-horizon-deep">
                                            {formatDurationHMS(
                                                projection.high_sec,
                                            )}
                                        </em>
                                    </p>
                                    <p className="mt-2 text-sm text-ink-2">
                                        Best estimate{' '}
                                        {formatDurationHMS(
                                            projection.predicted_sec,
                                        )}
                                        , from{' '}
                                        {projection.sample_size === 1
                                            ? '1 PR'
                                            : `${projection.sample_size} PRs`}{' '}
                                        (
                                        {CONFIDENCE_COPY[projection.confidence]}
                                        ).
                                    </p>
                                </div>
                            )}
                            {!projection && (
                                <p className="mt-6 border-t border-line pt-6 text-sm text-ink-2">
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
                        pose="proud"
                        title="No race on the calendar yet."
                        body="Set one below and Temari will start projecting your finish time."
                        className="mt-8"
                    />
                )}

                <section className="mt-10">
                    <SectionLabel>
                        {race ? 'Edit your race' : 'Set your race'}
                    </SectionLabel>
                    <Card padding="lg" className="mt-3">
                        <form
                            onSubmit={submit}
                            className="grid grid-cols-1 gap-5 sm:grid-cols-2"
                        >
                            <div>
                                <label
                                    htmlFor="race_name"
                                    className="text-label-micro text-ink-3"
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
                                    className="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus-ring"
                                />
                            </div>
                            <div>
                                <label
                                    htmlFor="race_date"
                                    className="text-label-micro text-ink-3"
                                >
                                    Race day
                                </label>
                                <input
                                    id="race_date"
                                    type="date"
                                    required
                                    value={raceDate}
                                    onChange={(e) =>
                                        setRaceDate(e.target.value)
                                    }
                                    className="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus-ring"
                                />
                            </div>

                            <div className="sm:col-span-2">
                                <span className="text-label-micro text-ink-3">
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
                                            className={cn(
                                                'focus-ring rounded-full border px-3 py-1.5 text-label-micro transition',
                                                distanceKm === preset.km
                                                    ? 'border-horizon bg-horizon/10 text-horizon-deep'
                                                    : 'border-line text-ink-3 hover:border-horizon/60 hover:text-ink',
                                            )}
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
                                            className="w-20 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink focus-ring"
                                        />
                                        <span className="text-sm text-ink-3">
                                            km
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="sm:col-span-2">
                                <span className="text-label-micro text-ink-3">
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
                                        className="w-16 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink focus-ring"
                                    />
                                    <span className="text-sm text-ink-3">
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
                                        className="w-16 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink focus-ring"
                                    />
                                    <span className="text-sm text-ink-3">
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
                                        className="w-16 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink focus-ring"
                                    />
                                    <span className="text-sm text-ink-3">
                                        sec
                                    </span>
                                </div>
                            </div>

                            <div className="sm:col-span-2">
                                <PillButton
                                    type="submit"
                                    tone="horizon"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Saving…'
                                        : race
                                          ? 'Update race'
                                          : 'Set race'}
                                </PillButton>
                            </div>
                        </form>
                    </Card>
                </section>

                <section className="mt-10">
                    <SectionLabel>Fitness · last 90 days</SectionLabel>
                    <Card padding="lg" className="mt-3">
                        <CtlTrendChart trend={ctlTrend} />
                    </Card>
                </section>
            </PageContainer>
        </>
    );
}

Race.layout = appLayout;
