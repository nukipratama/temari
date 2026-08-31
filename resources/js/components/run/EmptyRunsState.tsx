import { Link, usePage, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

import type { SharedProps, StravaSyncState } from '@/types/inertia';

import StravaSyncButton from '@/components/StravaSyncButton';
import FaceIcon from '@/components/temari/FaceIcon';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';

const HERO: Record<
    StravaSyncState,
    { eyebrow: string; headline: string; copy: string }
> = {
    disconnected: {
        eyebrow: '★ Not connected',
        headline: 'Connect Strava first',
        copy: 'I read your runs straight from Strava. Connect it first to get your first card going.',
    },
    revoked: {
        eyebrow: '★ Disconnected',
        headline: 'Strava connection lost',
        copy: "Your Strava token isn't active anymore. Reconnect so new runs can be read.",
    },
    syncing: {
        eyebrow: '★ Syncing',
        headline: 'Your runs are being pulled from Strava',
        copy: "Hang tight, the moment your first run comes in, I'll read it and the card will show up.",
    },
    ready: {
        eyebrow: '★ Nothing yet',
        headline: 'No new runs found yet',
        copy: 'If you just finished a run, try syncing again so it gets picked up.',
    },
};

const ACTIONS = [
    {
        icon: 'mdi:tshirt-crew-outline',
        title: 'Dress up Temari',
        desc: 'Pick an accessory combo for your profile.',
        href: '/accessories',
    },
    {
        icon: 'mdi:chart-line',
        title: 'See your run recap',
        desc: 'Once your first run comes in, the recap shows up here.',
        href: '/history',
    },
] as const;

export default function EmptyRunsState({
    onSky = false,
}: Readonly<{
    /** Cream-on-dark treatment for use on a HeroPanel/sky background. */
    onSky?: boolean;
}> = {}) {
    const { stravaSync } = usePage<SharedProps>().props;
    const state: StravaSyncState = stravaSync?.state ?? 'disconnected';
    const hero = HERO[state];
    const isSyncing = state === 'syncing';

    // Post-connect backfill runs server-side with no push channel back to the
    // client, so this is the only way the first card lands without a manual
    // reload. Stops the moment `stravaSync.state` flips off `syncing`.
    const { start, stop } = usePoll(
        7000,
        { only: ['recentRuns', 'stravaSync'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (isSyncing) {
            start();
        } else {
            stop();
        }
    }, [isSyncing, start, stop]);

    return (
        <div className="flex flex-col items-center gap-8 px-4 py-10">
            {/* Temari + headline */}
            <div className="flex flex-col items-center gap-5 text-center">
                <FaceIcon size={72} />
                <div>
                    <Eyebrow token="hero" tone="horizon-ink" className="mb-3">
                        {hero.eyebrow}
                    </Eyebrow>
                    <h2
                        className={cn(
                            'font-serif text-display-sm',
                            onSky ? 'text-cream' : 'text-foreground',
                        )}
                    >
                        {hero.headline}
                    </h2>
                    <p
                        className={cn(
                            'mx-auto mt-3 max-w-sm font-serif text-quote-sm italic leading-relaxed',
                            onSky ? 'text-ink-on-sky' : 'text-text-2',
                        )}
                    >
                        &ldquo;{hero.copy}&rdquo;
                    </p>
                </div>

                <StravaSyncButton state={state} />
            </div>

            {/* While you wait */}
            <Card
                tone={onSky ? 'onSky' : 'card'}
                padding="card"
                className="w-full max-w-md"
            >
                <SectionLabel onSky={onSky}>While you wait</SectionLabel>
                <div className="mt-3 flex flex-col gap-2">
                    {ACTIONS.map(({ icon, title, desc, href }) => (
                        <Link
                            key={title}
                            href={href}
                            className={cn(
                                'focus-ring flex items-center gap-3 rounded-xl px-4 py-3',
                                onSky ? 'bg-cream/[0.08]' : 'bg-card',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex h-8 w-8 flex-none items-center justify-center rounded-lg',
                                    onSky
                                        ? 'bg-cream/10 text-horizon'
                                        : 'bg-horizon/[0.14] text-horizon-ink',
                                )}
                            >
                                <Icon icon={icon} width={16} height={16} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div
                                    className={cn(
                                        'text-[13px] font-semibold',
                                        onSky
                                            ? 'text-cream'
                                            : 'text-foreground',
                                    )}
                                >
                                    {title}
                                </div>
                                <div
                                    className={cn(
                                        'mt-0.5 font-mono text-[11px]',
                                        onSky
                                            ? 'text-ink-on-sky'
                                            : 'text-text-3',
                                    )}
                                >
                                    {desc}
                                </div>
                            </div>
                            <span
                                aria-hidden
                                className={cn(
                                    'font-mono text-[14px]',
                                    onSky ? 'text-ink-on-sky' : 'text-text-3',
                                )}
                            >
                                ›
                            </span>
                        </Link>
                    ))}
                </div>
            </Card>
        </div>
    );
}
