import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import TemariMascot from '@/components/temari/TemariMascot';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate } from '@/lib/pace';
import type { SharedProps } from '@/types/inertia';

interface UnlockEntry {
    unlock_key: string;
    unlocked_at: string;
}

interface UnlockCatalogEntry {
    name: string;
    icon: string;
    description: string;
    criteria: string;
}

interface ProfileProps {
    stats: {
        total_runs: number;
        total_km: number;
        member_since: string | null;
    };
    strava: {
        athlete_id: number;
        scopes: string;
        token_expires_at: string | null;
    } | null;
    unlocks?: UnlockEntry[];
    unlockCatalog?: Record<string, UnlockCatalogEntry>;
}

export default function Profile({ stats, strava, unlocks = [], unlockCatalog = {} }: Readonly<ProfileProps>) {
    const user = usePage<SharedProps>().props.auth.user;
    const unlockedKeys = useMemo(() => new Set(unlocks.map((u) => u.unlock_key)), [unlocks]);
    const catalogEntries = Object.entries(unlockCatalog);

    return (
        <AppShell>
            <Head title="Profil" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-6 py-10"
            >
                <header className="mb-8 flex items-start gap-4">
                    <TemariMascot mood="glow" sizeClass="h-24 w-24 shrink-0 hidden sm:block" idle="breath" ornaments />
                    <div className="flex-1">
                        <h1 className="text-2xl font-semibold tracking-tight text-ink">Profil</h1>
                        <p className="mt-1 text-base leading-relaxed text-ink">
                            Identitas, koneksi Strava, dan ringkasan singkat — total {stats.total_km.toFixed(1)} km dari {stats.total_runs} lari.
                        </p>
                    </div>
                </header>

                {user !== null && (
                    <section className="rounded-2xl border border-line bg-surface-elev p-6 dark:border-line-dark dark:bg-surface-dark-elev">
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                            Identitas
                        </h2>
                        <div className="mt-4 flex items-center gap-4">
                            {user.avatar_url === null ? (
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-500/15 text-brand-600 dark:text-brand-400">
                                    <Icon icon="mdi:account" width={28} height={28} aria-hidden />
                                </div>
                            ) : (
                                <img
                                    src={user.avatar_url}
                                    alt={user.name}
                                    className="h-14 w-14 rounded-full object-cover ring-2 ring-line dark:ring-line-dark"
                                />
                            )}
                            <div>
                                <div className="text-lg font-semibold text-ink dark:text-ink-dark">{user.name}</div>
                                <div className="text-sm text-ink-meta dark:text-ink-meta-dark">
                                    Login via Strava
                                </div>
                            </div>
                        </div>
                    </section>
                )}

                {strava !== null && (
                    <section className="mt-6 rounded-2xl border border-line bg-surface-elev p-6 dark:border-line-dark dark:bg-surface-dark-elev">
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                            Strava
                        </h2>
                        <dl className="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                            <Field label="Athlete ID" value={String(strava.athlete_id)} />
                            <Field label="Scopes" value={strava.scopes} />
                            <Field
                                label="Token expires"
                                value={strava.token_expires_at === null ? '—' : formatIdDate(strava.token_expires_at, 'long')}
                            />
                        </dl>
                    </section>
                )}

                <section className="mt-6 rounded-2xl border border-line bg-surface-elev p-6 dark:border-line-dark dark:bg-surface-dark-elev">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                        Statistik singkat
                    </h2>
                    <div className="mt-4 grid grid-cols-3 gap-3 text-center">
                        <Stat label="Run dianalisis" value={stats.total_runs.toString()} />
                        <Stat label="Total km" value={stats.total_km.toFixed(1)} />
                        <Stat
                            label="Member sejak"
                            value={stats.member_since === null ? '—' : formatIdDate(stats.member_since)}
                        />
                    </div>
                </section>

                {catalogEntries.length > 0 && (
                    <section className="mt-6 rounded-2xl border border-line bg-surface-elev p-6">
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta">Koleksi Aksesori</h2>
                        <p className="mt-2 text-sm text-ink-soft">
                            Aksesori yang Temari kenakan, unlock dari milestones kamu.
                        </p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {catalogEntries.map(([key, def]) => (
                                <UnlockTile key={key} def={def} unlocked={unlockedKeys.has(key)} />
                            ))}
                        </div>
                    </section>
                )}
            </motion.main>
        </AppShell>
    );
}

const UNLOCK_TILE = {
    locked: 'rounded-xl border border-dashed border-line bg-surface-sunken/40 p-4 opacity-60',
    unlocked: 'rounded-xl border border-pop-200 bg-pop-50/40 p-4',
} as const;

function UnlockTile({ def, unlocked }: Readonly<{ def: UnlockCatalogEntry; unlocked: boolean }>) {
    return (
        <div className={unlocked ? UNLOCK_TILE.unlocked : UNLOCK_TILE.locked}>
            <Icon
                icon={def.icon}
                width={28}
                height={28}
                className={unlocked ? 'text-pop-600' : 'text-ink-meta/40'}
                aria-hidden
            />
            <div className="mt-2 text-sm font-semibold text-ink">{def.name}</div>
            <div className="mt-1 text-xs leading-relaxed text-ink-soft">
                {unlocked ? def.description : def.criteria}
            </div>
        </div>
    );
}

function Field({ label, value }: Readonly<{ label: string; value: string }>) {
    return (
        <div>
            <dt className="text-xs text-ink-meta dark:text-ink-meta-dark">{label}</dt>
            <dd className="mt-0.5 font-medium text-ink dark:text-ink-dark">{value}</dd>
        </div>
    );
}

function Stat({ label, value }: Readonly<{ label: string; value: string }>) {
    return (
        <div>
            <div className="text-2xl font-black tabular-nums text-ink dark:text-ink-dark">{value}</div>
            <div className="mt-1 text-xs text-ink-meta dark:text-ink-meta-dark">{label}</div>
        </div>
    );
}
