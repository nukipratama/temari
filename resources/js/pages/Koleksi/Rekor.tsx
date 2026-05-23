import type { ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import Chip from '@/components/daybreak/Chip';
import CollectionHeader from '@/components/daybreak/CollectionHeader';
import HeroPanel from '@/components/daybreak/HeroPanel';
import TemariProto from '@/components/daybreak/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate } from '@/lib/pace';
import { PR_CATEGORY_LABELS, formatPrValue } from '@/lib/pr';
import type { AnalysisPayload, PersonalRecord } from '@/types/inertia';

interface ExtendedPR extends Omit<PersonalRecord, 'activity'> {
    value_sec: number;
    set_at: string;
    activity?: { detail?: { name?: string | null } | null };
    context_analysis?: AnalysisPayload;
}

interface RekorProps {
    personalRecords: ExtendedPR[];
}

const DISTANCE_CATEGORIES = ['1km', '5km', '10km', '15km', 'half_marathon', 'marathon'] as const;

const DISTANCE_ORDER: Record<(typeof DISTANCE_CATEGORIES)[number], number> = {
    '1km': 1,
    '5km': 2,
    '10km': 3,
    '15km': 4,
    half_marathon: 5,
    marathon: 6,
};

export default function KoleksiRekor({ personalRecords }: Readonly<RekorProps>) {
    const distancePRs = personalRecords
        .filter((p) => DISTANCE_CATEGORIES.includes(p.category as (typeof DISTANCE_CATEGORIES)[number]))
        .sort((a, b) => DISTANCE_ORDER[b.category as (typeof DISTANCE_CATEGORIES)[number]] - DISTANCE_ORDER[a.category as (typeof DISTANCE_CATEGORIES)[number]]);
    const pacePRs = personalRecords.filter(
        (p) => !DISTANCE_CATEGORIES.includes(p.category as (typeof DISTANCE_CATEGORIES)[number]),
    );
    const featured = distancePRs[0] ?? personalRecords[0] ?? null;

    const totalKartu = 63;
    const eyebrow = `Koleksi · ${personalRecords.length} rekor · ${distancePRs.length} distance · ${pacePRs.length} pace`;

    return (
        <AppShell>
            <Head title="Koleksi · Rekor" />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="mx-auto w-full max-w-7xl px-5 py-6 sm:px-8 lg:px-14 lg:py-10"
            >
                <CollectionHeader
                    active="rekor"
                    eyebrow={eyebrow}
                    headline1="Sejauh ini"
                    headline2="yang terbaik dari kamu."
                    counts={{ kartu: totalKartu, rekor: personalRecords.length, aksesori: '4 / 5' }}
                />

                {featured ? (
                    <HeroScoreboard pr={featured} />
                ) : (
                    <EmptyState />
                )}

                {distancePRs.length > 0 && <TrophyWall records={distancePRs} />}

                {pacePRs.length > 0 && <PaceTicker records={pacePRs} />}

                <TemariFooter />
            </motion.div>
        </AppShell>
    );
}

function HeroScoreboard({ pr }: Readonly<{ pr: ExtendedPR }>) {
    const category = PR_CATEGORY_LABELS[pr.category] ?? pr.category;
    const time = formatPrValue(pr.category, pr.value_sec);
    const runName = pr.activity?.detail?.name ?? 'Lari';
    return (
        <HeroPanel className="mt-8 min-h-[400px] lg:px-14 lg:py-12">
            <span
                aria-hidden
                className="pointer-events-none absolute left-1/2 top-1/2 h-[520px] w-[520px] -translate-x-1/2 -translate-y-1/2 rounded-full opacity-60"
                style={{ background: 'radial-gradient(circle, rgba(232,160,118,0.45) 0%, transparent 60%)' }}
            />
            <div className="relative grid items-center gap-12 lg:grid-cols-[1.4fr_1fr]">
                <div>
                    <div className="mb-5 flex flex-wrap items-center gap-2">
                        <Chip tone="onSky">{category}</Chip>
                    </div>
                    <div
                        className="mb-4 font-sans font-bold leading-[0.85] tracking-[-0.05em] tabular-nums"
                        style={{
                            fontSize: 'clamp(80px, 16vw, 200px)',
                            background: 'linear-gradient(180deg, var(--color-cream), oklch(85% 0.10 50))',
                            WebkitBackgroundClip: 'text',
                            WebkitTextFillColor: 'transparent',
                            backgroundClip: 'text',
                            color: 'transparent',
                        }}
                    >
                        {time}
                    </div>
                    <div className="grid max-w-2xl gap-5 sm:grid-cols-3">
                        <Caption label="Tipe" value={runName} />
                        <Caption label="Hari" value={formatIdDate(pr.set_at, 'long')} />
                        <Caption
                            label="Aktivitas"
                            value={pr.activity_id ? (
                                <Link
                                    href={`/aktivitas/${pr.activity_id}`}
                                    className="text-cream underline-offset-2 hover:underline"
                                >
                                    Lihat detail
                                </Link>
                            ) : '—'}
                        />
                    </div>
                </div>
                <div className="flex flex-col items-center gap-4">
                    <TemariProto pose="glow" size={180} equipped={{ medal: 'emas', headband: 'epik' }} />
                    {pr.context_analysis && (
                        <div className="max-w-sm rounded-2xl border border-cream/[0.12] bg-cream/[0.06] px-5 py-4 backdrop-blur">
                            <AnalysisStatus
                                analysis={pr.context_analysis}
                                inertiaReloadProps={['personalRecords']}
                                allowReanalyze={false}
                                showTimestamp={false}
                                renderContent={(text) => (
                                    <p className="font-display text-[15px] italic leading-snug text-cream">
                                        “{text}”
                                    </p>
                                )}
                            />
                        </div>
                    )}
                </div>
            </div>
        </HeroPanel>
    );
}

function Caption({ label, value }: Readonly<{ label: string; value: ReactNode }>) {
    return (
        <div>
            <div className="mb-1.5 font-mono text-[9px] uppercase tracking-[0.14em] text-cream/55">
                {label}
            </div>
            <div className="font-sans text-[13px] font-medium leading-tight text-cream">{value}</div>
        </div>
    );
}

function TrophyWall({ records }: Readonly<{ records: ExtendedPR[] }>) {
    return (
        <section className="mt-10">
            <header className="mb-4 flex items-baseline justify-between">
                <div className="flex items-baseline gap-3">
                    <h2 className="font-display text-2xl tracking-[-0.01em] text-ink sm:text-[32px]">
                        Trophy wall · <em className="italic text-horizon-deep">jarak</em>
                    </h2>
                    <Chip tone="horizon">{records.length} PR</Chip>
                </div>
            </header>
            <div className="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
                {records.map((r) => (
                    <Medallion key={r.id} pr={r} />
                ))}
            </div>
        </section>
    );
}

function Medallion({ pr }: Readonly<{ pr: ExtendedPR }>) {
    const category = PR_CATEGORY_LABELS[pr.category] ?? pr.category;
    const time = formatPrValue(pr.category, pr.value_sec);
    const runName = pr.activity?.detail?.name ?? 'Lari';
    const card = (
        <div className="flex h-full flex-col gap-3 rounded-2xl border border-cream-deep bg-cream px-6 py-5">
            <div className="font-mono text-[10px] font-bold uppercase tracking-[0.16em] text-horizon-deep">
                {category}
            </div>
            <div className="font-sans text-[32px] font-bold leading-none tabular-nums tracking-[-0.02em] text-ink">
                {time}
            </div>
            <div className="font-sans text-xs text-ink-2">{runName}</div>
            <div className="font-mono text-[10px] uppercase tracking-[0.12em] text-ink-3">
                {formatIdDate(pr.set_at, 'short')}
            </div>
        </div>
    );
    if (pr.activity_id) {
        return (
            <Link
                href={`/aktivitas/${pr.activity_id}`}
                className="block h-full transition hover:-translate-y-0.5 hover:shadow-md"
            >
                {card}
            </Link>
        );
    }
    return card;
}

function PaceTicker({ records }: Readonly<{ records: ExtendedPR[] }>) {
    return (
        <section className="mt-10">
            <header className="mb-4 flex items-baseline justify-between">
                <div className="flex items-baseline gap-3">
                    <h2 className="font-display text-2xl tracking-[-0.01em] text-ink sm:text-[32px]">
                        Pace ticker · <em className="italic text-rarity-rare">best efforts</em>
                    </h2>
                    <Chip>{records.length} PR</Chip>
                </div>
            </header>
            <div className="relative overflow-hidden rounded-2xl bg-ink p-1.5 text-cream">
                <span
                    aria-hidden
                    className="pointer-events-none absolute inset-0"
                    style={{
                        background:
                            'repeating-linear-gradient(0deg, transparent, transparent 3px, rgba(246,241,232,0.02) 3px, rgba(246,241,232,0.02) 4px)',
                    }}
                />
                <div className="relative grid gap-1 sm:grid-cols-2 lg:grid-cols-4">
                    {records.map((r) => (
                        <PaceCell key={r.id} pr={r} />
                    ))}
                </div>
            </div>
        </section>
    );
}

function PaceCell({ pr }: Readonly<{ pr: ExtendedPR }>) {
    const category = PR_CATEGORY_LABELS[pr.category] ?? pr.category;
    const time = formatPrValue(pr.category, pr.value_sec);
    const runName = pr.activity?.detail?.name ?? 'Lari';
    return (
        <div className="flex flex-col gap-2 rounded-xl bg-sky/40 px-5 py-5">
            <div className="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold uppercase tracking-[0.16em] text-rarity-rare">
                <span aria-hidden className="h-1.5 w-1.5 rounded-full bg-rarity-rare" style={{ boxShadow: '0 0 8px var(--color-rarity-rare)' }} />
                {category}
            </div>
            <div className="font-sans text-[40px] font-bold leading-none tabular-nums tracking-[-0.03em] text-cream sm:text-5xl">
                {time}
            </div>
            <div className="border-t border-cream/10 pt-2.5">
                <div className="font-sans text-xs text-cream/85">{runName}</div>
                <div className="font-mono text-[10px] uppercase tracking-[0.12em] text-cream/50">
                    {formatIdDate(pr.set_at, 'short')}
                </div>
            </div>
        </div>
    );
}

function TemariFooter() {
    return (
        <section className="mt-10 flex items-start gap-3.5 rounded-2xl border border-cream-deep bg-cream px-6 py-5">
            <TemariProto pose="observational" size={56} />
            <p className="flex-1 font-display text-[15px] italic leading-relaxed text-ink-2">
                “Tiap kamu pecahin rekor, langsung aku catet di sini. Nggak ada yang ilang, ya.”
            </p>
        </section>
    );
}

function EmptyState() {
    return (
        <div className="mt-8 rounded-2xl border-2 border-dashed border-cream-deep bg-cream/40 px-8 py-12 text-center">
            <p className="font-display text-3xl italic text-ink-2">Belum ada PR.</p>
            <p className="mt-2 font-sans text-sm text-ink-3">
                Sinkronkan lari Strava kamu — Temari otomatis nyatet rekor yang kepecahin.
            </p>
        </div>
    );
}
