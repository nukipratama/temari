import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate } from '@/lib/pace';
import type { SharedProps } from '@/types/inertia';

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
}

export default function Profile({ stats, strava }: Readonly<ProfileProps>) {
    const { props } = usePage<SharedProps & ProfileProps>();
    const user = props.auth.user;

    return (
        <AppShell>
            <Head title="Profil" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="mx-auto max-w-3xl px-6 py-10"
            >
                <header className="mb-8">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">Profil</h1>
                    <p className="mt-1 text-base leading-relaxed text-ink dark:text-ink-dark">
                        Identitas, koneksi Strava, dan ringkasan singkat.
                    </p>
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
            </motion.main>
        </AppShell>
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
