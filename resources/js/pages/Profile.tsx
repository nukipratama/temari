import { Head, Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import DecorativeBlur from '@/components/DecorativeBlur';
import PageHero from '@/components/PageHero';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate } from '@/lib/pace';
import { PR_CATEGORY_LABELS, formatPrValue } from '@/lib/pr';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types/inertia';

interface IdentityPayload {
    name: string;
    avatar_url: string | null;
    first_run_at: string | null;
    member_since: string | null;
    strava_connected: boolean;
}

interface StatsPayload {
    total_runs: number;
    total_km: number;
    longest_run_km: number;
}

interface TopPrEntry {
    id: number;
    category: string;
    value_sec: number;
    set_at: string;
    activity_id: number | null;
    activity_name: string | null;
}

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
    identity: IdentityPayload;
    stats: StatsPayload;
    topPrs?: TopPrEntry[];
    unlocks?: UnlockEntry[];
    unlockCatalog?: Record<string, UnlockCatalogEntry>;
}

export default function Profile({ identity, stats, topPrs = [], unlocks = [], unlockCatalog = {} }: Readonly<ProfileProps>) {
    const sharedUser = usePage<SharedProps>().props.auth.user;
    const unlockedKeys = useMemo(() => new Set(unlocks.map((u) => u.unlock_key)), [unlocks]);
    const catalogEntries = Object.entries(unlockCatalog);
    const unlockedCount = catalogEntries.filter(([key]) => unlockedKeys.has(key)).length;

    return (
        <AppShell>
            <Head title="Profil" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-4 py-6 sm:px-6 sm:py-10"
            >
                <PageHero
                    icon="mdi:account-circle-outline"
                    title="Profil"
                    subtitle="Identitas + perjalanan lari kamu sejauh ini."
                    className="mb-6"
                />

                <IdentityCard identity={identity} fallbackAvatarUrl={sharedUser?.avatar_url ?? null} />

                <HeroStats stats={stats} className="mt-6" />

                {topPrs.length > 0 && <TopPrsSection prs={topPrs} className="mt-6" />}

                {catalogEntries.length > 0 && (
                    <UnlocksSection
                        catalog={catalogEntries}
                        unlockedKeys={unlockedKeys}
                        unlockedCount={unlockedCount}
                        className="mt-6"
                    />
                )}
            </motion.main>
        </AppShell>
    );
}

function IdentityCard({
    identity,
    fallbackAvatarUrl,
}: Readonly<{ identity: IdentityPayload; fallbackAvatarUrl: string | null }>) {
    const sinceLabel = runningSinceLabel(identity.first_run_at ?? identity.member_since);
    const avatarUrl = identity.avatar_url ?? fallbackAvatarUrl;

    return (
        <section className="relative overflow-hidden rounded-2xl border border-line bg-gradient-to-br from-brand-50 via-surface-warm to-accent-50 p-5 shadow-sm sm:p-6">
            <DecorativeBlur className="-right-12 -top-12 h-32 w-32 bg-brand-200/40" />
            <DecorativeBlur className="-bottom-16 -left-10 h-32 w-32 bg-accent-200/40" />
            <div className="relative flex items-center gap-4">
                {avatarUrl === null ? (
                    <div className="flex h-16 w-16 items-center justify-center rounded-full bg-brand-500/20 text-brand-700 ring-2 ring-white">
                        <Icon icon="mdi:account" width={32} height={32} aria-hidden />
                    </div>
                ) : (
                    <img
                        src={avatarUrl}
                        alt={identity.name}
                        className="h-16 w-16 rounded-full object-cover ring-2 ring-white shadow-sm"
                    />
                )}
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-bold tracking-tight text-ink sm:text-xl">{identity.name}</h2>
                    {sinceLabel && (
                        <p className="mt-0.5 text-sm text-ink-soft">{sinceLabel}</p>
                    )}
                    {identity.strava_connected && (
                        <span className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-strava-orange/15 px-2.5 py-1 text-xs font-semibold text-strava-orange">
                            <Icon icon="simple-icons:strava" width={12} height={12} aria-hidden />
                            Tersambung dengan Strava
                        </span>
                    )}
                </div>
            </div>
        </section>
    );
}

function HeroStats({ stats, className }: Readonly<{ stats: StatsPayload; className?: string }>) {
    return (
        <section className={cn('grid grid-cols-3 gap-2 sm:gap-3', className)}>
            <StatTile
                label="Total km"
                value={stats.total_km.toFixed(1)}
                suffix="km"
                icon="mdi:map-marker-distance"
                tone="brand"
            />
            <StatTile
                label="Total lari"
                value={stats.total_runs.toString()}
                suffix="lari"
                icon="mdi:run"
                tone="accent"
            />
            <StatTile
                label="Lari terjauh"
                value={stats.longest_run_km > 0 ? stats.longest_run_km.toFixed(2) : '—'}
                suffix={stats.longest_run_km > 0 ? 'km' : null}
                icon="mdi:trophy-variant-outline"
                tone="pop"
            />
        </section>
    );
}

interface StatTileProps {
    label: string;
    value: string;
    suffix: string | null;
    icon: string;
    tone: 'brand' | 'accent' | 'pop';
}

const STAT_TILE_TONE: Record<StatTileProps['tone'], { bg: string; border: string; value: string; icon: string }> = {
    brand: {
        bg: 'bg-gradient-to-br from-brand-50 via-surface-elev to-brand-100/60',
        border: 'border-brand-200',
        value: 'text-brand-800',
        icon: 'bg-brand-500',
    },
    accent: {
        bg: 'bg-gradient-to-br from-accent-50 via-surface-elev to-accent-100/60',
        border: 'border-accent-200',
        value: 'text-accent-800',
        icon: 'bg-accent-500',
    },
    pop: {
        bg: 'bg-gradient-to-br from-pop-50 via-surface-elev to-pop-100/70',
        border: 'border-pop-200',
        value: 'text-pop-800',
        icon: 'bg-pop-500',
    },
};

function StatTile({ label, value, suffix, icon, tone }: Readonly<StatTileProps>) {
    const cls = STAT_TILE_TONE[tone];
    return (
        <div className={cn('relative overflow-hidden rounded-2xl border p-3 shadow-md sm:p-4', cls.bg, cls.border)}>
            <DecorativeBlur intensity="md" className="-right-6 -top-6 h-16 w-16 bg-white/40" />
            <div className="relative flex items-center justify-between gap-2">
                <span className="text-[10px] font-semibold uppercase tracking-wider text-ink-meta">{label}</span>
                <span
                    aria-hidden
                    className={cn('flex h-7 w-7 items-center justify-center rounded-lg text-white shadow-sm ring-1 ring-white/60 sm:h-8 sm:w-8', cls.icon)}
                >
                    <Icon icon={icon} width={14} height={14} />
                </span>
            </div>
            <div className={cn('relative mt-2 text-2xl font-black tabular-nums sm:text-3xl', cls.value)}>{value}</div>
            {suffix !== null && (
                <div className="relative text-[11px] font-semibold uppercase tracking-wider text-ink-meta">{suffix}</div>
            )}
        </div>
    );
}

function TopPrsSection({ prs, className }: Readonly<{ prs: TopPrEntry[]; className?: string }>) {
    return (
        <section className={cn('rounded-2xl border border-line bg-surface-elev p-4 shadow-sm sm:p-5', className)}>
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta">Rekor terbaru</h2>
                <Link
                    href="/rekor"
                    className="inline-flex items-center gap-0.5 text-xs font-semibold text-brand-700 hover:text-brand-800"
                >
                    Semua rekor
                    <Icon icon="mdi:chevron-right" width={14} height={14} aria-hidden />
                </Link>
            </div>
            <div className="mt-3 grid gap-2 sm:grid-cols-3">
                {prs.map((pr) => (
                    <TopPrTile key={pr.id} pr={pr} />
                ))}
            </div>
        </section>
    );
}

function TopPrTile({ pr }: Readonly<{ pr: TopPrEntry }>) {
    const body = (
        <>
            <div className="text-[10px] font-semibold uppercase tracking-wider text-pop-700">
                {PR_CATEGORY_LABELS[pr.category] ?? pr.category}
            </div>
            <div className="mt-1 text-xl font-black tabular-nums text-pop-800">
                {formatPrValue(pr.category, pr.value_sec)}
            </div>
            <div className="mt-1 truncate text-xs text-ink-soft">{pr.activity_name ?? 'Aktivitas'}</div>
            <div className="text-[11px] text-ink-meta">{formatIdDate(pr.set_at, 'long')}</div>
        </>
    );

    const chrome = 'block rounded-xl border border-pop-200 bg-gradient-to-br from-pop-50 to-pop-100/40 p-3 transition';

    if (pr.activity_id !== null) {
        return (
            <Link
                href={`/aktivitas/${pr.activity_id}`}
                className={cn(chrome, 'hover:-translate-y-0.5 hover:border-pop-400 hover:shadow-md')}
            >
                {body}
            </Link>
        );
    }

    return <div className={chrome}>{body}</div>;
}

function UnlocksSection({
    catalog,
    unlockedKeys,
    unlockedCount,
    className,
}: Readonly<{
    catalog: Array<[string, UnlockCatalogEntry]>;
    unlockedKeys: Set<string>;
    unlockedCount: number;
    className?: string;
}>) {
    return (
        <section className={cn('rounded-2xl border border-line bg-surface-elev p-4 shadow-sm sm:p-5', className)}>
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta">Koleksi Aksesori</h2>
                <span className="text-xs font-semibold text-ink-meta">
                    {unlockedCount}/{catalog.length}
                </span>
            </div>
            <p className="mt-1 text-sm text-ink-soft">
                Aksesori yang aku kenakan, terbuka dari milestone kamu.
            </p>
            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {catalog.map(([key, def]) => (
                    <UnlockTile key={key} def={def} unlocked={unlockedKeys.has(key)} />
                ))}
            </div>
        </section>
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

function runningSinceLabel(iso: string | null): string | null {
    if (iso === null) return null;
    const first = new Date(iso);
    if (Number.isNaN(first.getTime())) return null;

    const diffDays = Math.floor((Date.now() - first.getTime()) / (1000 * 60 * 60 * 24));
    if (diffDays < 7) {
        return `Mulai berlari ${formatIdDate(iso, 'long')}`;
    }
    if (diffDays < 60) {
        return `Berlari sejak ${Math.floor(diffDays / 7)} minggu lalu`;
    }
    const months = Math.floor(diffDays / 30);
    if (months < 24) {
        return `Berlari sejak ${months} bulan lalu`;
    }
    return `Berlari sejak ${Math.floor(months / 12)} tahun lalu`;
}
