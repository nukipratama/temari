import { Head, Link, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import PersonaBar, { type PersonaSlice } from '@/components/PersonaBar';
import PrCard from '@/components/card/PrCard';
import SectionLabel from '@/components/ui/SectionLabel';
import TemariProto from '@/components/temari/TemariProto';
import VoiceCard from '@/components/temari/VoiceCard';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate, formatShortDateId, monthsSinceId } from '@/lib/pace';
import { PR_CATEGORY_LABELS, formatPrValue } from '@/lib/pr';
import type { AnalysisPayload, SharedProps } from '@/types/inertia';

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

interface AkuProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    topPrs?: TopPrEntry[];
    unlocks?: UnlockEntry[];
    unlockCatalog?: Record<string, UnlockCatalogEntry>;
    personaMix?: PersonaSlice[];
    personaSummary?: AnalysisPayload;
}

export default function Aku({
    identity,
    stats,
    topPrs = [],
    unlocks = [],
    unlockCatalog = {},
    personaMix = [],
    personaSummary,
}: Readonly<AkuProps>) {
    const sharedUser = usePage<SharedProps>().props.auth.user;
    const firstName = sharedUser?.first_name ?? identity.name.split(' ')[0] ?? '';
    const firstRunShort = identity.first_run_at ? formatShortDateId(identity.first_run_at) : null;
    const memberSince = identity.member_since ? formatIdDate(identity.member_since, 'long') : null;
    const monthsSinceFirstRun = monthsSinceId(identity.first_run_at);

    const eyebrowParts: string[] = ['Aku'];
    if (firstRunShort) eyebrowParts.push(`berlari sejak ${firstRunShort}`);
    if (monthsSinceFirstRun !== null) eyebrowParts.push(`${monthsSinceFirstRun} bulan`);

    return (
        <AppShell>
            <Head title="Aku" />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-10"
            >
                <header className="mb-8">
                    <div className="mb-3.5 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-ink-3 lg:text-xs">
                        {eyebrowParts.join(' · ')}
                    </div>
                    <h1 className="font-display text-display-lg text-ink">
                        {firstName ? `${firstName} Runner,` : 'Aku,'}<br />
                        <em className="italic text-horizon-deep">ceritanya.</em>
                    </h1>
                </header>

                <HeroPanel className="lg:px-12 lg:py-10">
                    <div className="grid items-center gap-6 lg:grid-cols-[160px_1fr]">
                        <div className="flex justify-center lg:justify-start">
                            <TemariProto pose="proud" size={160} />
                        </div>
                        <div>
                            <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.2em] text-horizon">
                                ★ Kata Temari tentang kamu
                            </div>
                            <VoiceCard onSky attribution="Temari" pose="proud">
                                Halo lagi. Aku catet semua perjalanan kamu di sini — kartu, rekor, aksesori, ceritanya.
                            </VoiceCard>
                            <div className="mt-5 flex flex-wrap gap-2">
                                <Chip tone="onSky">
                                    {identity.strava_connected ? 'Strava aktif' : 'Strava off'}
                                </Chip>
                                {memberSince && <Chip tone="onSky">Gabung sejak {memberSince}</Chip>}
                            </div>
                        </div>
                    </div>
                </HeroPanel>

                <section className="mt-8 grid gap-3.5 sm:grid-cols-3">
                    <StatCard
                        accent="leaf"
                        label="Total km"
                        value={stats.total_km.toFixed(1)}
                        unit="km"
                        hint="sejauh ini"
                    />
                    <StatCard
                        accent="horizon"
                        label="Total lari"
                        value={stats.total_runs.toString()}
                        unit="lari"
                        hint="bareng Temari"
                    />
                    <StatCard
                        accent="nyala"
                        label="Lari terjauh"
                        value={stats.longest_run_km.toFixed(2)}
                        unit="km"
                        hint={firstRunShort ? `sejak ${firstRunShort}` : undefined}
                    />
                </section>

                <section className="mt-10">
                    <SectionLabel>Persona · 12 minggu terakhir</SectionLabel>
                    <Card className="flex flex-col gap-5">
                        <PersonaBar mix={personaMix} />
                        {personaSummary && (
                            <AnalysisStatus
                                analysis={personaSummary}
                                inertiaReloadProps={['personaSummary']}
                                renderContent={(text) => (
                                    <p className="font-display text-quote-md italic text-ink-2">
                                        “{text}”
                                    </p>
                                )}
                            />
                        )}
                    </Card>
                </section>

                {topPrs.length > 0 && (
                    <section className="mt-10">
                        <SectionLabel>Rekor terbaru</SectionLabel>
                        <div className="grid gap-3.5 sm:grid-cols-3">
                            {topPrs.map((pr) => (
                                <RekorMini key={pr.id} pr={pr} />
                            ))}
                        </div>
                        <div className="mt-4 text-right">
                            <Link
                                href="/rekor"
                                className="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-horizon hover:text-horizon-deep"
                            >
                                Semua rekor →
                            </Link>
                        </div>
                    </section>
                )}

                <section className="mt-10">
                    <SectionLabel>Aksesori</SectionLabel>
                    <AksesoriStrip
                        unlocks={unlocks}
                        catalog={unlockCatalog}
                    />
                </section>

                <Card as="section" className="mt-10 flex items-start gap-3.5">
                    <TemariProto pose="observational" size={48} />
                    <div className="flex-1">
                        <p className="font-display text-base italic leading-relaxed text-ink-2">
                            “Strava {identity.strava_connected ? 'tersambung' : 'belum nyambung'}. {identity.strava_connected ? 'Lari baru otomatis kepoin.' : 'Sambungkan supaya lari baru kebaca.'}”
                        </p>
                    </div>
                    {!identity.strava_connected && (
                        <Link
                            href="/auth/strava/redirect"
                            className="inline-flex items-center gap-2 rounded-full bg-strava-orange px-4 py-2 text-xs font-semibold text-white hover:bg-strava-orange-hover"
                        >
                            <Icon icon="mdi:strava" width={14} height={14} aria-hidden />
                            Sambungkan
                        </Link>
                    )}
                </Card>

                <div className="mt-8 flex justify-center lg:hidden">
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="inline-flex items-center gap-2 rounded-full border border-cream-deep bg-cream px-5 py-2.5 font-sans text-sm text-ink-2 transition hover:text-ink"
                    >
                        <Icon icon="mdi:logout" width={16} height={16} aria-hidden className="text-ink-3" />
                        Keluar
                    </button>
                </div>
            </motion.div>
        </AppShell>
    );
}

type StatAccent = 'leaf' | 'horizon' | 'nyala';

const STAT_ACCENT: Record<StatAccent, { border: string; value: string }> = {
    leaf: { border: 'before:bg-leaf', value: 'text-leaf-deep' },
    horizon: { border: 'before:bg-horizon', value: 'text-horizon-deep' },
    nyala: { border: 'before:bg-mood-nyala', value: 'text-mood-nyala' },
};

function StatCard({
    accent,
    label,
    value,
    unit,
    hint,
}: Readonly<{ accent: StatAccent; label: string; value: string; unit?: string; hint?: string }>) {
    const tone = STAT_ACCENT[accent];
    return (
        <Card
            padding="lg"
            className={cn(
                'relative overflow-hidden',
                'before:absolute before:inset-x-0 before:top-0 before:h-1',
                tone.border,
            )}
        >
            <div className="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-3">
                {label}
            </div>
            <div className="mt-2 flex items-baseline gap-1.5">
                <span
                    className={cn(
                        'font-sans text-display-sm font-black leading-none tabular-nums',
                        tone.value,
                    )}
                >
                    {value}
                </span>
                {unit && (
                    <span className="font-mono text-[11px] uppercase tracking-[0.14em] text-ink-3">
                        {unit}
                    </span>
                )}
            </div>
            {hint && <div className="mt-2 font-display text-sm italic text-ink-3">{hint}</div>}
        </Card>
    );
}

function RekorMini({ pr }: Readonly<{ pr: TopPrEntry }>) {
    return (
        <PrCard
            category={PR_CATEGORY_LABELS[pr.category] ?? pr.category}
            time={formatPrValue(pr.category, pr.value_sec)}
            setAt={formatIdDate(pr.set_at, 'short')}
            activityId={pr.activity_id}
        />
    );
}

function AksesoriStrip({
    unlocks,
    catalog,
}: Readonly<{
    unlocks: UnlockEntry[];
    catalog: Record<string, UnlockCatalogEntry>;
}>) {
    const { entries, unlockedKeys, unlockedCount } = useMemo(() => {
        const keys = new Set(unlocks.map((u) => u.unlock_key));
        const list = Object.entries(catalog);
        return {
            entries: list,
            unlockedKeys: keys,
            unlockedCount: list.filter(([key]) => keys.has(key)).length,
        };
    }, [unlocks, catalog]);

    if (entries.length === 0) return null;

    return (
        <Card padding="lg">
            <div className="mb-4 flex items-center justify-between">
                <Chip tone="horizon">{unlockedCount} / {entries.length} kebuka</Chip>
                <Link
                    href="/aksesori"
                    className="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-horizon hover:text-horizon-deep"
                >
                    Dandanin →
                </Link>
            </div>
            <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                {entries.map(([key, meta]) => {
                    const unlocked = unlockedKeys.has(key);
                    return (
                        <article
                            key={key}
                            className={
                                unlocked
                                    ? 'flex flex-col gap-2 rounded-2xl bg-horizon/[0.08] px-4 py-4 text-ink'
                                    : 'flex flex-col gap-2 rounded-2xl border border-dashed border-cream-deep bg-cream/40 px-4 py-4 text-ink-3'
                            }
                        >
                            <span
                                className={
                                    unlocked
                                        ? 'flex h-9 w-9 items-center justify-center rounded-xl bg-horizon text-cream'
                                        : 'flex h-9 w-9 items-center justify-center rounded-xl bg-ink-3/20 text-ink-3'
                                }
                            >
                                <Icon
                                    icon={unlocked ? meta.icon : 'mdi:lock-outline'}
                                    width={18}
                                    height={18}
                                    aria-hidden
                                />
                            </span>
                            <h4 className="font-display text-base leading-tight tracking-[-0.005em] text-ink">
                                {meta.name}
                            </h4>
                            <p className="font-sans text-[11px] leading-snug text-ink-3">
                                {unlocked ? meta.description : meta.criteria}
                            </p>
                        </article>
                    );
                })}
            </div>
        </Card>
    );
}
