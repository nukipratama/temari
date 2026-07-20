import { type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { aktivitasUrl } from '@/lib/routes';
import { appLayout } from '@/layouts/appLayout';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import CollectionHeader from '@/components/koleksi/CollectionHeader';
import HeroPanel from '@/components/ui/HeroPanel';
import MilestoneStrip from '@/components/koleksi/MilestoneStrip';
import PrCard from '@/components/card/PrCard';
import SectionLabel from '@/components/ui/SectionLabel';
import SplitsSparkline from '@/components/run/SplitsSparkline';
import Temari from '@/components/temari/Temari';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import PageContainer from '@/components/ui/PageContainer';
import { formatNaiveIdDate } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { PR_CATEGORY_LABELS, formatPrValue } from '@/lib/pr';
import GradientText from '@/components/ui/GradientText';
import type { AnalysisPayload, PersonalRecord } from '@/types/inertia';

interface ExtendedPR extends Omit<PersonalRecord, 'activity'> {
    value_sec: number;
    set_at: string;
    activity?: { detail?: { name?: string | null } | null };
    context_analysis?: AnalysisPayload;
}

interface FeaturedExtras {
    pr_id: number;
    splits_pace_sec: number[];
    splits_partial_pace_sec: number | null;
    location_name: string | null;
    weather_temp_c: number | null;
    weather_humidity_pct: number | null;
    target_sec: number | null;
    delta_sec: number | null;
}

interface RekorProps {
    personalRecords: ExtendedPR[];
    featuredExtras?: FeaturedExtras | null;
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

export default function KoleksiRekor({
    personalRecords,
    featuredExtras = null,
}: Readonly<RekorProps>) {
    const distancePRs = personalRecords
        .filter((p) => DISTANCE_CATEGORIES.includes(p.category as (typeof DISTANCE_CATEGORIES)[number]))
        .sort((a, b) => DISTANCE_ORDER[b.category as (typeof DISTANCE_CATEGORIES)[number]] - DISTANCE_ORDER[a.category as (typeof DISTANCE_CATEGORIES)[number]]);
    const pacePRs = personalRecords.filter(
        (p) => !DISTANCE_CATEGORIES.includes(p.category as (typeof DISTANCE_CATEGORIES)[number]),
    );
    const featured = distancePRs[0] ?? personalRecords[0] ?? null;

    const eyebrow = `Koleksi · ${personalRecords.length} rekor · ${distancePRs.length} jarak · ${pacePRs.length} pace`;

    return (
        <>
            <Head title="Koleksi · Rekor" />
            <PageContainer>
                <CollectionHeader
                    active="rekor"
                    eyebrow={eyebrow}
                    headline1="Sejauh ini"
                    headline2="yang terbaik dari kamu."
                    activeCount={String(personalRecords.length)}
                />

                {featured ? (
                    <HeroScoreboard pr={featured} extras={featuredExtras} />
                ) : (
                    <EmptyState />
                )}

                {distancePRs.length > 0 && <TrophyWall records={distancePRs} />}

                {pacePRs.length > 0 && <PaceTicker records={pacePRs} />}

            </PageContainer>
        </>
    );
}

function HeroScoreboard({
    pr,
    extras,
}: Readonly<{ pr: ExtendedPR; extras: FeaturedExtras | null }>) {
    const category = PR_CATEGORY_LABELS[pr.category] ?? pr.category;
    const time = formatPrValue(pr.category, pr.value_sec);
    const runName = pr.activity?.detail?.name ?? 'Lari';
    const splits = extras?.splits_pace_sec ?? [];
    const partialPace = extras?.splits_partial_pace_sec ?? null;
    const tempo = extras?.weather_temp_c;
    const humidity = extras?.weather_humidity_pct;
    const location = extras?.location_name;
    const targetSec = extras?.target_sec ?? null;
    const deltaSec = extras?.delta_sec ?? null;

    return (
        <HeroPanel className="mt-8 lg:px-14 lg:py-12">
            {/* Two-row layout:
                Row 1 — oversized time + Temari quote, balanced side-by-side.
                Row 2 — captions (Tipe / Hari / Tempat / Cuaca), full width.
                Row 3 — splits, full width.
               The previous 1.4fr_1fr split left the right column with just a
               180px mascot + a max-w-sm card, ringed by a sea of empty sky.  */}
            <div className="relative grid grid-cols-1 items-center gap-8 lg:grid-cols-[1fr_minmax(320px,_360px)] lg:gap-12">
                <div>
                    <div className="mb-5 flex flex-wrap items-center gap-2">
                        <Chip tone="onSky">{category}</Chip>
                    </div>
                    <GradientText
                        preset="cream-sun"
                        fontSize="clamp(64px, 14vw, 200px)"
                        className="block font-sans font-bold leading-[0.85] tracking-[-0.05em] tabular-nums"
                    >
                        {time}
                    </GradientText>
                </div>
                <div className="flex flex-col items-center gap-4 lg:items-stretch">
                    <div className="flex justify-center">
                        <Temari pose="glow" size={160} />
                    </div>
                    {pr.context_analysis && (
                        <div className="rounded-2xl border border-cream/[0.12] bg-cream/[0.06] px-5 py-4 backdrop-blur">
                            <AnalysisStatus
                                analysis={pr.context_analysis}
                                inertiaReloadProps={['personalRecords']}
                                allowReanalyze={false}
                                showTimestamp={false}
                                renderContent={(text) => (
                                    <p className="font-display text-quote-lg italic text-cream">
                                        “{renderBold(stripEdgeQuotes(text))}”
                                    </p>
                                )}
                            />
                        </div>
                    )}
                </div>
            </div>
            <div className="relative mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <Caption label="Tipe" value={runName} />
                <Caption label="Hari" value={formatNaiveIdDate(pr.set_at, 'long')} />
                <Caption
                    label="Tempat"
                    value={
                        location ?? (
                            pr.activity_id ? (
                                <Link
                                    href={aktivitasUrl({ activity_id: pr.activity_id })}
                                    className="focus-ring-on-sky rounded text-cream underline-offset-2 hover:underline"
                                >
                                    Lihat detail lari
                                </Link>
                            ) : '—'
                        )
                    }
                />
                {tempo != null && (
                    <Caption
                        label="Cuaca"
                        value={`${Math.round(tempo)}°C${humidity != null ? ` · ${Math.round(humidity)}% lembab` : ''}`}
                    />
                )}
            </div>
            <SplitsSparkline paceSec={splits} partialPaceSec={partialPace} className="relative mt-5" />
            {targetSec != null && deltaSec != null && deltaSec > 0 && (
                <MilestoneStrip
                    targetSec={targetSec}
                    deltaSec={deltaSec}
                    distanceLabel={category}
                    className="relative mt-6"
                />
            )}
        </HeroPanel>
    );
}

function Caption({ label, value }: Readonly<{ label: string; value: ReactNode }>) {
    return (
        <div>
            <div className="mb-1.5 font-mono text-sm uppercase tracking-[0.14em] text-ink-on-sky">
                {label}
            </div>
            <div className="font-sans text-sm font-medium leading-tight text-cream">{value}</div>
        </div>
    );
}

function TrophyWall({ records }: Readonly<{ records: ExtendedPR[] }>) {
    return (
        <section className="mt-8">
            <header className="mb-4 flex items-baseline justify-between">
                <div className="flex items-baseline gap-3">
                    <h2 className="font-display text-headline-md text-ink">
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
    return (
        <PrCard
            category={PR_CATEGORY_LABELS[pr.category] ?? pr.category}
            time={formatPrValue(pr.category, pr.value_sec)}
            setAt={formatNaiveIdDate(pr.set_at, 'short')}
            activityId={pr.activity_id}
            runName={pr.activity?.detail?.name ?? 'Lari'}
            size="lg"
        />
    );
}

const PACE_FILLER_KEYS = ['pace-filler-a', 'pace-filler-b', 'pace-filler-c'] as const;

function PaceTicker({ records }: Readonly<{ records: ExtendedPR[] }>) {
    return (
        <section className="mt-8">
            <header className="mb-4 flex items-baseline justify-between">
                <div className="flex items-baseline gap-3">
                    <h2 className="font-display text-headline-md text-ink">
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
                    {PACE_FILLER_KEYS.slice(0, (4 - (records.length % 4)) % 4).map((k) => (
                        <div key={k} aria-hidden className="rounded-xl bg-sky/10" />
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
            <SectionLabel
                dot
                dotClass="bg-rarity-rare shadow-[0_0_8px_var(--color-rarity-rare)]"
                className="mb-0 inline-flex text-rarity-rare"
            >
                {category}
            </SectionLabel>
            <div className="font-sans text-[40px] font-bold leading-none tabular-nums tracking-[-0.03em] text-cream sm:text-5xl">
                {time}
            </div>
            <div className="border-t border-cream/10 pt-2.5">
                <div className="font-sans text-xs text-cream/85">{runName}</div>
                <div className="font-mono text-xs uppercase tracking-[0.12em] text-ink-on-sky">
                    {formatNaiveIdDate(pr.set_at, 'short')}
                </div>
            </div>
        </div>
    );
}

function EmptyState() {
    return (
        <Card tone="empty" padding="lg" className="mt-8 text-center">
            <p className="font-display text-3xl italic text-ink-2">Belum ada PR.</p>
            <p className="mt-2 font-sans text-sm text-ink-3">
                Sync Strava kamu, Temari otomatis nyatet rekor yang kepecahin.
            </p>
        </Card>
    );
}

KoleksiRekor.layout = appLayout;
