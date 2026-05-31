import { cn } from '@/lib/cn';
import { BADGE_ABILITY, RARITY_BORDER, RARITY_DOT, RARITY_LABELS, RARITY_TINT, badgeEmblem, badgeName } from '@/lib/runcard';
import { BunnyGlyph } from '@/components/BrandMark';
import GradientText from '@/components/ui/GradientText';
import RouteGlyph from '@/components/card/RouteGlyph';
import type { CardEdition, Rarity } from '@/types/inertia';

interface KartuProps {
    name: string;
    subtitle?: string | null;
    km: string;
    durasi: string;
    trimp: string | number;
    rarity?: Rarity;
    /** Raw badge slugs — rendered as ability rows (full) / emblem chips (compact). */
    badges?: ReadonlyArray<string>;
    /** Run route polyline + per-km pace seconds, for the art-window glyph. */
    polyline?: string | null;
    paceShape?: ReadonlyArray<number> | null;
    /** Temari flavor quote — only shown on the full tier. */
    flavor?: string | null;
    /** Collector number within the rarity ("#3/12"). */
    edition?: CardEdition | null;
    size?: 'md' | 'lg' | 'xl';
    className?: string;
}

// RARITY_DOT / RARITY_TINT are shared with KartuMini — see lib/runcard.ts.
const RARITY_RIBBON: Record<Rarity, string> = {
    common: 'bg-rarity-common/[0.12]',
    uncommon: 'bg-rarity-uncommon/[0.12]',
    rare: 'bg-rarity-rare/[0.14]',
    epic: 'bg-rarity-epic/[0.16]',
    legendary: 'bg-rarity-legendary/[0.16]',
};

const SIZE_PAD: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'p-3.5',
    lg: 'p-5',
    xl: 'p-6',
};

const SIZE_NAME: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'text-[21px]',
    lg: 'text-[30px]',
    xl: 'text-[40px]',
};

const SIZE_KM_PLAIN: Record<NonNullable<KartuProps['size']>, string> = {
    md: 'text-[30px]',
    lg: 'text-[40px]',
    xl: 'text-[52px]',
};

const FULL_KM_FONT: Record<'lg' | 'xl', string> = {
    lg: '48px',
    xl: '64px',
};

/**
 * The collectible run card, in a framed trading-card silhouette: a chrome row
 * (rarity ribbon + edition), an Oswald nameplate, the run's route as art, a
 * hero KM stat with TRIMP/duration demoted to a footnote, and — on the full
 * tier — badge "abilities" with meanings plus the Temari flavor quote.
 *
 * Two tiers off `size`: `md` is the compact grid tile (no gradient KM, no
 * ability text, no flavor — keeps the viewport calm); `lg`/`xl` is the full
 * hero card. Top tiers (Epik/Legendaris) get an animated holo sheen.
 */
export default function Kartu({
    name,
    subtitle,
    km,
    durasi,
    trimp,
    rarity = 'epic',
    badges,
    polyline,
    paceShape,
    flavor,
    edition,
    size = 'md',
    className,
}: Readonly<KartuProps>) {
    const isFull = size !== 'md';
    const isHolo = rarity === 'epic' || rarity === 'legendary';
    const slugs = badges ?? [];
    // Compact tiles collapse the art window when there's nothing to draw (no
    // empty box); the full/hero tier keeps it and shows the rarity motif so the
    // card doesn't read as stubby.
    const hasArt = (polyline != null && polyline !== '') || (paceShape != null && paceShape.length > 0);

    return (
        <div
            className={cn(
                'relative flex h-full flex-col overflow-hidden rounded-[16px] border-2 bg-surface-card',
                RARITY_BORDER[rarity],
                isHolo && 'kartu-holo',
                SIZE_PAD[size],
                className,
            )}
        >
            <span aria-hidden className={cn('pointer-events-none absolute inset-0', RARITY_TINT[rarity])} />

            <div className="relative z-10 flex h-full flex-col">
                <header className="flex items-center justify-between gap-2">
                    <RarityRibbon rarity={rarity} />
                    {edition && <EditionMark edition={edition} />}
                </header>

                <div className="mt-2.5">
                    <div className={cn('font-collectible font-semibold uppercase leading-[1.04] tracking-[0.01em] text-ink', SIZE_NAME[size])}>
                        {name}
                    </div>
                    {subtitle != null && subtitle !== '' && (
                        <div className="mt-1 font-mono text-[11px] uppercase tracking-[0.1em] text-ink-3">
                            {subtitle}
                        </div>
                    )}
                </div>

                {(hasArt || isFull) && (
                    <CardArtWindow rarity={rarity} polyline={polyline} paceShape={paceShape} />
                )}

                <div className="mt-3 flex items-end justify-between gap-3">
                    <div className="flex items-baseline gap-1.5">
                        {isFull ? (
                            <GradientText preset="horizon" fontSize={FULL_KM_FONT[size]} className="font-collectible font-bold tabular-nums leading-none">
                                {km}
                            </GradientText>
                        ) : (
                            <span className={cn('font-collectible font-bold tabular-nums leading-none text-horizon-deep', SIZE_KM_PLAIN[size])}>
                                {km}
                            </span>
                        )}
                        <span className="font-mono text-xs uppercase tracking-[0.12em] text-ink-3">km</span>
                    </div>
                    <div className="text-right font-mono text-[11px] leading-tight tracking-[0.04em] text-ink-3">
                        <div>{durasi}</div>
                        <div className="tabular-nums">TRIMP {trimp}</div>
                    </div>
                </div>

                {slugs.length > 0 && (
                    isFull ? (
                        <div className="mt-4 space-y-2">
                            {slugs.map((slug) => (
                                <AbilityRow key={slug} slug={slug} />
                            ))}
                        </div>
                    ) : (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {slugs.slice(0, 3).map((slug) => (
                                <EmblemChip key={slug} slug={slug} />
                            ))}
                        </div>
                    )
                )}

                {isFull && flavor != null && flavor !== '' && (
                    <p className="mt-auto pt-4 font-display text-quote-md italic text-ink-2">
                        “{flavor}”
                    </p>
                )}
            </div>
        </div>
    );
}

function RarityRibbon({ rarity }: Readonly<{ rarity: Rarity }>) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1', RARITY_RIBBON[rarity])}>
            <span aria-hidden className={cn('h-2 w-2 rounded-full', RARITY_DOT[rarity])} />
            <span className="font-mono text-[11px] font-bold uppercase tracking-[0.12em] text-ink-2">
                {RARITY_LABELS[rarity]}
            </span>
        </span>
    );
}

function EditionMark({ edition }: Readonly<{ edition: CardEdition }>) {
    return (
        <span className="font-collectible text-[13px] font-semibold tabular-nums text-ink-3">
            #{edition.index}
            <span className="text-ink-3/60">/{edition.total}</span>
        </span>
    );
}

function CardArtWindow({
    rarity,
    polyline,
    paceShape,
}: Readonly<{ rarity: Rarity; polyline?: string | null; paceShape?: ReadonlyArray<number> | null }>) {
    return (
        <div className="relative mt-3 aspect-[5/3] w-full overflow-hidden rounded-lg border border-line bg-surface-sunken">
            <RouteGlyph rarity={rarity} polyline={polyline} paceShape={paceShape} />
            <span aria-hidden className="pointer-events-none absolute bottom-1 right-1 opacity-30">
                <BunnyGlyph size={20} tone="ink" />
            </span>
        </div>
    );
}

function AbilityRow({ slug }: Readonly<{ slug: string }>) {
    return (
        <div className="flex items-baseline gap-2">
            <span aria-hidden className="text-base leading-none">{badgeEmblem(slug)}</span>
            <div className="min-w-0">
                <span className="font-sans text-[13px] font-semibold text-ink">{badgeName(slug)}</span>
                {BADGE_ABILITY[slug] && (
                    <span className="font-sans text-[13px] text-ink-3"> · {BADGE_ABILITY[slug]}</span>
                )}
            </div>
        </div>
    );
}

function EmblemChip({ slug }: Readonly<{ slug: string }>) {
    return (
        <span
            title={badgeName(slug)}
            className="inline-flex items-center gap-1 rounded-full bg-sky/[0.06] px-2 py-0.5 text-[11px] font-medium text-ink-2"
        >
            <span aria-hidden>{badgeEmblem(slug)}</span>
            {badgeName(slug)}
        </span>
    );
}
