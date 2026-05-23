import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { cn } from '@/lib/cn';
import AppShell from '@/layouts/AppShell';
import MotionLink from '@/components/MotionLink';
import PageHero from '@/components/PageHero';
import Paginator from '@/components/Paginator';
import { useState } from 'react';
import ConfettiBurst from '@/components/ConfettiBurst';
import RunCard from '@/components/card/RunCard';
import { fadeInUp, pressShrink } from '@/lib/motion';
import { RARITY_LABELS, RARITY_ORDER } from '@/lib/runcard';
import type {
    Activity,
    ActivityDetail,
    PaginatedResponse,
    Rarity,
    RunCard as RunCardModel,
} from '@/types/inertia';

type CardWithRel = RunCardModel & {
    activity: Activity & { detail: ActivityDetail };
};

interface CardsIndexProps {
    cards: PaginatedResponse<CardWithRel>;
    selectedRarity: string | null;
}

function pickFeatured(items: CardWithRel[]): CardWithRel | null {
    let best: CardWithRel | null = null;
    let bestRank = -1;
    let bestDate = '';
    for (const c of items) {
        if (!c.activity?.detail) continue;
        const rank = RARITY_ORDER.indexOf(c.rarity);
        const date = c.activity.detail.start_date_local ?? '';
        if (rank > bestRank || (rank === bestRank && date > bestDate)) {
            best = c;
            bestRank = rank;
            bestDate = date;
        }
    }
    return best;
}

export default function CardsIndex({ cards, selectedRarity }: Readonly<CardsIndexProps>) {
    const [burstKey, setBurstKey] = useState<string | null>(null);

    const totalLabel =
        selectedRarity === null
            ? `${cards.total} kartu total`
            : `${cards.total} kartu ${RARITY_LABELS[selectedRarity as Rarity] ?? selectedRarity}`;

    const featured = selectedRarity === null && cards.current_page === 1 ? pickFeatured(cards.data) : null;
    const gridItems = featured === null ? cards.data : cards.data.filter((c) => c.id !== featured.id);

    const triggerBurstFor = (card: CardWithRel) => {
        if (card.rarity === 'epic' || card.rarity === 'legendary') {
            setBurstKey(`card-${card.id}-${Date.now()}`);
        }
    };

    return (
        <AppShell>
            <Head title="Kartu Aktivitas" />
            <ConfettiBurst burstKey={burstKey} />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-4 py-6 sm:px-6 sm:py-10"
            >
                <PageHero
                    icon="mdi:cards"
                    eyebrow="Koleksi"
                    title="Kartu Aktivitas"
                    subtitle="Setiap lari mendapat satu kartu. Rarity-nya naik kalau kamu memecahkan PR, negative split, atau lari terjauh."
                    tone="pop"
                    trailing={
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-citrus px-3 py-1.5 text-sm font-bold text-white shadow-sm ring-2 ring-white">
                            <Icon icon="mdi:cards" width={14} height={14} aria-hidden />
                            {totalLabel}
                        </span>
                    }
                />

                <nav aria-label="Filter rarity" className="mt-6 flex flex-wrap items-center gap-2">
                    <span className="mr-1 text-xs font-semibold uppercase tracking-wider text-ink-3">
                        Rarity
                    </span>
                    <RarityPill href="/kartu" label="Semua" rarity={null} active={selectedRarity === null} />
                    {RARITY_ORDER.map((r) => (
                        <RarityPill
                            key={r}
                            href={`/kartu?rarity=${r}`}
                            label={RARITY_LABELS[r]}
                            rarity={r}
                            active={selectedRarity === r}
                        />
                    ))}
                </nav>

                {featured?.activity?.detail && (
                    <section className="mt-6">
                        <div className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-citrus/15 px-3 py-1 text-xs font-bold uppercase tracking-wider text-citrus-deep ring-1 ring-citrus/40">
                            <Icon icon="mdi:star-shooting" width={14} height={14} className="text-citrus-deep" aria-hidden />
                            Kartu sorotan minggu ini
                        </div>
                        <MotionLink
                            href={`/aktivitas/${featured.activity_id}`}
                            whileTap={pressShrink}
                            onClick={() => triggerBurstFor(featured)}
                            className="block transition hover:-translate-y-1 hover:drop-shadow-xl"
                        >
                            <RunCard card={featured} detail={featured.activity.detail} size="hero" />
                        </MotionLink>
                    </section>
                )}

                {gridItems.length === 0 && featured === null ? (
                    <EmptyState />
                ) : (
                    <>
                        {gridItems.length > 0 && (
                            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {gridItems.map((card) => (
                                    <CardThumb key={card.id} card={card} onClick={() => triggerBurstFor(card)} />
                                ))}
                            </div>
                        )}
                        {cards.last_page > 1 && <Paginator links={cards.links} />}
                    </>
                )}
            </motion.main>
        </AppShell>
    );
}

function EmptyState() {
    return (
        <div className="mt-6 rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center">
            <Icon
                icon="mdi:cards-outline"
                width={32}
                height={32}
                className="mx-auto text-ink-3"
                aria-hidden
            />
            <p className="mt-3 text-sm leading-relaxed text-ink-2">
                Belum ada kartu di rarity ini. Coba filter lain, atau sinkronkan lari terbaru kamu.
            </p>
        </div>
    );
}

interface CardThumbProps {
    card: CardWithRel;
    onClick: () => void;
}

function CardThumb({ card, onClick }: Readonly<CardThumbProps>) {
    if (!card.activity?.detail) return null;
    return (
        <MotionLink
            href={`/aktivitas/${card.activity_id}`}
            whileTap={pressShrink}
            onClick={onClick}
            className="block h-full transition hover:-translate-y-0.5"
        >
            <RunCard card={card} detail={card.activity.detail} />
        </MotionLink>
    );
}

interface RarityPillProps {
    href: string;
    label: string;
    rarity: Rarity | null;
    active: boolean;
}

const RARITY_PILL_ACTIVE: Record<Rarity, string> = {
    legendary: 'border border-citrus bg-citrus text-white shadow-sm',
    epic: 'border border-horizon bg-horizon text-white shadow-sm',
    rare: 'border border-mood-mumet bg-mood-mumet text-white shadow-sm',
    uncommon: 'border border-leaf bg-leaf text-white shadow-sm',
    common: 'border border-ink-3 bg-ink-3 text-white shadow-sm',
};

const RARITY_PILL_ICON: Record<Rarity, string> = {
    legendary: 'mdi:crown',
    epic: 'mdi:star-four-points',
    rare: 'mdi:star',
    uncommon: 'mdi:star-outline',
    common: 'mdi:circle-outline',
};

const PILL_INACTIVE = 'border border-line bg-surface-elev text-ink-2 hover:border-leaf/40 hover:text-ink';
const PILL_ALL_ACTIVE = 'border border-leaf bg-leaf text-white shadow-sm';

function rarityToneClasses(rarity: Rarity | null, active: boolean): string {
    if (!active) return PILL_INACTIVE;
    return rarity === null ? PILL_ALL_ACTIVE : RARITY_PILL_ACTIVE[rarity];
}

function RarityPill({ href, label, rarity, active }: Readonly<RarityPillProps>) {
    const icon = rarity === null ? null : RARITY_PILL_ICON[rarity];
    return (
        <MotionLink
            href={href}
            whileTap={pressShrink}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition',
                rarityToneClasses(rarity, active),
            )}
        >
            {icon !== null && <Icon icon={icon} width={14} height={14} aria-hidden />}
            {label}
        </MotionLink>
    );
}
