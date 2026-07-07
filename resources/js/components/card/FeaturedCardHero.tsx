import { type ReactNode } from 'react';
import { Icon } from '@iconify/react';
import HeroPanel from '@/components/ui/HeroPanel';
import PillLink from '@/components/ui/PillLink';
import { type KartuStats } from '@/components/card/Kartu';
import { RARITY_LABELS, RARITY_SYMBOL, badgeEmblem, badgeName } from '@/lib/runcard';
import type { Rarity } from '@/types/inertia';

interface FeaturedCardHeroProps {
    /** Mono eyebrow above the name, e.g. "★ Kartu andalan dari Temari". */
    eyebrow: string;
    name: string;
    rarity: Rarity;
    /** Formatted distance for the catch line, e.g. "10.01". */
    km: string;
    /** Run telemetry for the hero stat strip (present-only cells). */
    stats?: KartuStats;
    /** Formatted moving time for the DURASI cell. */
    durasi?: string;
    /** Badge slugs rendered as small pips. */
    badges?: ReadonlyArray<string>;
    /** One short Temari voice line (a compact <AnalysisStatus>) — optional. */
    voice?: ReactNode;
    ctaHref: string;
    ctaLabel?: string;
    /** The pre-built <Kartu> (pass with `w-full`) — bleeds past the frame on lg. */
    card: ReactNode;
}

/**
 * The featured-card hero: one card, made the star. An oversized, tilted Kartu
 * breaks past the panel's top + bottom edges on desktop while a substantive copy
 * column (eyebrow, name, rarity·KM catch line, a telemetry stat strip, badge
 * pips, one short Temari line, CTA) fills the left. On mobile the card sits
 * inside the panel below the copy so it stays part of the hero.
 */
export default function FeaturedCardHero({
    eyebrow,
    name,
    rarity,
    km,
    stats,
    durasi,
    badges,
    voice,
    ctaHref,
    ctaLabel = 'Lihat aktivitas',
    card,
}: Readonly<FeaturedCardHeroProps>) {
    const catchLine = `${RARITY_SYMBOL[rarity]} ${RARITY_LABELS[rarity]} · ${km} KM`;
    const cells = statCells(stats, durasi);
    const pips = (badges ?? []).slice(0, 3);

    return (
        <div className="relative my-8">
            <HeroPanel className="min-h-[300px] lg:px-14 lg:py-12">
                {/* Left copy — kept clear of the bleeding card on desktop. */}
                <div className="relative lg:max-w-[58%]">
                    <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.2em] text-horizon">
                        {eyebrow}
                    </div>
                    <h2 className="font-display text-display-xl text-cream">
                        <em className="italic text-horizon">{name}</em>
                    </h2>
                    <div className="mt-3 font-mono text-[13px] font-bold uppercase tracking-[0.12em] text-cream/85">
                        {catchLine}
                    </div>

                    {cells.length > 0 && (
                        <dl className="mt-5 flex flex-wrap gap-x-8 gap-y-3">
                            {cells.map((cell) => (
                                <div key={cell.label}>
                                    <dt className="font-mono text-[10px] uppercase tracking-[0.12em] text-ink-on-sky">
                                        {cell.label}
                                    </dt>
                                    <dd className="font-mono text-[15px] font-semibold tabular-nums text-cream">
                                        {cell.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}

                    {pips.length > 0 && (
                        <div className="mt-4 flex flex-wrap gap-1.5">
                            {pips.map((slug) => (
                                <span
                                    key={slug}
                                    className="inline-flex items-center gap-1 rounded-full bg-cream/10 px-2 py-0.5 font-mono text-[11px] text-cream/85"
                                >
                                    <span aria-hidden>{badgeEmblem(slug)}</span>
                                    <span>{badgeName(slug)}</span>
                                </span>
                            ))}
                        </div>
                    )}

                    {voice && (
                        <div className="mt-4 max-w-md text-sm">{voice}</div>
                    )}
                    <PillLink href={ctaHref} onSky className="mt-6">
                        <Icon icon="mdi:run" width={16} height={16} aria-hidden />
                        {ctaLabel}
                    </PillLink>
                </div>

                {/* Mobile: the card lives on the navy hero panel, below the copy. */}
                <div className="mt-6 flex justify-center lg:hidden">
                    <div className="w-full max-w-[300px]">{card}</div>
                </div>
            </HeroPanel>

            {/* The frame-breaker — desktop only. Pinned right, taller than the
                panel via negative top/bottom so it bleeds past the top/bottom edge.
                Callers pass the Kartu with `w-full`; the slot sets the size. */}
            <div className="pointer-events-none absolute -bottom-6 -top-6 right-10 hidden items-center lg:flex">
                <div className="w-[300px]">{card}</div>
            </div>
        </div>
    );
}

/** Present-only PACE · HR · CADENCE · DURASI · BEST cells (mirrors Kartu's StatGrid). */
function statCells(stats: KartuStats | undefined, durasi: string | undefined): Array<{ label: string; value: string }> {
    const raw: Array<{ label: string; value: string | undefined }> = [
        { label: 'PACE', value: stats?.pace },
        { label: 'HR', value: stats?.hr },
        { label: 'CADENCE', value: stats?.cadence },
        { label: 'DURASI', value: durasi },
        { label: 'BEST', value: stats?.fastestKm },
    ];
    return raw.filter((c): c is { label: string; value: string } => c.value != null && c.value !== '' && c.value !== '—');
}
