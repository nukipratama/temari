import type { FormDataConvertible } from '@inertiajs/core';

import { Head, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { type FormEvent, useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import Temari from '@/components/temari/Temari';
import Card from '@/components/ui/Card';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'Half', km: 21.1 },
    { label: 'Marathon', km: 42.2 },
] as const;

type Step = 'connected' | 'goal';

export default function OnboardingIndex() {
    const firstName = usePage<SharedProps>().props.auth.user?.first_name ?? '';
    const [step, setStep] = useState<Step>('connected');
    const [raceDate, setRaceDate] = useState('');
    const [distanceKm, setDistanceKm] = useState<number>(10);
    const [hours, setHours] = useState(0);
    const [minutes, setMinutes] = useState(50);
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);

    const finish = (payload: Record<string, FormDataConvertible>) => {
        router.post('/onboarding', payload, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const submitGoal = (event: FormEvent) => {
        event.preventDefault();
        finish({
            race_date: raceDate,
            distance_m: Math.round(distanceKm * 1000),
            goal_time_sec: hours * 3_600 + minutes * 60,
            name: name.trim() === '' ? null : name.trim(),
        });
    };

    const skip = () => finish({});

    return (
        <>
            <Head title="Welcome" />
            <PageContainer className="max-w-2xl">
                {step === 'connected' ? (
                    <motion.div
                        key="connected"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                        className="flex flex-col items-center gap-3 py-2 text-center sm:gap-5 sm:py-10"
                    >
                        <Temari pose="glow" size={112} animate />
                        <PageHero
                            size="lg"
                            eyebrow="Step 1 of 2 · Welcome"
                            className="text-center"
                        >
                            You&rsquo;re connected, {firstName}.
                        </PageHero>
                        <p className="mx-auto max-w-md font-sans text-sm leading-relaxed text-ink-2">
                            Your Strava history is already on its way in. While
                            that catches up, let&rsquo;s get one more thing
                            sorted.
                        </p>
                        <PillButton
                            tone="horizon"
                            onClick={() => setStep('goal')}
                        >
                            Continue
                        </PillButton>
                    </motion.div>
                ) : (
                    <motion.div
                        key="goal"
                        variants={fadeInUp}
                        initial="hidden"
                        animate="visible"
                    >
                        <PageHero size="lg" eyebrow="Step 2 of 2 · Optional">
                            Got a race in mind?
                        </PageHero>
                        <p className="mt-3 font-sans text-sm leading-relaxed text-ink-2">
                            Give Temari something to build toward. Skip it if
                            you&rsquo;re not sure yet, you can always set one
                            later from Plan.
                        </p>

                        <Card padding="hero" className="mt-6">
                            <form
                                onSubmit={submitGoal}
                                className="grid grid-cols-1 gap-5 sm:grid-cols-2"
                            >
                                <div>
                                    <label
                                        htmlFor="onboarding_race_name"
                                        className="text-label-micro text-ink-3"
                                    >
                                        Name (optional)
                                    </label>
                                    <input
                                        id="onboarding_race_name"
                                        type="text"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        maxLength={120}
                                        placeholder="Jakarta Half 2026"
                                        className="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus-ring"
                                    />
                                </div>
                                <div>
                                    <label
                                        htmlFor="onboarding_race_date"
                                        className="text-label-micro text-ink-3"
                                    >
                                        Race day
                                    </label>
                                    <input
                                        id="onboarding_race_date"
                                        type="date"
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
                                                setMinutes(
                                                    Number(e.target.value),
                                                )
                                            }
                                            aria-label="Minutes"
                                            className="w-16 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-ink focus-ring"
                                        />
                                        <span className="text-sm text-ink-3">
                                            min
                                        </span>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                                    <PillButton
                                        type="submit"
                                        tone="horizon"
                                        disabled={processing || raceDate === ''}
                                    >
                                        {processing
                                            ? 'Saving…'
                                            : 'Set my goal & finish'}
                                    </PillButton>
                                    <PillButton
                                        type="button"
                                        tone="ghost"
                                        disabled={processing}
                                        onClick={skip}
                                    >
                                        Skip for now
                                    </PillButton>
                                </div>
                            </form>
                        </Card>
                    </motion.div>
                )}
            </PageContainer>
        </>
    );
}

OnboardingIndex.layout = appLayout;
