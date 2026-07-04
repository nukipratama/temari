import { useRef } from 'react';
import { cn } from '@/lib/cn';
import {
    BADGE_ABILITY,
    RARITY_BORDER,
    RARITY_HEADBAND,
    RARITY_HEX,
    RARITY_LABELS,
    RARITY_POSE,
    RARITY_SYMBOL,
    RARITY_TEXT,
    badgeEmblem,
    badgeName,
} from '@/lib/runcard';
import { MOOD_FILL, MOOD_LABEL, moodSigilColor } from '@/lib/mood';
import RouteGlyph from '@/components/card/RouteGlyph';
import ZoneBar from '@/components/card/ZoneBar';
import TemariProto from '@/components/temari/TemariProto';
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
    subtitle?: string | null;
    km: string;
    durasi: string;
    trimp: string | number;
    rarity?: Rarity;
    /** The run's Temari mood, used as the card's "element/type". */
    mood?: Mood;
    /** Raw badge slugs — rendered as emblem pips in the stat block (full tier). */
    badges?: ReadonlyArray<string>;
    /** Secondary stats for the stat row. */
    stats?: KartuStats;
    /** HR zone distribution (Z1..Z5 %) for the effort bar. Hidden when absent. */
    zonePct?: ZonePct | null;
    /** Run route polyline + per-km pace seconds, for the art-window glyph. */
    polyline?: string | null;
    paceShape?: ReadonlyArray<number> | null;
    /** Temari flavor quote — shown in the stat block on the full tier. */
    flavor?: string | null;
    /** Collector number within the rarity ("#3/12"). */
    edition?: CardEdition | null;
    size?: 'md' | 'lg' | 'xl';
    className?: string;
}

// Foil treatment per rarity — a calm border halo only (no window-flooding
// sweep). The `--glow-strength` multiplier escalates the halo per tier; tiers at
// 0 get no `kartu-glow` class at all (flat frame).
const GLOW_STRENGTH: Record<Rarity, number> = {
    common: 0,
    uncommon: 0,
    rare: 1,
    epic: 1.6,
    legendary: 2.4,
};

const MASCOT_SIZE: Record<NonNullable<KartuProps['size']>, number> = {
    md: 36,
    lg: 48,
    xl: 56,
};

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

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * The collectible run card — a dark-frame, One-Piece-dense TCG card.
 *
 * A dark navy frame holds a bright art window where the **route is the hero**
 * (bold, filled) with Temari as a small corner companion. Below sits a dark
 * stat block: rarity ribbon, special-move name, subtitle, the run's numbers
 * (KM big; a labeled PACE · HR · CADENCE · DURASI · BEST grid on the full tier),
 * and a Z1..Z5 HR-zone effort bar. Rarity drives a vivid loot-ladder color
 * (gray → green → blue → purple → gold) on the frame, ribbon, and route, with a
 * calm per-tier border halo (no window-flooding foil). All cards tilt on hover.
 */
export default function Kartu({
    name,
    subtitle,
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
    flavor,
    edition,
    size = 'md',
    className,
}: Readonly<KartuProps>) {
    const isFull = size !== 'md';
    const slugs = badges ?? [];
    const rarityHex = RARITY_HEX[rarity];

    const cardRef = useRef<HTMLDivElement>(null);

    function handleMouseMove(e: React.MouseEvent<HTMLDivElement>) {
        const el = cardRef.current;
        if (!el) return;
        if (globalThis.matchMedia?.(REDUCED_MOTION_QUERY).matches) return;
        const r = el.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width;
        const y = (e.clientY - r.top) / r.height;
        // Promote to its own layer only while actually tilting; cleared on leave
        // so at-rest cards (and every card on touch) don't each hold a layer.
        el.style.willChange = 'transform';
        el.style.setProperty('--tilt-x', `${(y - 0.5) * -10}deg`);
        el.style.setProperty('--tilt-y', `${(x - 0.5) * 10}deg`);
    }

    function handleMouseLeave() {
        const el = cardRef.current;
        if (!el) return;
        el.style.removeProperty('--tilt-x');
        el.style.removeProperty('--tilt-y');
        el.style.willChange = '';
    }

    const moodColor = moodSigilColor(mood);
    const glowStrength = GLOW_STRENGTH[rarity];
    const rootStyle = { '--rarity': rarityHex, '--glow-strength': glowStrength } as CSSProperties;
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

    const statParts = buildStatParts(stats);

    return (
        <div
            ref={cardRef}
            role="img"
            aria-label={name}
            style={rootStyle}
            className={cn(
                'relative flex aspect-[5/7] flex-col overflow-hidden rounded-[16px] bg-sky-deep kartu-tilt',
                isFull ? 'border-[3px] p-1.5' : 'border-2 p-1',
                RARITY_BORDER[rarity],
                glowStrength > 0 && 'kartu-glow',
                className,
            )}
            onMouseMove={handleMouseMove}
            onMouseLeave={handleMouseLeave}
        >
            {/* ── ART WINDOW ── bright, route is hero, bunny corner companion */}
            <div className="relative flex-1 overflow-hidden rounded-[11px]" style={artStyle}>
                {/* Route hero */}
                <div className="absolute inset-0">
                    <RouteGlyph rarity={rarity} color={rarityHex} polyline={polyline} paceShape={paceShape} distanceKm={Number.parseFloat(km)} />
                </div>

                {/* Bunny corner companion */}
                <span aria-hidden className="pointer-events-none absolute bottom-1 right-1">
                    <TemariProto
                        pose={RARITY_POSE[rarity]}
                        size={MASCOT_SIZE[size]}
                        equipped={{ headband: RARITY_HEADBAND[rarity], medal: 'none' }}
                        animate={isFull}
                        dropShadow={false}
                    />
                </span>

                {/* Floating header — edition (left) + TRIMP "power" badge (right) */}
                <div className="absolute inset-x-1.5 top-1.5 flex items-start justify-between gap-1">
                    {edition ? <EditionMark edition={edition} /> : <span />}
                    <TRIMPBadge trimp={trimp} mood={mood} />
                </div>
            </div>

            {/* ── STAT BLOCK ── dark, high-contrast text */}
            <div className={cn('px-2 text-cream', isFull ? 'pt-2 pb-1.5' : 'pt-1.5 pb-1')}>
                {/* Rarity ribbon */}
                <div className="flex min-w-0 items-center gap-1">
                    <span aria-hidden className={cn('shrink-0 text-[10px] leading-none', RARITY_TEXT[rarity])}>
                        {RARITY_SYMBOL[rarity]}
                    </span>
                    <span className={cn('min-w-0 shrink-0 whitespace-nowrap font-mono text-[9px] font-bold uppercase tracking-[0.14em]', RARITY_TEXT[rarity])}>
                        {RARITY_LABELS[rarity]}
                    </span>
                </div>

                {/* Special-move name */}
                <div
                    className={cn('mt-0.5 font-collectible font-semibold uppercase leading-[1.02] tracking-[0.01em] text-cream', SIZE_NAME[size])}
                    style={nameGlow}
                >
                    {name}
                </div>

                {/* Subtitle */}
                {subtitle != null && subtitle !== '' && (
                    <div className="mt-0.5 truncate font-mono text-[9px] uppercase tracking-[0.08em] text-ink-on-sky">
                        {subtitle}
                    </div>
                )}

                {/* KM hero + (md only) inline pace · HR */}
                <div className="mt-1.5 flex items-end justify-between gap-2">
                    <div className="flex items-baseline gap-1">
                        <span className={cn('font-collectible font-bold tabular-nums leading-none text-horizon', SIZE_KM[size])}>
                            {km}
                        </span>
                        <span className="font-mono text-[9px] uppercase tracking-[0.12em] text-ink-on-sky">km</span>
                    </div>
                    {!isFull && statParts.length > 0 && (
                        <div className="min-w-0 text-right font-mono text-[10px] leading-tight text-cream/85">
                            {statParts.map((p, i) => (
                                <span key={p} className="whitespace-nowrap">
                                    {i > 0 && <span className="mx-1 text-ink-on-sky/50">·</span>}
                                    {p}
                                </span>
                            ))}
                        </div>
                    )}
                </div>

                {/* Full-tier labeled stat grid — a dense TCG stat block */}
                {isFull && <StatGrid stats={stats} durasi={durasi} />}

                {/* HR-zone effort bar (compact on md, labeled on full) */}
                {zonePct != null && (
                    <ZoneBar zonePct={zonePct} showLegend={isFull} className="mt-1.5" />
                )}

                {/* Badges + flavor (full tier only) */}
                {isFull && slugs.length > 0 && (
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        {slugs.map((slug) => (
                            <BadgePip key={slug} slug={slug} />
                        ))}
                    </div>
                )}
                {isFull && flavor != null && flavor !== '' && (
                    <p className="mt-1.5 font-display text-base italic leading-relaxed text-ink-on-sky">
                        &ldquo;{flavor}&rdquo;
                    </p>
                )}
            </div>
        </div>
    );
}

function EditionMark({ edition }: Readonly<{ edition: CardEdition }>) {
    return (
        <span className="inline-flex rounded-full bg-sky-deep/90 px-2 py-0.5 font-collectible text-[10px] font-semibold tabular-nums text-cream leading-none">
            #{edition.index}
            <span className="opacity-60">/{edition.total}</span>
        </span>
    );
}

function TRIMPBadge({ trimp, mood }: Readonly<{ trimp: string | number; mood: Mood }>) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-sky-deep/90 px-2 py-0.5 leading-none">
            <span
                aria-label={`Vibe ${MOOD_LABEL[mood]}`}
                className={cn('h-3 w-3 shrink-0 rounded-full', MOOD_FILL[mood])}
            />
            <span aria-hidden className="font-mono text-[11px] font-bold tabular-nums text-cream">
                {trimp}
            </span>
        </span>
    );
}

function BadgePip({ slug }: Readonly<{ slug: string }>) {
    return (
        <span
            title={BADGE_ABILITY[slug] ? badgeName(slug) + ' · ' + BADGE_ABILITY[slug] : badgeName(slug)}
            className="inline-flex items-center gap-0.5 rounded-full bg-cream/10 px-1.5 py-0.5 font-mono text-[10px] text-cream/85"
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

/** pace · HR — the compact inline stat row on the md (grid) tile. */
function buildStatParts(stats: KartuStats | undefined): string[] {
    const parts: string[] = [];
    if (stats?.pace != null && stats.pace !== '') {
        parts.push(stats.pace);
    }
    if (stats?.hr != null && stats.hr !== '') {
        parts.push(stats.hr);
    }
    return parts;
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
    push('Best km', stats?.fastestKm);

    if (cells.length === 0) {
        return null;
    }

    return (
        <dl className="mt-2 grid grid-cols-3 gap-x-2 gap-y-1.5">
            {cells.map((cell) => (
                <div key={cell.label} className="min-w-0">
                    <dt className="font-mono text-[8px] uppercase tracking-[0.1em] text-ink-on-sky">{cell.label}</dt>
                    <dd className="truncate font-mono text-[12px] font-semibold tabular-nums text-cream">{cell.value}</dd>
                </div>
            ))}
        </dl>
    );
}
