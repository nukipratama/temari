import { Link, usePage, usePoll } from '@inertiajs/react';
import { ChartLine } from 'lucide-react';
import { useEffect } from 'react';

import type { SharedProps, StravaSyncState } from '@/types/inertia';

import StravaSyncButton from '@/components/StravaSyncButton';
import TemariMascot from '@/components/temari/TemariMascot';
import { Card } from '@/components/ui/card';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import SectionLabel from '@/components/ui/SectionLabel';

const HERO: Record<
    StravaSyncState,
    { eyebrow: string; headline: string; copy: string }
> = {
    disconnected: {
        eyebrow: '★ Not connected',
        headline: 'connect Strava first',
        copy: 'i read your runs straight from Strava. connect it first to get your first card going.',
    },
    revoked: {
        eyebrow: '★ Disconnected',
        headline: 'Strava connection lost',
        copy: "your Strava connection isn't active anymore. reconnect so new runs can be read.",
    },
    syncing: {
        eyebrow: '★ Syncing',
        headline: 'your runs are being pulled from Strava',
        copy: "hang tight, the moment your first run comes in, i'll read it and the card will show up.",
    },
    failed: {
        eyebrow: '★ Sync stalled',
        headline: "that sync didn't make it through",
        copy: "something went wrong pulling your runs from Strava. give it another sync and i'll try again.",
    },
    ready: {
        eyebrow: '★ Nothing yet',
        headline: 'no new runs found yet',
        copy: 'if you just finished a run, try syncing again so it gets picked up.',
    },
};

const ACTIONS = [
    {
        icon: ChartLine,
        title: 'see your run recap',
        desc: 'once your first run comes in, the recap shows up here.',
        href: '/history',
    },
] as const;

export default function EmptyRunsState() {
    const { stravaSync } = usePage<SharedProps>().props;
    const state: StravaSyncState = stravaSync?.state ?? 'disconnected';
    const hero = HERO[state];
    const isSyncing = state === 'syncing';

    // Post-connect backfill runs server-side with no push channel back to the
    // client, so this is the only way the first card lands without a manual
    // reload. Stops the moment `stravaSync.state` flips off `syncing`.
    const { start, stop } = usePoll(
        7000,
        { only: ['hasRuns', 'stravaSync'] },
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
                <div>
                    <div className="mb-3 flex items-center justify-center gap-2.5">
                        <TemariMascot
                            pose={isSyncing ? 'thinking' : 'sleepy'}
                            size={40}
                        />
                        <Eyebrow token="hero" tone="horizon-ink">
                            {hero.eyebrow}
                        </Eyebrow>
                    </div>
                    <h2 className="font-serif text-display-sm text-foreground">
                        {hero.headline}
                    </h2>
                    <p className="mx-auto mt-3 max-w-sm text-quote-sm leading-relaxed text-text-2">
                        &ldquo;{hero.copy}&rdquo;
                    </p>
                </div>

                <StravaSyncButton state={state} />
            </div>

            {/* While you wait */}
            <Card className="w-full max-w-md">
                <SectionLabel>While you wait</SectionLabel>
                <div className="mt-3 flex flex-col gap-2">
                    {ACTIONS.map(({ icon, title, desc, href }) => (
                        <Link
                            key={title}
                            href={href}
                            className="focus-ring flex items-center gap-3 rounded-xl bg-card px-4 py-3"
                        >
                            <span
                                aria-hidden
                                className="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-horizon/[0.14] text-horizon-ink"
                            >
                                <Icon icon={icon} width={16} height={16} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-xs font-semibold text-foreground">
                                    {title}
                                </div>
                                <div className="mt-0.5 font-mono text-[0.6875rem] text-text-3">
                                    {desc}
                                </div>
                            </div>
                            <span
                                aria-hidden
                                className="font-mono text-sm text-text-3"
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
