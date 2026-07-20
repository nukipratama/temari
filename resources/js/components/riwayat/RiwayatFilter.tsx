import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useCallback, useRef, useState } from 'react';
import { useDismissable } from '@/hooks/useDismissable';
import { useFocusReturn } from '@/hooks/useFocusReturn';
import { cn } from '@/lib/cn';
import type { Mood } from '@/types/inertia';

export interface RangeOption<V extends string> {
    value: V;
    label: string;
    hint?: string;
}

export interface MoodOption {
    mood: Mood;
    label: string;
    hint: string;
    /** Tailwind class for the chip swatch. */
    swatchClass: string;
}

interface RangeSection<V extends string> {
    /** Currently active value. Always one of `options`. */
    value: V;
    options: ReadonlyArray<RangeOption<V>>;
    /** The range lives in the URL, so each option is a real link. Builds its href. */
    hrefFor: (value: V) => string;
    /** Inertia partial-reload props to scope the visit to. */
    only?: ReadonlyArray<string>;
}

interface MoodSection {
    selected: ReadonlySet<Mood>;
    options: ReadonlyArray<MoodOption>;
    onToggle: (mood: Mood) => void;
}

export interface DistanceOption<B extends string> {
    value: B;
    label: string;
    hint?: string;
}

interface DistanceSection<B extends string> {
    /** Active band, or null for any distance. */
    value: B | null;
    options: ReadonlyArray<DistanceOption<B>>;
    /** Selecting the active band again clears it. */
    onSelect: (band: B) => void;
}

interface SearchSection {
    /** The term the server is currently filtering on. */
    value: string;
    onSubmit: (term: string) => void;
}

export interface SortOption<S extends string> {
    value: S;
    label: string;
    hint?: string;
}

interface SortSection<S extends string> {
    value: S;
    options: ReadonlyArray<SortOption<S>>;
    onSelect: (sort: S) => void;
}

interface RiwayatFilterProps<V extends string, B extends string = string, S extends string = string> {
    range?: RangeSection<V>;
    mood?: MoodSection;
    distance?: DistanceSection<B>;
    search?: SearchSection;
    sort?: SortSection<S>;
    /** When the user hits Reset — clears every filter set this component owns. */
    onReset?: () => void;
    className?: string;
}

/**
 * Single compact filter trigger used on both Jejak and Kalender. Replaces
 * a row-spanning chip strip with one button + popover, so the page title and
 * tabs stay the visual anchor and the filter is opt-in.
 *
 * Active-filter count is surfaced as a badge on the button so the user can
 * see at a glance whether they're looking at a filtered slice.
 */
export default function RiwayatFilter<V extends string, B extends string = string, S extends string = string>({
    range,
    mood,
    distance,
    search,
    sort,
    onReset,
    className,
}: Readonly<RiwayatFilterProps<V, B, S>>) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismissable(open, containerRef, close);
    useFocusReturn(open);

    const moodActive = mood?.selected.size ?? 0;
    // Range counts as "active" only when the user picked something other than
    // the first (most-recent) option — that's the implicit default.
    const rangeActive =
        range && range.options.length > 0 && range.value !== range.options[0].value ? 1 : 0;
    const distanceActive = distance?.value != null ? 1 : 0;
    const searchActive = (search?.value ?? '') !== '' ? 1 : 0;
    // Like range, the first sort option is the implicit default and isn't counted.
    const sortActive =
        sort && sort.options.length > 0 && sort.value !== sort.options[0].value ? 1 : 0;
    const totalActive = moodActive + rangeActive + distanceActive + searchActive + sortActive;
    const summary = buildSummary(range, moodActive, distance, searchActive > 0, sort);

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <button
                type="button"
                onClick={(e) => {
                    e.currentTarget.focus();
                    setOpen((v) => !v);
                }}
                aria-expanded={open}
                aria-label="Buka filter"
                className={cn(
                    'focus-ring inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-medium transition lg:text-sm',
                    totalActive > 0
                        ? 'border-sky/40 bg-sky/[0.06] text-sky'
                        : 'border-line/60 bg-surface-elev text-ink-2 hover:bg-surface-warm',
                )}
            >
                <Icon icon="mdi:tune-variant" width={14} height={14} aria-hidden />
                <span>{summary}</span>
                {totalActive > 0 && (
                    <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-sky px-1 font-mono text-[11px] font-bold text-cream">
                        {totalActive}
                    </span>
                )}
                <Icon
                    icon="mdi:chevron-down"
                    width={14}
                    height={14}
                    aria-hidden
                    className={cn('transition', open && 'rotate-180')}
                />
            </button>
            {/* Scrim behind the mobile sheet: gives the sheet something to sit
                against and makes tapping away to dismiss an obvious target.
                Desktop keeps the anchored popover, so it is hidden there. */}
            {open && <div className="fixed inset-0 z-30 bg-ink/20 lg:hidden" aria-hidden onClick={close} />}
            {open && (
                <div
                    className={cn(
                        // Mobile: a bottom sheet — thumb-reachable, full-width, and
                        // able to grow as filters are added, where a 288px popover
                        // anchored to a top-right trigger gets cramped and sits at
                        // the far end of the screen from the thumb.
                        'fixed inset-x-0 bottom-0 z-40 max-h-[80vh] overflow-y-auto rounded-t-2xl border border-line bg-surface-elev pb-[max(1rem,env(safe-area-inset-bottom))] shadow-lg',
                        // Desktop: the original anchored popover.
                        'lg:absolute lg:inset-x-auto lg:right-0 lg:bottom-auto lg:top-[calc(100%+8px)] lg:max-h-none lg:w-72 lg:overflow-hidden lg:rounded-2xl lg:pb-0',
                    )}
                >
                    {/* Grab handle: the affordance that says "this sheet is
                        dismissable", mobile only. */}
                    <div className="flex justify-center pt-2 lg:hidden" aria-hidden>
                        <span className="h-1 w-9 rounded-full bg-ink/15" />
                    </div>
                    {(totalActive > 0 || onReset) && (
                        <div className="flex items-center justify-between border-b border-line/60 px-3 py-2">
                            <span className="font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                                Filter
                            </span>
                            {totalActive > 0 && onReset && (
                                <button
                                    type="button"
                                    onClick={onReset}
                                    className="focus-ring rounded text-[11px] font-medium text-sky hover:underline"
                                >
                                    Reset
                                </button>
                            )}
                        </div>
                    )}
                    {search && <SearchSectionView section={search} />}
                    {/* Sitting on anything but the first option switches the page to a
                        flat ranked list, which the hint spells out. */}
                    {sort && <OptionListSectionView title="Urutan" section={sort} />}
                    {range && <RangeSectionView section={range} />}
                    {distance && <OptionListSectionView title="Jarak" section={distance} />}
                    {mood && <MoodSectionView section={mood} />}
                </div>
            )}
        </div>
    );
}

function RangeSectionView<V extends string>({ section }: Readonly<{ section: RangeSection<V> }>) {
    return (
        <div className="border-b border-line/60 px-3 py-3 last:border-b-0">
            <div className="mb-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                Rentang waktu
            </div>
            <div className="flex flex-col gap-1">
                {section.options.map((opt) => {
                    const active = opt.value === section.value;
                    return (
                        <Link
                            key={opt.value}
                            href={section.hrefFor(opt.value)}
                            only={section.only ? [...section.only] : undefined}
                            preserveScroll
                            preserveState
                            aria-current={active ? 'true' : undefined}
                            className={cn(
                                'focus-ring flex min-h-11 w-full items-center justify-between rounded-lg px-2 py-2 text-left text-xs transition lg:text-sm',
                                active ? 'bg-sky/10 font-semibold text-sky' : 'text-ink hover:bg-surface-warm',
                            )}
                        >
                            <span>{opt.label}</span>
                            {opt.hint && (
                                <span className="font-mono text-[11px] text-ink-3">{opt.hint}</span>
                            )}
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}

function MoodSectionView({ section }: Readonly<{ section: MoodSection }>) {
    return (
        <div className="border-b border-line/60 px-3 py-3 last:border-b-0">
            <div className="mb-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                Mood
            </div>
            <div className="grid grid-cols-2 gap-1">
                {section.options.map(({ mood, label, swatchClass }) => {
                    const active = section.selected.has(mood);
                    return (
                        <button
                            key={mood}
                            type="button"
                            aria-pressed={active}
                            onClick={() => section.onToggle(mood)}
                            className={cn(
                                'focus-ring flex min-h-11 items-center gap-2 rounded-lg px-2 py-2 text-left text-xs font-medium transition',
                                active ? 'bg-sky/10 text-sky' : 'text-ink hover:bg-surface-warm',
                            )}
                        >
                            <span className={cn('inline-block h-3 w-3 rounded-sm', swatchClass)} aria-hidden />
                            {label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Free-text search over the run name. Submits on Enter or blur rather than per
 * keystroke: each submit is a server round trip, so debouncing every character
 * would fire a burst of partial reloads for a term the user is still typing.
 */
function SearchSectionView({ section }: Readonly<{ section: SearchSection }>) {
    const [term, setTerm] = useState(section.value);

    // Re-sync when the server reports a different term (e.g. Reset was hit).
    const [lastValue, setLastValue] = useState(section.value);
    if (section.value !== lastValue) {
        setLastValue(section.value);
        setTerm(section.value);
    }

    return (
        <div className="border-b border-line/60 px-3 py-3 last:border-b-0">
            <div className="mb-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                Cari nama lari
            </div>
            <div className="relative">
                <Icon
                    icon="mdi:magnify"
                    width={15}
                    height={15}
                    aria-hidden
                    className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-3"
                />
                <input
                    type="search"
                    value={term}
                    onChange={(e) => setTerm(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            section.onSubmit(term);
                        }
                    }}
                    onBlur={() => term !== section.value && section.onSubmit(term)}
                    placeholder="Misal: Pagi santai"
                    aria-label="Cari nama lari"
                    className="focus-ring min-h-11 w-full rounded-lg border border-line/60 bg-surface-warm py-2 pl-8 pr-2 text-xs text-ink placeholder:text-ink-3 lg:text-sm"
                />
            </div>
        </div>
    );
}

/**
 * A titled list of buttons where at most one option is active. Backs both the
 * distance and sort sections, which render identically and differ only in
 * section title and value type.
 */
function OptionListSectionView<T extends string>({
    title,
    section,
}: Readonly<{ title: string; section: { value: T | null; options: ReadonlyArray<{ value: T; label: string; hint?: string }>; onSelect: (value: T) => void } }>) {
    return (
        <div className="border-b border-line/60 px-3 py-3 last:border-b-0">
            <div className="mb-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                {title}
            </div>
            <div className="flex flex-col gap-1">
                {section.options.map((opt) => {
                    const active = opt.value === section.value;
                    return (
                        <button
                            key={opt.value}
                            type="button"
                            aria-pressed={active}
                            onClick={() => section.onSelect(opt.value)}
                            className={cn(
                                'focus-ring flex min-h-11 w-full items-center justify-between rounded-lg px-2 py-2 text-left text-xs transition lg:text-sm',
                                active ? 'bg-sky/10 font-semibold text-sky' : 'text-ink hover:bg-surface-warm',
                            )}
                        >
                            <span>{opt.label}</span>
                            {opt.hint && (
                                <span className="font-mono text-[11px] text-ink-3">{opt.hint}</span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function buildSummary<V extends string, B extends string, S extends string>(
    range: RangeSection<V> | undefined,
    moodActive: number,
    distance: DistanceSection<B> | undefined,
    searchActive: boolean,
    sort: SortSection<S> | undefined,
): string {
    const parts: string[] = [];
    if (sort && sort.options.length > 0 && sort.value !== sort.options[0].value) {
        const current = sort.options.find((o) => o.value === sort.value);
        if (current) parts.push(current.label);
    }
    if (range) {
        const current = range.options.find((o) => o.value === range.value);
        if (current) parts.push(current.label);
    }
    if (distance?.value != null) {
        const band = distance.options.find((o) => o.value === distance.value);
        if (band) parts.push(band.hint ?? band.label);
    }
    if (moodActive > 0) {
        parts.push(`${moodActive} mood`);
    }
    if (searchActive) {
        parts.push('cari');
    }
    return parts.length > 0 ? parts.join(' · ') : 'Filter';
}
