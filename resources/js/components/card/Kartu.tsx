import { cn } from '@/lib/cn';
import {
    BADGE_ABILITY,
    RARITY_BORDER,
    RARITY_HEX,
    RARITY_LABELS,
    RARITY_SYMBOL,
    RARITY_TEXT,
    badgeEmblem,
    badgeName,
} from '@/lib/runcard';
import { MOOD_FILL, MOOD_LABEL, moodSigilColor } from '@/lib/mood';
import RouteGlyph from '@/components/card/RouteGlyph';
import ZoneBar from '@/components/card/ZoneBar';
import type { CardEdition, Mood, Rarity, ZonePct } from '@/types/inertia';
import type { CSSProperties } from 'react';

/** Secondary telemetry shown in the stat block. */
export interface KartuStats {
    pace?: string;
    hr?: string;
    /** Avg cadence, e.g. "178 spm" — full tier only. */
    cadence?: string;
    /** Fastest single km pace, e.g. "5:41" — full tier only. */
    fastestKm?: string;
    elevation?: string;
    weather?: string;
}

interface KartuProps {
    name: string;
    km: string;
    durasi: string;
    trimp: string | number;
    rarity?: Rarity;
    /** The run's Temari mood, used as the card's "element/type". */
    mood?: Mood;
    /** Raw badge slugs — rendered as emblem pips in the stat block (full tier). */
    badges?: ReadonlyArray<string>;
    /** Secondary stats for the stat row. */
    stats?: KartuStats | undefined;
    /** HR zone distribution (Z1..Z5 %) for the effort bar. Hidden when absent. */
    zonePct?: ZonePct | null;
    /** Run route polyline + per-km pace seconds, for the art-window glyph. */
    polyline?: string | null;
    paceShape?: ReadonlyArray<number> | null;
    /** Collector number within the rarity ("#3/12"). */
    edition?: CardEdition | null;
    size?: 'md' | 'lg' | 'xl';
    /** Hide the special-move name on mobile (below sm). */
    hideName?: boolean;
    /** Hide the full stat grid on very small screens. */
    hideStats?: boolean;
    /** Shrink the floating rarity + TRIMP corner chips so the longest rarity
     *  name (LEGENDARIS) fits without clipping/overlap on the narrow grid tiles. */
    compact?: boolean;
    className?: string;
}

// Foil treatment — a calm border halo, tinted by the card's own `--rarity`
// hex, same strength on every tier (a common card glows in muted grey just
// as brightly as a legendary glows in gold — consistency over escalation).
const GLOW_STRENGTH = 2;

const SIZE_NAME: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'text-[14px]',
    lg: 'text-[19px]',
    xl: 'text-[23px]',
};

const SIZE_KM: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'text-[26px]',
    lg: 'text-[34px]',
    xl: 'text-[42px]',
};

/**
 * The collectible run card — a dark-frame, One-Piece-dense TCG card.
 *
 * A dark navy frame holds a bright art window where the **route is the hero**
 * (bold, filled), plus floating rarity and TRIMP chips in its top corners.
 * Below sits a dark stat block:
 * special-move name, the run's numbers (KM big; a labeled
 * PACE · HR · CADENCE · DURASI · BEST grid), badges, and a Z1..Z5 HR-zone
 * effort bar. Rarity drives a vivid loot-ladder color (gray → green → blue →
 * purple → gold) on the frame and route, with the same calm border halo on
 * every tier (no window-flooding foil).
 */
export default function Kartu({
    name,
    km,
    durasi,
    trimp,
    rarity = 'epic',
    mood = 'adem',
    badges,
    stats,
    zonePct,
    polyline,
    paceShape,
    edition,
    size = 'md',
    hideName = false,
    hideStats = false,
    compact = false,
    className,
}: Readonly<KartuProps>) {
    const isFull = size !== 'md';
    const slugs = badges ?? [];
    // A grid thumbnail (`compact`) shows a single pip. The frame is a fixed
    // aspect-[5/7], so anything the stat block cannot fit is clipped by the
    // card's overflow-hidden — and six pips wrapping over four rows on a ~98px
    // card squeezed the art window to nothing entirely, dropping its
    // EditionMark onto the RarityChip ("BERKESAN4").
    //
    // One rather than two because pip height is not fixed: a long label wraps
    // to two lines on its own at that width ("Negative Split" does, and
    // "Long Slow Distance" is longer still), so any count above one clips for
    // some badge sets. `whitespace-nowrap` is not the answer either — the
    // longest label is far wider than the card and would reintroduce horizontal
    // overflow. Tapping through shows the full set.
    const shownSlugs = compact ? slugs.slice(0, 1) : slugs;
    const rarityHex = RARITY_HEX[rarity];

    const moodColor = moodSigilColor(mood);
    const rootStyle = { '--rarity': rarityHex, '--glow-strength': GLOW_STRENGTH } as CSSProperties;
    const nameGlow = nameGlowFor(rarity);

    // Pearl art-window backdrop — mirrors the canvas share card: a rarity tier
    // glow up top, a faint mood echo bottom-right, over a cream depth gradient so
    // the route reads with contrast instead of floating on flat cream.
    const artStyle: CSSProperties = {
        background: [
            `radial-gradient(ellipse at 30% 26%, ${rarityHex}30 0%, ${rarityHex}12 42%, transparent 70%)`,
            `radial-gradient(ellipse at 82% 84%, ${moodColor}22 0%, transparent 60%)`,
            `linear-gradient(to bottom, #fcf9f3, var(--color-cream-deep))`,
        ].join(', '),
    };

    return (
        <div
            role="img"
            aria-label={name}
            style={rootStyle}
            className={cn(
                'relative flex aspect-[5/7] flex-col overflow-hidden rounded-[16px] bg-sky-deep',
                isFull ? 'border-[3px] p-1.5' : 'border-2 p-1',
                RARITY_BORDER[rarity],
                'kartu-glow',
                className,
            )}
        >
            {/* ── ART WINDOW ── bright, route is hero.
                `min-h-[30%]` because `flex-1` alone is `flex: 1 1 0%`, which happily
                shrinks to zero: inside a fixed `aspect-[5/7]` frame, a tall stat
                block (badges wrapping over several rows on a ~140px grid card)
                would starve the window entirely. That hid the route art — the
                card's whole point — and collapsed the window's bottom-left
                EditionMark onto the card's top-left RarityChip, rendering as
                "BERKESAN4". The floor keeps the art visible and the two corner
                marks apart at any width. */}
            <div className="relative min-h-[30%] flex-1 overflow-hidden rounded-[11px]" style={artStyle}>
                {/* Route hero */}
                <div className="absolute inset-0">
                    <RouteGlyph rarity={rarity} color={rarityHex} polyline={polyline} paceShape={paceShape} distanceKm={Number.parseFloat(km)} />
                </div>

                {/* Edition mark hugs the art window's own bottom-left corner
                    (the window's bottom edge sits mid-card, above the stat
                    block, so it stays inside the window, not the outer card). */}
                {edition && (
                    <div className="absolute bottom-0 left-0">
                        <EditionMark edition={edition} />
                    </div>
                )}
            </div>

            {/* Rarity + TRIMP hug the CARD's own top corners (not the art window's),
                so they sit flush against the outer edge with no border/padding
                sliver; overflow-hidden on the outer frame clips their square outer
                corners to its radius. Mirrors the share card's corner treatment. */}
            <div className="absolute left-0 top-0">
                <RarityChip rarity={rarity} compact={compact} />
            </div>
            <div className="absolute right-0 top-0">
                <TRIMPBadge trimp={trimp} mood={mood} compact={compact} />
            </div>

            {/* ── STAT BLOCK ── dark, high-contrast text. The SAME full layout at
                every tier (name, KM, badges, stat grid, zone bar) so the grid /
                featured / detail cards all mirror the share card; only the scale
                differs by size. */}
            <div className={cn('px-2 pb-1.5 text-center text-cream', isFull ? 'pt-2' : 'pt-1.5')}>
                {/* Special-move name (rarity now floats on the art window) */}
                <div
                    className={cn(
                        'font-collectible font-semibold uppercase leading-[1.02] tracking-[0.01em] text-cream',
                        SIZE_NAME[size],
                        hideName && 'hidden sm:block',
                    )}
                    style={nameGlow}
                >
                    {name}
                </div>

                {/* KM hero, centred */}
                <div className="mt-1 flex items-baseline justify-center gap-1">
                    <span className={cn('font-collectible font-bold tabular-nums leading-none', RARITY_TEXT[rarity], SIZE_KM[size])}>
                        {km}
                    </span>
                    <span className="font-mono text-[9px] uppercase tracking-[0.12em] text-cream">km</span>
                </div>

                {/* Badges — centred row below the KM hero. */}
                {shownSlugs.length > 0 && (
                    <div className="mt-1.5 flex flex-wrap justify-center gap-1">
                        {shownSlugs.map((slug) => (
                            <BadgePip key={slug} slug={slug} />
                        ))}
                    </div>
                )}

                {/* Labeled stat grid — the dense TCG stat block. At narrow (mobile grid)
                    widths it's too tight for six truncation-prone cells, so `hideStats`
                    drops it below the `sm` breakpoint; badges above stay as the mobile
                    summary. Wider/detail renders keep the full grid. */}
                <div className={cn(hideStats && 'hidden sm:block')}>
                    <StatGrid stats={stats} durasi={durasi} />

                    {/* HR-zone effort bar — bare (no Z1..Z5 legend), matching the share
                        card's rounded legendless bar. */}
                    {zonePct != null && (
                        <ZoneBar zonePct={zonePct} showLegend={false} className="mt-1.5" />
                    )}
                </div>
            </div>
        </div>
    );
}

// The chips have SQUARE outer corners and only the inner corner rounds; the art
// window's `overflow-hidden` clips them to its radius, so they fill the corner
// completely (no pearl sliver) and read as truly stuck to the corner. Opaque
// background + bumped sizes for legibility.
function RarityChip({ rarity, compact = false }: Readonly<{ rarity: Rarity; compact?: boolean }>) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-br-[11px] bg-sky-deep leading-none',
                compact ? 'gap-0.5 px-1.5 py-1' : 'gap-1 px-2.5 py-1.5',
            )}
        >
            {/* The set symbol is dropped on the compact grid tile to buy width for
                the longest rarity name; the border color still tags the tier. */}
            {!compact && (
                <span aria-hidden className={cn('text-[12px] leading-none', RARITY_TEXT[rarity])}>{RARITY_SYMBOL[rarity]}</span>
            )}
            <span
                className={cn(
                    'font-sans font-bold uppercase',
                    compact ? 'text-[8px] tracking-[0.02em]' : 'text-[11px] tracking-[0.04em]',
                    RARITY_TEXT[rarity],
                )}
            >
                {RARITY_LABELS[rarity]}
            </span>
        </span>
    );
}

function EditionMark({ edition }: Readonly<{ edition: CardEdition }>) {
    return (
        <span className="inline-flex rounded-tr-[11px] bg-sky-deep px-2.5 py-1.5 font-sans text-[12px] font-bold tabular-nums text-cream leading-none">
            #{edition.index}
            <span className="opacity-60">/{edition.total}</span>
        </span>
    );
}

function TRIMPBadge({ trimp, mood, compact = false }: Readonly<{ trimp: string | number; mood: Mood; compact?: boolean }>) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-bl-[11px] bg-sky-deep leading-none',
                compact ? 'gap-1 px-1.5 py-1' : 'gap-1.5 px-2.5 py-1.5',
            )}
        >
            <span
                aria-label={`Vibe ${MOOD_LABEL[mood]}`}
                className={cn('shrink-0 rounded-full', MOOD_FILL[mood], compact ? 'h-2.5 w-2.5' : 'h-3 w-3')}
            />
            <span
                aria-hidden
                className={cn('font-sans font-extrabold tabular-nums text-cream', compact ? 'text-[11px]' : 'text-[13px]')}
            >
                {trimp}
            </span>
        </span>
    );
}

function BadgePip({ slug }: Readonly<{ slug: string }>) {
    return (
        <span
            title={BADGE_ABILITY[slug] ? badgeName(slug) + ' · ' + BADGE_ABILITY[slug] : badgeName(slug)}
            className="inline-flex items-center gap-0.5 rounded-full bg-cream/10 px-1.5 py-0.5 font-mono text-[10px] text-cream"
        >
            <span aria-hidden>{badgeEmblem(slug)}</span>
            <span>{badgeName(slug)}</span>
        </span>
    );
}

/** Epic+ names get a rarity-tinted glow; lower tiers stay flat cream. */
function nameGlowFor(rarity: Rarity): CSSProperties {
    if (rarity === 'legendary') {
        return { textShadow: '0 0 14px color-mix(in oklab, var(--rarity) 75%, transparent)' };
    }
    if (rarity === 'epic') {
        return { textShadow: '0 0 10px color-mix(in oklab, var(--rarity) 60%, transparent)' };
    }
    return {};
}

/**
 * Full-tier labeled stat block: a dense PACE · HR · CADENCE · DURASI · BEST grid.
 * Each cell only renders when its source value is present (no "—" filler), so the
 * block stays honest and fills with substance rather than padding.
 */
function StatGrid({ stats, durasi }: Readonly<{ stats: KartuStats | undefined; durasi: string }>) {
    const cells: Array<{ label: string; value: string }> = [];
    const push = (label: string, value: string | undefined) => {
        if (value != null && value !== '' && value !== '—') {
            cells.push({ label, value });
        }
    };
    push('Pace', stats?.pace);
    push('HR', stats?.hr);
    push('Cadence', stats?.cadence);
    push('Durasi', durasi);
    push('Best', stats?.fastestKm);
    push('Elevasi', stats?.elevation);

    if (cells.length === 0) {
        return null;
    }

    return (
        <dl className="mt-2 grid grid-cols-3 gap-x-2 gap-y-1.5 text-center">
            {cells.map((cell) => (
                <div key={cell.label} className="min-w-0">
                    <dt className="font-mono text-[8px] uppercase tracking-[0.1em] text-cream">{cell.label}</dt>
                    <dd className="truncate font-mono text-[12px] font-semibold tabular-nums text-cream">{cell.value}</dd>
                </div>
            ))}
        </dl>
    );
}
