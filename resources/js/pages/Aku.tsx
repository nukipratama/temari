import { Head, Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import Chip from '@/components/daybreak/Chip';
import HeroPanel from '@/components/daybreak/HeroPanel';
import PersonaBar, { type PersonaSlice } from '@/components/daybreak/PersonaBar';
import SectionLabel from '@/components/daybreak/SectionLabel';
import TemariProto from '@/components/daybreak/TemariProto';
import VoiceCard from '@/components/daybreak/VoiceCard';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate } from '@/lib/pace';
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
    const memberSince = identity.member_since ? formatIdDate(identity.member_since, 'long') : null;

    return (
        <AppShell>
            <Head title="Aku" />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-10"
            >
                <HeroPanel className="lg:px-14 lg:py-12">
                    <div className="grid items-center gap-9 lg:grid-cols-[220px_1fr]">
                        <div className="flex justify-center">
                            <TemariProto pose="proud" size={220} />
                        </div>
                        <div>
                            <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                                ★ Identitas kamu
                            </div>
                            <h1 className="mb-4 font-display text-[44px] leading-[0.95] tracking-[-0.02em] text-cream sm:text-[64px] lg:text-[80px] lg:leading-[0.92]">
                                {firstName ? <>Aku, <em className="italic text-horizon">{firstName}.</em></> : <em className="italic text-horizon">Aku.</em>}
                            </h1>
                            <VoiceCard onSky attribution="Temari" pose="proud">
                                Halo lagi. Aku catet semua perjalanan kamu di sini — kartu, rekor, aksesori, ceritanya.
                            </VoiceCard>
                            {memberSince && (
                                <div className="mt-4 font-mono text-[10px] uppercase tracking-[0.14em] text-cream/55">
                                    Bareng sejak {memberSince}
                                </div>
                            )}
                        </div>
                    </div>
                </HeroPanel>

                <section className="mt-10 grid gap-3.5 sm:grid-cols-3">
                    <BigStat value={stats.total_km.toFixed(1)} unit="km" label="Total jarak" />
                    <BigStat value={stats.total_runs.toString()} unit="lari" label="Total aktivitas" />
                    <BigStat value={stats.longest_run_km.toFixed(2)} unit="km" label="Terjauh" />
                </section>

                <section className="mt-10">
                    <SectionLabel>Persona · 12 minggu terakhir</SectionLabel>
                    <div className="flex flex-col gap-5 rounded-2xl border border-cream-deep bg-cream px-6 py-5">
                        <PersonaBar mix={personaMix} />
                        {personaSummary && (
                            <AnalysisStatus
                                analysis={personaSummary}
                                inertiaReloadProps={['personaSummary']}
                                renderContent={(text) => (
                                    <p className="font-display text-base italic leading-relaxed text-ink-2 sm:text-lg">
                                        “{text}”
                                    </p>
                                )}
                            />
                        )}
                    </div>
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

                <section className="mt-10 flex items-start gap-3.5 rounded-2xl border border-cream-deep bg-cream px-6 py-5">
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
                </section>
            </motion.div>
        </AppShell>
    );
}

function BigStat({ value, unit, label }: Readonly<{ value: string; unit: string; label: string }>) {
    return (
        <div className="rounded-2xl border border-cream-deep bg-cream px-7 py-6">
            <div className="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-3">{label}</div>
            <div className="mt-2 flex items-baseline gap-2">
                <span
                    className="font-sans font-bold leading-none tabular-nums tracking-[-0.03em]"
                    style={{
                        fontSize: 'clamp(48px, 6vw, 72px)',
                        background: 'linear-gradient(180deg, var(--color-horizon-deep), var(--color-citrus))',
                        WebkitBackgroundClip: 'text',
                        WebkitTextFillColor: 'transparent',
                        backgroundClip: 'text',
                        color: 'transparent',
                    }}
                >
                    {value}
                </span>
                <span className="font-mono text-[11px] uppercase tracking-[0.14em] text-ink-3">{unit}</span>
            </div>
        </div>
    );
}

function RekorMini({ pr }: Readonly<{ pr: TopPrEntry }>) {
    const category = PR_CATEGORY_LABELS[pr.category] ?? pr.category;
    const time = formatPrValue(pr.category, pr.value_sec);
    const card = (
        <div className="flex h-full flex-col gap-2 rounded-2xl border border-cream-deep bg-cream px-5 py-4">
            <div className="font-mono text-[10px] font-bold uppercase tracking-[0.16em] text-horizon-deep">
                {category}
            </div>
            <div className="font-sans text-2xl font-bold leading-none tabular-nums tracking-[-0.02em] text-ink">
                {time}
            </div>
            <div className="font-mono text-[10px] uppercase tracking-[0.12em] text-ink-3">
                {formatIdDate(pr.set_at, 'short')}
            </div>
        </div>
    );
    if (pr.activity_id) {
        return (
            <Link href={`/aktivitas/${pr.activity_id}`} className="block h-full transition hover:-translate-y-0.5">
                {card}
            </Link>
        );
    }
    return card;
}

function AksesoriStrip({
    unlocks,
    catalog,
}: Readonly<{
    unlocks: UnlockEntry[];
    catalog: Record<string, UnlockCatalogEntry>;
}>) {
    const unlockedKeys = new Set(unlocks.map((u) => u.unlock_key));
    const entries = Object.entries(catalog);
    if (entries.length === 0) return null;

    const unlockedCount = entries.filter(([key]) => unlockedKeys.has(key)).length;

    return (
        <div className="rounded-2xl border border-cream-deep bg-cream px-6 py-5">
            <div className="mb-4 flex items-center justify-between">
                <Chip tone="horizon">{unlockedCount} / {entries.length} kebuka</Chip>
                <Link
                    href="/aksesori"
                    className="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-horizon hover:text-horizon-deep"
                >
                    Dandanin →
                </Link>
            </div>
            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                {entries.map(([key, meta]) => {
                    const unlocked = unlockedKeys.has(key);
                    return (
                        <div
                            key={key}
                            className={
                                unlocked
                                    ? 'flex items-center gap-2 rounded-xl bg-horizon/[0.08] px-3 py-2.5 text-ink'
                                    : 'flex items-center gap-2 rounded-xl bg-ink/[0.04] px-3 py-2.5 text-ink-3'
                            }
                        >
                            <Icon
                                icon={unlocked ? meta.icon : 'mdi:lock-outline'}
                                width={16}
                                height={16}
                                aria-hidden
                            />
                            <span className="truncate text-xs font-medium">{meta.name}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
