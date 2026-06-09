import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AppShell from '@/layouts/AppShell';
import MotionLink from '@/components/MotionLink';
import ConfettiBurst from '@/components/ConfettiBurst';
import Card from '@/components/ui/Card';
import CollectionHeader from '@/components/koleksi/CollectionHeader';
import Kartu from '@/components/card/Kartu';
import FeaturedCardHero from '@/components/card/FeaturedCardHero';
import { cn } from '@/lib/cn';
import { pressShrink } from '@/lib/motion';
import { kartuUrl } from '@/lib/routes';
import PageContainer from '@/components/ui/PageContainer';
import { formatDuration, formatNaiveIdDate, formatKm } from '@/lib/pace';
import { RARITY_LABELS, RARITY_ORDER, buildCardStats, paceShapeFromDetail, zonePctFromDetail } from '@/lib/runcard';
import { renderBold } from '@/lib/richText';
import { memo, useCallback, useDeferredValue, useMemo, useState, type ReactNode } from 'react';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    CardEdition,
    Mood,
    PaginatedResponse,
    Rarity,
    RunCard as RunCardModel,
} from '@/types/inertia';

interface FeaturedCardPayload {
    id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    mood: Mood;
    badges: string[] | null;
    detail: ActivityDetail | null;
    edition?: CardEdition | null;
    flavor_analysis?: AnalysisPayload;
}

type CardWithRel = RunCardModel & {
    mood: Mood;
    activity: Activity & { detail: ActivityDetail };
};

interface KartuProps {
    cards: PaginatedResponse<CardWithRel>;
    selectedRarity: string | null;
    featuredCard: FeaturedCardPayload | null;
    rarityCounts: Record<Rarity, number>;
}

type SortMode = 'date' | 'rarity' | 'name';

const SORT_OPTIONS: ReadonlyArray<{ value: SortMode; label: string }> = [
    { value: 'date', label: 'Terbaru' },
    { value: 'rarity', label: 'Tingkat' },
    { value: 'name', label: 'Nama' },
];

const RARITY_RANK: Record<Rarity, number> = {
    legendary: 5,
    epic: 4,
    rare: 3,
    uncommon: 2,
    common: 1,
};

export default function KoleksiKartu({
    cards,
    selectedRarity,
    featuredCard,
    rarityCounts,
}: Readonly<KartuProps>) {
    const [burstKey, setBurstKey] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [sortBy, setSortBy] = useState<SortMode>('date');
    // Defer the heavy grid filter/sort + per-card derived-stat passes off the
    // keystroke so typing in the search box stays responsive on large collections.
    const deferredSearch = useDeferredValue(search);

    const totalKartu = Object.values(rarityCounts).reduce((sum, n) => sum + n, 0);
    const epicCount = rarityCounts.epic + rarityCounts.legendary;
    const eyebrow = `Koleksi · ${totalKartu} kartu · ${epicCount} terbaik`;

    // One flat, newest-first grid (the controller orders by id desc). Filter
    // tabs narrow to a single rarity; otherwise it's the whole collection.
    const rawGrid = cards.data;

    const grid = useMemo(() => {
        let filtered = rawGrid;
        if (deferredSearch.trim() !== '') {
            const q = deferredSearch.toLowerCase();
            filtered = filtered.filter((card) =>
                card.special_move.toLowerCase().includes(q)
                || (card.activity?.detail?.name ?? '').toLowerCase().includes(q),
            );
        }
        const sorted = [...filtered];
        if (sortBy === 'rarity') {
            sorted.sort((a, b) => RARITY_RANK[b.rarity] - RARITY_RANK[a.rarity]);
        } else if (sortBy === 'name') {
            sorted.sort((a, b) => a.special_move.localeCompare(b.special_move));
        }
        // 'date' = server order (id desc), no re-sort needed
        return sorted;
    }, [rawGrid, deferredSearch, sortBy]);

    const triggerBurstFor = useCallback((rarity: Rarity, id: number) => {
        if (rarity === 'epic' || rarity === 'legendary') {
            setBurstKey(`card-${id}-${Date.now()}`);
        }
    }, []);

    const gridBody: ReactNode =
        grid.length === 0 ? (
            <div className="mt-6">
                <EmptyState />
            </div>
        ) : (
            <div className="mt-6 grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {grid.map((card) => (
                    <CardCell key={card.id} card={card} onTap={triggerBurstFor} />
                ))}
            </div>
        );

    return (
        <AppShell>
            <Head title="Koleksi · Kartu" />
            <ConfettiBurst burstKey={burstKey} />
            <PageContainer>
                <CollectionHeader
                    active="kartu"
                    eyebrow={eyebrow}
                    headline1="Semua kartu kamu"
                    headline2="dari Temari."
                    activeCount={String(totalKartu)}
                />

                {featuredCard && <SlimBanner featured={featuredCard} />}

                <RarityFilter
                    selected={selectedRarity}
                    counts={rarityCounts}
                    search={search}
                    onSearchChange={setSearch}
                    sortBy={sortBy}
                    onSortChange={setSortBy}
                />

                {gridBody}

                {rarityCounts.legendary === 0 && <LegendaryTease />}
            </PageContainer>
        </AppShell>
    );
}

/** Collection highlight hero — same layout as the homepage featured panel. */
function SlimBanner({ featured }: Readonly<{ featured: FeaturedCardPayload }>) {
    const detail = featured.detail;
    const kartuProps = useMemo(() => ({
        name: featured.special_move,
        subtitle: detail ? `${detail.name ?? 'Lari'} · ${formatNaiveIdDate(detail.start_date_local, 'short')}` : undefined,
        km: formatKm(detail?.distance),
        durasi: detail?.moving_time != null ? formatDuration(detail.moving_time) : '—',
        trimp: detail?.trimp_edwards != null ? String(Math.round(detail.trimp_edwards)) : '—',
        rarity: featured.rarity,
        mood: featured.mood,
        badges: featured.badges ?? [],
        stats: buildCardStats(detail),
        zonePct: zonePctFromDetail(detail),
        polyline: detail?.summary_polyline,
        paceShape: paceShapeFromDetail(detail),
        edition: featured.edition,
        size: 'md' as const,
    }), [featured, detail]);

    return (
        <FeaturedCardHero
            eyebrow={`★ Highlight minggu ini · ${RARITY_LABELS[featured.rarity]}`}
            name={featured.special_move}
            rarity={featured.rarity}
            km={kartuProps.km}
            stats={kartuProps.stats}
            durasi={kartuProps.durasi}
            badges={kartuProps.badges}
            ctaHref={kartuUrl(featured)}
            voice={
                featured.flavor_analysis && (
                    <AnalysisStatus
                        analysis={featured.flavor_analysis}
                        inertiaReloadProps={['featuredCard']}
                        allowReanalyze={false}
                        showTimestamp={false}
                        onSky
                        renderContent={(text) => (
                            <p className="font-display italic text-cream/85">
                                &ldquo;{renderBold(text)}&rdquo;
                            </p>
                        )}
                    />
                )
            }
            card={<Kartu {...kartuProps} className="w-full" />}
        />
    );
}

function RarityFilter({
    selected,
    counts,
    search,
    onSearchChange,
    sortBy,
    onSortChange,
}: Readonly<{
    selected: string | null;
    counts: Record<Rarity, number>;
    search: string;
    onSearchChange: (v: string) => void;
    sortBy: SortMode;
    onSortChange: (v: SortMode) => void;
}>) {
    return (
        <nav aria-label="Filter kartu" className="mt-8 flex flex-wrap items-center gap-2">
            <span className="mr-1.5 font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">
                Tingkat
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

            {/* Search + Sort */}
            <div className="ml-auto flex items-center gap-2">
                <div className="relative">
                    <Icon icon="mdi:magnify" width={14} height={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-3" aria-hidden />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Cari kartu..."
                        aria-label="Cari kartu"
                        className="w-36 rounded-full border border-cream-deep bg-cream py-1.5 pl-8 pr-3 text-xs text-ink placeholder:text-ink-3 focus:border-horizon focus:outline-none sm:w-44"
                    />
                </div>
                <select
                    value={sortBy}
                    onChange={(e) => onSortChange(e.target.value as SortMode)}
                    aria-label="Urutkan"
                    className="rounded-full border border-cream-deep bg-cream px-3 py-1.5 text-xs font-medium text-ink-2 focus:border-horizon focus:outline-none"
                >
                    {SORT_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                    ))}
                </select>
            </div>
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
            aria-current={active ? 'page' : undefined}
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

const CardCell = memo(function CardCell({
    card,
    onTap,
}: Readonly<{ card: CardWithRel; onTap: (rarity: Rarity, id: number) => void }>) {
    const detail = card.activity?.detail;
    // The derived-stat helpers each run multiple per_km passes; memoize them per
    // card so a parent re-render (e.g. a search keystroke) doesn't recompute every
    // tile. `memo` already skips re-render when props are unchanged, but this keeps
    // the work cheap on the renders that do happen (sort changes, etc.).
    const derived = useMemo(() => {
        if (detail == null) {
            return null;
        }
        return {
            km: formatKm(detail.distance),
            durasi: detail.moving_time != null ? formatDuration(detail.moving_time) : '—',
            trimp: detail.trimp_edwards != null ? String(Math.round(detail.trimp_edwards)) : '—',
            subtitle: `${detail.name ?? 'Lari'} · ${formatNaiveIdDate(detail.start_date_local, 'short')}`,
            stats: buildCardStats(detail),
            zonePct: zonePctFromDetail(detail),
            paceShape: paceShapeFromDetail(detail),
        };
    }, [detail]);

    if (detail == null || derived == null) {
        return null;
    }

    return (
        <MotionLink
            href={kartuUrl(card)}
            whileTap={pressShrink}
            onClick={() => onTap(card.rarity, card.id)}
            className="mx-auto block w-full max-w-[300px] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-horizon focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <Kartu
                name={card.special_move}
                subtitle={derived.subtitle}
                km={derived.km}
                durasi={derived.durasi}
                trimp={derived.trimp}
                rarity={card.rarity}
                mood={card.mood}
                badges={card.badges ?? []}
                stats={derived.stats}
                zonePct={derived.zonePct}
                polyline={detail.summary_polyline}
                paceShape={derived.paceShape}
                edition={card.edition}
                size="md"
            />
        </MotionLink>
    );
});

function EmptyState() {
    return (
        <Card tone="empty" padding="lg" className="mt-8 text-center">
            <p className="font-display text-2xl italic text-ink-2">
                Belum ada kartu di sini.
            </p>
            <p className="mt-2 font-sans text-sm text-ink-2">Coba filter lain, atau sync lari terbaru dulu.</p>
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
                <div className="mb-1.5 font-mono text-[11px] font-bold uppercase tracking-[0.16em] text-rarity-legendary">
                    ★ Legendaris · belum kebuka
                </div>
                <p className="font-display text-sm italic leading-relaxed text-ink-2">
                    Buat buka: PR di 21K, atau 5 lari Nyala beruntun.
                </p>
            </div>
        </Card>
    );
}

