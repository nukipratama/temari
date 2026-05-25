import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import MotionLink from '@/components/MotionLink';
import ConfettiBurst from '@/components/ConfettiBurst';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import CollectionHeader from '@/components/koleksi/CollectionHeader';
import HeroPanel from '@/components/ui/HeroPanel';
import Kartu from '@/components/card/Kartu';
import TemariProto from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import { fadeInUp, pressShrink } from '@/lib/motion';
import { formatDuration, formatIdDate, formatKm } from '@/lib/pace';
import { RARITY_LABELS, RARITY_ORDER, prettyBadge } from '@/lib/runcard';
import { emberGlowStyle } from '@/lib/styles';
import { useState } from 'react';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    PaginatedResponse,
    Rarity,
    RunCard as RunCardModel,
} from '@/types/inertia';

interface FeaturedCardPayload {
    id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    badges: string[] | null;
    detail: ActivityDetail | null;
    flavor_analysis?: AnalysisPayload;
}

type CardWithRel = RunCardModel & {
    activity: Activity & { detail: ActivityDetail };
};

interface KartuProps {
    cards: PaginatedResponse<CardWithRel>;
    selectedRarity: string | null;
    featuredCard: FeaturedCardPayload | null;
    rarityCounts: Record<Rarity, number>;
}

export default function KoleksiKartu({
    cards,
    selectedRarity,
    featuredCard,
    rarityCounts,
}: Readonly<KartuProps>) {
    const [burstKey, setBurstKey] = useState<string | null>(null);

    const totalKartu = Object.values(rarityCounts).reduce((sum, n) => sum + n, 0);
    const epicCount = rarityCounts.epic + rarityCounts.legendary;
    const eyebrow = `Koleksi · ${totalKartu} kartu · ${epicCount} Epic+`;

    // On the "Semua" view we hide the spotlight card from the grid to avoid
    // it appearing twice (hero + tile). On any rarity-filtered view we keep
    // it in the grid — filtering by Epik and finding the highlighted Epik
    // card missing from the count reads as a bug, not a feature.
    const grid = selectedRarity === null
        ? cards.data.filter((c) => featuredCard === null || c.id !== featuredCard.id)
        : cards.data;

    const triggerBurstFor = (rarity: Rarity, id: number) => {
        if (rarity === 'epic' || rarity === 'legendary') {
            setBurstKey(`card-${id}-${Date.now()}`);
        }
    };

    return (
        <AppShell>
            <Head title="Koleksi · Kartu" />
            <ConfettiBurst burstKey={burstKey} />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-10"
            >
                <CollectionHeader
                    active="kartu"
                    eyebrow={eyebrow}
                    headline1="Yang Temari kasih kamu"
                    headline2="semuanya."
                    activeCount={String(totalKartu)}
                />

                {featuredCard && (
                    <FeaturedPanel featured={featuredCard} onTap={triggerBurstFor} />
                )}

                <RarityFilter selected={selectedRarity} counts={rarityCounts} />

                {grid.length === 0 && featuredCard === null ? (
                    <div className="mt-6">
                        <EmptyState />
                    </div>
                ) : (
                    <div className="mt-6 grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                        {grid.map((card) => (
                            <CardCell key={card.id} card={card} onTap={triggerBurstFor} />
                        ))}
                    </div>
                )}

                {rarityCounts.legendary === 0 && <LegendaryTease />}
            </motion.div>
        </AppShell>
    );
}

function FeaturedPanel({
    featured,
    onTap,
}: Readonly<{ featured: FeaturedCardPayload; onTap: (rarity: Rarity, id: number) => void }>) {
    const detail = featured.detail;
    const km = formatKm(detail?.distance);
    const durasi = detail?.moving_time != null ? formatDuration(detail.moving_time) : '—';
    const trimp = detail?.trimp_edwards != null ? String(Math.round(detail.trimp_edwards)) : '—';
    const subtitle = `${RARITY_LABELS[featured.rarity]} · ${formatIdDate(detail?.start_date_local ?? null, 'short')}`;
    const tags = (featured.badges ?? []).slice(0, 2).map(prettyBadge);

    return (
        <HeroPanel className="mt-8 lg:px-14 lg:py-12">
            <span
                aria-hidden
                className="pointer-events-none absolute -right-10 -top-10 h-60 w-60 rounded-full"
                style={emberGlowStyle()}
            />
            <div className="relative grid items-center gap-9 lg:grid-cols-[160px_1fr_minmax(380px,_46%)]">
                <div className="hidden lg:block">
                    <TemariProto pose="proud" size={160} />
                </div>
                <div>
                    <div className="mb-3.5 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                        ★ Sorotan minggu ini · {RARITY_LABELS[featured.rarity]}
                    </div>
                    <h2 className="mb-4 font-display text-display-md text-cream">
                        <em className="italic text-horizon">{featured.special_move}</em>
                    </h2>
                    {featured.flavor_analysis && (
                        <div className="mb-4 max-w-xl">
                            <AnalysisStatus
                                analysis={featured.flavor_analysis}
                                inertiaReloadProps={['featuredCard']}
                                allowReanalyze={false}
                                showTimestamp={false}
                                renderContent={(text) => (
                                    <p className="font-display text-quote-lg italic text-cream">
                                        “{text}”
                                    </p>
                                )}
                            />
                        </div>
                    )}
                    {tags.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {tags.map((t) => (
                                <Chip key={t} tone="horizon">{t}</Chip>
                            ))}
                        </div>
                    )}
                </div>
                <Link
                    href={`/aktivitas/${featured.activity_id}`}
                    className="hidden lg:block"
                    onClick={() => onTap(featured.rarity, featured.id)}
                >
                    <Kartu
                        name={featured.special_move}
                        subtitle={subtitle}
                        km={km}
                        durasi={durasi}
                        trimp={trimp}
                        rarity={featured.rarity}
                        tags={tags}
                        size="lg"
                        onSky
                        className="rotate-[-3deg]"
                    />
                </Link>
            </div>
        </HeroPanel>
    );
}

function RarityFilter({
    selected,
    counts,
}: Readonly<{ selected: string | null; counts: Record<Rarity, number> }>) {
    return (
        <nav aria-label="Filter rarity" className="mt-8 flex flex-wrap items-center gap-2">
            <span className="mr-1.5 font-mono text-[10px] uppercase tracking-[0.14em] text-ink-3">
                Rarity
            </span>
            <FilterPill href="/kartu" label="Semua" active={selected === null} dot={null} />
            {RARITY_ORDER.map((r) => (
                <FilterPill
                    key={r}
                    href={`/kartu?rarity=${r}`}
                    label={`${RARITY_LABELS[r]} · ${counts[r]}`}
                    active={selected === r}
                    dot={r}
                />
            ))}
        </nav>
    );
}

const RARITY_DOT: Record<Rarity, string> = {
    common: 'bg-rarity-common',
    uncommon: 'bg-rarity-uncommon',
    rare: 'bg-rarity-rare',
    epic: 'bg-rarity-epic',
    legendary: 'bg-rarity-legendary',
};

function FilterPill({
    href,
    label,
    active,
    dot,
}: Readonly<{ href: string; label: string; active: boolean; dot: Rarity | null }>) {
    return (
        <MotionLink
            href={href}
            whileTap={pressShrink}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-medium transition',
                active
                    ? 'bg-sky text-cream font-semibold'
                    : 'bg-sky/[0.06] text-ink-2 hover:bg-sky/[0.12]',
            )}
        >
            {dot && <span aria-hidden className={cn('h-2 w-2 rounded-full', RARITY_DOT[dot])} />}
            {label}
        </MotionLink>
    );
}

function CardCell({
    card,
    onTap,
}: Readonly<{ card: CardWithRel; onTap: (rarity: Rarity, id: number) => void }>) {
    const detail = card.activity?.detail;
    if (!detail) return null;
    const km = formatKm(detail.distance);
    const durasi = detail.moving_time != null ? formatDuration(detail.moving_time) : '—';
    const trimp = detail.trimp_edwards != null ? String(Math.round(detail.trimp_edwards)) : '—';
    const subtitle = `${detail.name ?? 'Lari'} · ${formatIdDate(detail.start_date_local, 'short')}`;
    const tags = (card.badges ?? []).slice(0, 2).map(prettyBadge);

    return (
        <MotionLink
            href={`/aktivitas/${card.activity_id}`}
            whileTap={pressShrink}
            onClick={() => onTap(card.rarity, card.id)}
            className="block transition hover:-translate-y-0.5"
        >
            <Kartu
                name={card.special_move}
                subtitle={subtitle}
                km={km}
                durasi={durasi}
                trimp={trimp}
                rarity={card.rarity}
                tags={tags}
                size="md"
            />
        </MotionLink>
    );
}

function EmptyState() {
    return (
        <Card tone="empty" padding="lg" className="mt-8 text-center">
            <p className="font-display text-2xl italic text-ink-2">
                Belum ada kartu di rarity ini.
            </p>
            <p className="mt-2 font-sans text-sm text-ink-3">Coba filter lain, atau sinkronkan lari terbaru kamu.</p>
        </Card>
    );
}

function LegendaryTease() {
    return (
        <Card tone="empty" as="section" padding="lg" className="mt-8 flex flex-col items-start gap-5 sm:flex-row sm:items-center">
            <div className="flex h-28 w-20 items-center justify-center rounded-lg border-2 border-dashed border-rarity-legendary bg-rarity-legendary/[0.06] font-display text-4xl italic text-rarity-legendary">
                ?
            </div>
            <div className="flex-1">
                <div className="mb-1.5 font-mono text-[10px] font-bold uppercase tracking-[0.16em] text-rarity-legendary">
                    ★ Legendaris · belum terbuka
                </div>
                <p className="mb-1.5 font-display text-2xl leading-tight tracking-[-0.01em] text-ink">
                    “Ada kartu Legendaris nungguin di sini.”
                </p>
                <p className="font-display text-sm italic leading-relaxed text-ink-3">
                    Syaratnya: PR di 21K, atau 5 lari Nyala beruntun.
                </p>
            </div>
        </Card>
    );
}

