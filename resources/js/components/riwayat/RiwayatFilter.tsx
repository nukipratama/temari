import { Icon } from '@iconify/react';
import { useCallback, useRef, useState } from 'react';
import { useDismissable } from '@/hooks/useDismissable';
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
    /** Called when the user picks a different value. */
    onChange: (next: V) => void;
}

interface MoodSection {
    selected: ReadonlySet<Mood>;
    options: ReadonlyArray<MoodOption>;
    onToggle: (mood: Mood) => void;
}

interface RiwayatFilterProps<V extends string> {
    range?: RangeSection<V>;
    mood?: MoodSection;
    /** When the user hits Reset — clears every filter set this component owns. */
    onReset?: () => void;
    className?: string;
}

/**
 * Single compact filter trigger used on both Linimasa and Kalender. Replaces
 * a row-spanning chip strip with one button + popover, so the page title and
 * tabs stay the visual anchor and the filter is opt-in.
 *
 * Active-filter count is surfaced as a badge on the button so the user can
 * see at a glance whether they're looking at a filtered slice.
 */
export default function RiwayatFilter<V extends string>({
    range,
    mood,
    onReset,
    className,
}: Readonly<RiwayatFilterProps<V>>) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismissable(open, containerRef, close);

    const moodActive = mood?.selected.size ?? 0;
    // Range counts as "active" only when the user picked something other than
    // the first (most-recent) option — that's the implicit default.
    const rangeActive =
        range && range.options.length > 0 && range.value !== range.options[0].value ? 1 : 0;
    const totalActive = moodActive + rangeActive;
    const summary = buildSummary(range, moodActive);

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Buka filter"
                className={cn(
                    'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-medium transition lg:text-sm',
                    totalActive > 0
                        ? 'border-sky/40 bg-sky/[0.06] text-sky'
                        : 'border-line/60 bg-surface-elev text-ink-2 hover:bg-surface-warm',
                )}
            >
                <Icon icon="mdi:tune-variant" width={14} height={14} aria-hidden />
                <span>{summary}</span>
                {totalActive > 0 && (
                    <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-sky px-1 font-mono text-[10px] font-bold text-cream">
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
            {open && (
                <div
                    role="menu"
                    className="absolute right-0 top-[calc(100%+8px)] z-40 w-72 overflow-hidden rounded-2xl border border-line bg-surface-elev shadow-lg"
                >
                    {(totalActive > 0 || onReset) && (
                        <div className="flex items-center justify-between border-b border-line/60 px-3 py-2">
                            <span className="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-3">
                                Filter
                            </span>
                            {totalActive > 0 && onReset && (
                                <button
                                    type="button"
                                    onClick={onReset}
                                    className="text-[11px] font-medium text-sky hover:underline"
                                >
                                    Reset
                                </button>
                            )}
                        </div>
                    )}
                    {range && <RangeSectionView section={range} />}
                    {mood && <MoodSectionView section={mood} />}
                </div>
            )}
        </div>
    );
}

function RangeSectionView<V extends string>({ section }: Readonly<{ section: RangeSection<V> }>) {
    return (
        <div className="border-b border-line/60 px-3 py-3 last:border-b-0">
            <div className="mb-2 font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-3">
                Rentang waktu
            </div>
            <div className="flex flex-col gap-1">
                {section.options.map((opt) => {
                    const active = opt.value === section.value;
                    return (
                        <button
                            key={opt.value}
                            type="button"
                            role="menuitemradio"
                            aria-checked={active}
                            onClick={() => section.onChange(opt.value)}
                            className={cn(
                                'flex w-full items-baseline justify-between rounded-lg px-2 py-1.5 text-left text-xs transition lg:text-sm',
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

function MoodSectionView({ section }: Readonly<{ section: MoodSection }>) {
    return (
        <div className="border-b border-line/60 px-3 py-3 last:border-b-0">
            <div className="mb-2 font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-3">
                Mood
            </div>
            <div className="grid grid-cols-2 gap-1">
                {section.options.map(({ mood, label, swatchClass }) => {
                    const active = section.selected.has(mood);
                    return (
                        <button
                            key={mood}
                            type="button"
                            role="menuitemcheckbox"
                            aria-checked={active}
                            onClick={() => section.onToggle(mood)}
                            className={cn(
                                'flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs font-medium transition',
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

function buildSummary<V extends string>(range: RangeSection<V> | undefined, moodActive: number): string {
    const parts: string[] = [];
    if (range) {
        const current = range.options.find((o) => o.value === range.value);
        if (current) parts.push(current.label);
    }
    if (moodActive > 0) {
        parts.push(`${moodActive} mood`);
    }
    return parts.length > 0 ? parts.join(' · ') : 'Filter';
}
