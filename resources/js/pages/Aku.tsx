import { Head, Link, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import GradientText from '@/components/ui/GradientText';
import PageHero from '@/components/ui/PageHero';
import PersonaBar, { type PersonaSlice } from '@/components/PersonaBar';
import PrCard from '@/components/card/PrCard';
import SectionLabel from '@/components/ui/SectionLabel';
import TemariProto from '@/components/temari/TemariProto';
import VoiceCard from '@/components/temari/VoiceCard';
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
                            <PageHero
                                onSky
                                eyebrow="★ Identitas kamu"
                                lead={firstName ? 'Aku,' : undefined}
                                emph={firstName ? `${firstName}.` : 'Aku.'}
                                className="mb-4"
                            />
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

function BigStat({ value, unit, label }: Readonly<{ value: string; unit: string; label: string }>) {
    return (
        <Card padding="lg">
            <div className="font-mono text-[10px] uppercase tracking-[0.14em] text-ink-3">{label}</div>
            <div className="mt-2 flex items-baseline gap-2">
                <GradientText
                    preset="horizon"
                    fontSize="clamp(48px, 6vw, 72px)"
                    className="font-sans font-bold leading-none tabular-nums tracking-[-0.03em]"
                >
                    {value}
                </GradientText>
                <span className="font-mono text-[11px] uppercase tracking-[0.14em] text-ink-3">{unit}</span>
            </div>
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
