import { Icon } from '@iconify/react';
import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    memo,
    useCallback,
    useDeferredValue,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';

import type {
    Activity,
    ActivityDetail,
    AnalysisPayload,
    CardEdition,
    Mood,
    PaginatedResponse,
    Rarity,
    RunCard as RunCardModel,
    SharedProps,
    StravaSyncState,
} from '@/types/inertia';

import FeaturedCardHero from '@/components/card/FeaturedCardHero';
import Kartu from '@/components/card/Kartu';
import KartuMount from '@/components/card/KartuMount';
import ConfettiBurst from '@/components/ConfettiBurst';
import ExpandableQuote from '@/components/dashboard/ExpandableQuote';
import CollectionHeader from '@/components/koleksi/CollectionHeader';
import MotionLink from '@/components/MotionLink';
import CoachMark from '@/components/onboarding/CoachMark';
import StravaSyncButton from '@/components/StravaSyncButton';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Card from '@/components/ui/Card';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { activityUrl } from '@/lib/routes';
import {
    RARITY_LABELS,
    RARITY_ORDER,
    kartuPropsFromDetail,
} from '@/lib/runcard';

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

interface CardsProps {
    cards: PaginatedResponse<CardWithRel>;
    selectedRarity: string | null;
    featuredCard: FeaturedCardPayload | null;
    rarityCounts: Record<Rarity, number>;
}

type SortMode = 'date' | 'rarity' | 'name';

const SORT_OPTIONS: ReadonlyArray<{ value: SortMode; label: string }> = [
    { value: 'date', label: 'Newest' },
    { value: 'rarity', label: 'Rarity' },
    { value: 'name', label: 'Name' },
];

const RARITY_RANK: Record<Rarity, number> = {
    legendary: 5,
    epic: 4,
    rare: 3,
    uncommon: 2,
    common: 1,
};

export default function Cards({
    cards,
    selectedRarity,
    featuredCard,
    rarityCounts,
}: Readonly<CardsProps>) {
    const [burst, setBurst] = useState<{
        key: string;
        legendary: boolean;
    } | null>(null);
    const [search, setSearch] = useState('');
    const [sortBy, setSortBy] = useState<SortMode>('date');
    const gridRef = useRef<HTMLDivElement>(null);
    // Defer the heavy grid filter/sort + per-card derived-stat passes off the
    // keystroke so typing in the search box stays responsive on large collections.
    const deferredSearch = useDeferredValue(search);

    const totalKartu = Object.values(rarityCounts).reduce(
        (sum, n) => sum + n,
        0,
    );
    const epicCount = rarityCounts.epic + rarityCounts.legendary;
    const eyebrow = `Collection · ${totalKartu} cards · ${epicCount} best`;

    // One flat, newest-first grid (the controller orders by id desc). Filter
    // tabs narrow to a single rarity; otherwise it's the whole collection.
    const rawGrid = cards.data;

    const grid = useMemo(() => {
        let filtered = rawGrid;
        if (deferredSearch.trim() !== '') {
            const q = deferredSearch.toLowerCase();
            filtered = filtered.filter(
                (card) =>
                    card.special_move.toLowerCase().includes(q) ||
                    (card.activity?.detail?.name ?? '')
                        .toLowerCase()
                        .includes(q),
            );
        }
        const sorted = [...filtered];
        if (sortBy === 'rarity') {
            sorted.sort(
                (a, b) => RARITY_RANK[b.rarity] - RARITY_RANK[a.rarity],
            );
        } else if (sortBy === 'name') {
            sorted.sort((a, b) => a.special_move.localeCompare(b.special_move));
        }
        // 'date' = server order (id desc), no re-sort needed
        return sorted;
    }, [rawGrid, deferredSearch, sortBy]);

    const triggerBurstFor = useCallback((rarity: Rarity, id: number) => {
        if (rarity === 'epic' || rarity === 'legendary') {
            setBurst({
                key: `card-${id}-${Date.now()}`,
                legendary: rarity === 'legendary',
            });
        }
    }, []);

    const gridBody: ReactNode =
        grid.length === 0 ? (
            <div className="mt-6">
                <EmptyState />
            </div>
        ) : (
            // The ref/data-coachmark anchor lives on this stable wrapper, not on
            // the keyed motion.div below: CoachMark's anchor tracking sets up its
            // IntersectionObserver once and never re-attaches, so a `key={sortBy}`
            // remount on the ref'd element itself would detach it from the DOM the
            // observer is still watching, dropping the mark for the rest of the visit.
            <div
                ref={gridRef}
                data-coachmark="collection-grid"
                className="mt-6"
            >
                <motion.div
                    key={sortBy}
                    variants={fadeInUp}
                    initial="hidden"
                    animate="visible"
                    className="grid grid-cols-2 gap-3.5 md:grid-cols-3 xl:grid-cols-4"
                >
                    {grid.map((card) => (
                        <CardCell
                            key={card.id}
                            card={card}
                            onTap={triggerBurstFor}
                        />
                    ))}
                </motion.div>
            </div>
        );

    return (
        <>
            <Head title="Collection · Cards" />
            <ConfettiBurst
                burstKey={burst?.key ?? null}
                count={burst?.legendary ? 45 : 30}
                durationMs={burst?.legendary ? 3200 : 2500}
            />
            <PageContainer>
                <CollectionHeader
                    active="kartu"
                    eyebrow={eyebrow}
                    headline1="All your cards"
                    headline2="from Temari."
                    activeCount={String(totalKartu)}
                />

                {featuredCard && (
                    <div data-coachmark="collection-featured">
                        <SlimBanner featured={featuredCard} />
                    </div>
                )}

                <RarityFilter
                    selected={selectedRarity}
                    counts={rarityCounts}
                    search={search}
                    onSearchChange={setSearch}
                    sortBy={sortBy}
                    onSortChange={setSortBy}
                />

                {gridBody}
                <CoachMark
                    id="collection-grid"
                    anchorRef={gridRef}
                    placement="top"
                    title="Tap a card"
                    body="Each one opens the run it came from."
                />

                {rarityCounts.legendary === 0 && <LegendaryTease />}
            </PageContainer>
        </>
    );
}

/** Collection highlight hero — same layout as the homepage featured panel. */
function SlimBanner({ featured }: Readonly<{ featured: FeaturedCardPayload }>) {
    const detail = featured.detail;
    const kartuProps = useMemo(() => {
        return {
            name: featured.special_move,
            ...kartuPropsFromDetail(detail),
            rarity: featured.rarity,
            mood: featured.mood,
            badges: featured.badges ?? [],
            polyline: detail?.summary_polyline,
            edition: featured.edition,
            size: 'md' as const,
        };
    }, [featured, detail]);

    return (
        <FeaturedCardHero
            eyebrow={`★ Your best card · ${RARITY_LABELS[featured.rarity]}`}
            name={featured.special_move}
            rarity={featured.rarity}
            km={kartuProps.km}
            stats={kartuProps.stats}
            durasi={kartuProps.durasi}
            badges={kartuProps.badges}
            polyline={kartuProps.polyline}
            ctaHref={activityUrl(featured)}
            voice={
                featured.flavor_analysis &&
                featured.flavor_analysis.status !== 'pending' && (
                    <AnalysisStatus
                        analysis={featured.flavor_analysis}
                        inertiaReloadProps={['featuredCard']}
                        allowReanalyze={false}
                        showTimestamp={false}
                        onSky
                        renderContent={(text) => (
                            <ExpandableQuote text={text} onSky />
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
        <nav
            aria-label="Filter cards"
            data-coachmark="collection-filter"
            className="mt-8 flex flex-wrap items-center gap-2"
        >
            <Eyebrow as="span" token="micro" tone="ink-2" className="mr-1.5">
                Rarity
            </Eyebrow>
            <FilterPill
                href="/cards"
                label="All"
                active={selected === null}
                dot={null}
            />
            {RARITY_ORDER.map((r) => (
                <FilterPill
                    key={r}
                    href={`/cards?rarity=${r}`}
                    label={`${RARITY_LABELS[r]} · ${counts[r]}`}
                    active={selected === r}
                    dot={r}
                />
            ))}

            {/* Search + Sort */}
            <div className="ml-auto flex items-center gap-2">
                <div className="relative">
                    <Icon
                        icon="mdi:magnify"
                        width={14}
                        height={14}
                        className="absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-3"
                        aria-hidden
                    />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Search cards..."
                        aria-label="Search cards"
                        className="w-36 rounded-full border border-cream-deep bg-cream py-1.5 pl-8 pr-3 text-xs text-ink placeholder:text-ink-3 focus:border-horizon focus:outline-none sm:w-44"
                    />
                </div>
                <select
                    value={sortBy}
                    onChange={(e) => onSortChange(e.target.value as SortMode)}
                    aria-label="Sort"
                    className="rounded-full border border-cream-deep bg-cream px-3 py-1.5 text-xs font-medium text-ink-2 focus:border-horizon focus:outline-none"
                >
                    {SORT_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                            {opt.label}
                        </option>
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
}: Readonly<{
    href: string;
    label: string;
    active: boolean;
    dot: Rarity | null;
}>) {
    return (
        <MotionLink
            href={href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-medium transition',
                active
                    ? 'bg-sky text-cream font-semibold'
                    : 'bg-sky/[0.06] text-ink-2 hover:bg-sky/[0.12]',
            )}
        >
            {dot && (
                <span
                    aria-hidden
                    className={cn('h-2 w-2 rounded-full', RARITY_DOT[dot])}
                />
            )}
            {label}
        </MotionLink>
    );
}

const CardCell = memo(function CardCell({
    card,
    onTap,
}: Readonly<{
    card: CardWithRel;
    onTap: (rarity: Rarity, id: number) => void;
}>) {
    const detail = card.activity?.detail;
    // The derived-stat helpers each run multiple per_km passes; memoize them per
    // card so a parent re-render (e.g. a search keystroke) doesn't recompute every
    // tile. `memo` already skips re-render when props are unchanged, but this keeps
    // the work cheap on the renders that do happen (sort changes, etc.).
    const derived = useMemo(
        () => (detail == null ? null : kartuPropsFromDetail(detail)),
        [detail],
    );

    if (detail == null || derived == null) {
        return null;
    }

    return (
        <MotionLink
            href={activityUrl(card)}
            onClick={() => onTap(card.rarity, card.id)}
            className="mx-auto block w-full max-w-[300px] focus-visible:ring-2 focus-visible:ring-horizon focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <KartuMount className="p-2 sm:p-3">
                <Kartu
                    name={card.special_move}
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
                    hideStats
                    hideName
                    compact
                    className="w-full"
                />
            </KartuMount>
        </MotionLink>
    );
});

function EmptyState() {
    const { stravaSync } = usePage<SharedProps>().props;
    const state: StravaSyncState = stravaSync?.state ?? 'disconnected';

    return (
        <EmptyPanel
            title="No cards here yet."
            body="Try a different filter, or sync your latest runs first."
            action={
                state !== 'syncing' && (
                    <StravaSyncButton state={state} className="mt-4" />
                )
            }
            className="mt-8"
        />
    );
}

function LegendaryTease() {
    return (
        <Card
            tone="empty"
            as="section"
            padding="hero"
            className="mt-8 flex flex-col items-start gap-5 sm:flex-row sm:items-center"
        >
            <div className="flex h-28 w-20 items-center justify-center rounded-lg border-2 border-dashed border-rarity-legendary bg-rarity-legendary/[0.06] font-display text-4xl italic text-rarity-legendary">
                ?
            </div>
            <div className="flex-1">
                <Eyebrow token="micro" className="mb-1.5 text-rarity-legendary">
                    ★ Legendary · not unlocked yet
                </Eyebrow>
                <p className="font-display text-sm italic leading-relaxed text-ink-2">
                    The rarest card. Unlocks when one run stacks up a bunch of
                    great things at once: a PR, a faster back half, a long
                    distance, plus your badges.
                </p>
            </div>
        </Card>
    );
}

Cards.layout = appLayout;
