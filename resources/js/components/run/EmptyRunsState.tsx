import { Icon } from '@iconify/react';
import { Link, usePage, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

import type { SharedProps, StravaSyncState } from '@/types/inertia';

import StravaSyncButton from '@/components/StravaSyncButton';
import Temari from '@/components/temari/Temari';
import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import SectionLabel from '@/components/ui/SectionLabel';

const HERO: Record<
    StravaSyncState,
    { eyebrow: string; headline: string; copy: string }
> = {
    disconnected: {
        eyebrow: '★ Belum nyambung',
        headline: 'Sambungin Strava dulu',
        copy: 'Aku baca lari kamu langsung dari Strava. Sambungin dulu biar kartu pertamamu mulai jalan.',
    },
    revoked: {
        eyebrow: '★ Sambungan putus',
        headline: 'Sambungan Strava putus',
        copy: 'Token Strava kamu udah gak aktif. Sambungin lagi yuk biar lari baru kebaca.',
    },
    syncing: {
        eyebrow: '★ Lagi narik',
        headline: 'Lari kamu lagi ditarik dari Strava',
        copy: 'Sebentar ya, begitu lari pertamamu masuk, aku langsung baca dan kartunya muncul.',
    },
    ready: {
        eyebrow: '★ Belum ada',
        headline: 'Belum nemu lari baru',
        copy: 'Kalau kamu baru kelar lari, coba sync lagi biar langsung kebaca.',
    },
};

const ACTIONS = [
    {
        icon: 'mdi:cards-outline',
        title: 'Cek koleksi yang legendaris',
        desc: 'Liat kartu yang bisa kamu dapetin.',
        href: '/kartu',
    },
    {
        icon: 'mdi:tshirt-crew-outline',
        title: 'Dandanin Temari',
        desc: 'Pilih kombinasi aksesori untuk profilmu.',
        href: '/aksesori',
    },
    {
        icon: 'mdi:chart-line',
        title: 'Lihat rekap lari kamu',
        desc: 'Begitu lari pertama masuk, rekap langsung muncul di sini.',
        href: '/aktivitas',
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
        <div className="flex flex-col items-center gap-8 px-5 py-10 sm:px-8 lg:px-14">
            {/* Temari + headline */}
            <div className="flex flex-col items-center gap-5 text-center">
                <Temari pose="reading" size={140} />
                <div>
                    <Eyebrow token="hero" tone="horizon" className="mb-3">
                        {hero.eyebrow}
                    </Eyebrow>
                    <h2 className="font-display text-display-sm text-ink">
                        {hero.headline}
                    </h2>
                    <p className="mx-auto mt-3 max-w-sm font-display text-quote-sm italic leading-relaxed text-ink-2">
                        &ldquo;{hero.copy}&rdquo;
                    </p>
                </div>

                <StravaSyncButton state={state} />
            </div>

            {/* Sambil nungguin */}
            <Card padding="md" className="w-full max-w-md">
                <SectionLabel>Sambil nungguin</SectionLabel>
                <div className="mt-3 flex flex-col gap-2">
                    {ACTIONS.map(({ icon, title, desc, href }) => (
                        <Link
                            key={title}
                            href={href}
                            className="focus-ring flex items-center gap-3 rounded-xl bg-surface-card px-4 py-3"
                        >
                            <span
                                aria-hidden
                                className="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-horizon/[0.14] text-horizon-deep"
                            >
                                <Icon icon={icon} width={16} height={16} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[13px] font-semibold text-ink">
                                    {title}
                                </div>
                                <div className="mt-0.5 font-mono text-[11px] text-ink-3">
                                    {desc}
                                </div>
                            </div>
                            <span
                                aria-hidden
                                className="font-mono text-[14px] text-ink-3"
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
