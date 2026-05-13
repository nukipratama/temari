import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { MASCOT_GRADIENT, moodRing } from '@/lib/mood';
import { formatIdDate } from '@/lib/pace';
import DegradedChip from './DegradedChip';
import type { VerdictTimelineItem } from '@/types/inertia';

interface VerdictStripProps {
    items: VerdictTimelineItem[];
}

const SCROLL_STEP = 280; // approx card width (w-64 = 256px) + gap-3 (12px) + slack

/**
 * Horizontal scroller — "Kata Temari" recent verdicts. Scrolling via:
 *   - left/right arrow buttons (fade in/out based on edge state)
 *   - touch swipe (native, mobile)
 *   - shift+wheel (native, desktop)
 * Native scrollbar hidden — arrows + swipe + wheel are the affordance.
 */
export default function VerdictStrip({ items }: Readonly<VerdictStripProps>) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const [canLeft, setCanLeft] = useState(false);
    const [canRight, setCanRight] = useState(false);

    useEffect(() => {
        const el = scrollerRef.current;
        if (el === null) return;

        // rAF-throttle so a fast swipe doesn't fire a React re-render on every
        // scroll event.
        let frame = 0;
        const update = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                setCanLeft(el.scrollLeft > 0);
                setCanRight(el.scrollLeft + el.clientWidth < el.scrollWidth - 1);
            });
        };
        update();
        el.addEventListener('scroll', update, { passive: true });
        globalThis.addEventListener('resize', update);
        return () => {
            cancelAnimationFrame(frame);
            el.removeEventListener('scroll', update);
            globalThis.removeEventListener('resize', update);
        };
    }, []);

    if (items.length === 0) return null;

    const scrollBy = (dir: 'left' | 'right') => {
        scrollerRef.current?.scrollBy({ left: dir === 'left' ? -SCROLL_STEP : SCROLL_STEP, behavior: 'smooth' });
    };

    return (
        <section className="mt-6">
            <div className="flex items-baseline justify-between">
                <h2 className="text-lg font-bold tracking-tight">Kata Temari</h2>
                <span className="text-xs text-ink-soft dark:text-ink-soft-dark">{items.length} run terakhir</span>
            </div>

            {/* `-mx-6` on the wrapper (not the scroller) so the arrow buttons,
                which are absolutely positioned inside, cover the FULL visible
                width including the negative-margin bleed at the page edges.
                Otherwise cards in the bleed area peek past the arrows. */}
            <div className="relative -mx-6 mt-3">
                <ArrowButton dir="left" onClick={() => scrollBy('left')} visible={canLeft} />
                <ArrowButton dir="right" onClick={() => scrollBy('right')} visible={canRight} />

                <div ref={scrollerRef} className="scrollbar-hide overflow-x-auto px-6">
                    <div className="flex gap-3 pb-1">
                        {items.map((item) => (
                            <Link
                                key={item.activityId}
                                href={`/runs/${item.activityId}`}
                                className="group flex w-64 shrink-0 flex-col gap-2 rounded-2xl border border-line bg-surface-elev p-4 transition hover:border-brand-400/60 hover:shadow-sm dark:border-line-dark dark:bg-surface-dark-elev dark:hover:border-brand-500/40"
                            >
                                <div className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'flex h-9 w-9 items-center justify-center rounded-full text-base ring-2',
                                            MASCOT_GRADIENT,
                                            moodRing(item.mood),
                                        )}
                                    >
                                        {item.moodFace}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-xs font-semibold text-ink dark:text-ink-dark">
                                            {item.distanceKm.toFixed(1)} km
                                        </div>
                                        <div className="text-[10px] uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                                            {formatIdDate(item.startedAt)}
                                        </div>
                                    </div>
                                    {item.degraded && <DegradedChip />}
                                </div>
                                <p className="line-clamp-3 text-xs leading-relaxed text-ink dark:text-ink-dark">{item.oneline}</p>
                            </Link>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function ArrowButton({
    dir,
    onClick,
    visible,
}: Readonly<{ dir: 'left' | 'right'; onClick: () => void; visible: boolean }>) {
    // Full-height column so the entire left/right edge of the strip is a
    // tappable target — accidental taps near a card's edge land here
    // instead of navigating into the card. Visual chevron-in-pill sits
    // centered inside; the surrounding gradient doubles as a fade hint
    // that there's more content past the edge.
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={dir === 'left' ? 'Scroll kiri' : 'Scroll kanan'}
            tabIndex={visible ? 0 : -1}
            className={cn(
                'group absolute inset-y-0 z-10 flex w-16 items-center justify-center transition focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-brand-500',
                dir === 'left'
                    ? 'left-0 bg-gradient-to-r from-surface via-surface/70 to-transparent dark:from-surface-dark dark:via-surface-dark/70'
                    : 'right-0 bg-gradient-to-l from-surface via-surface/70 to-transparent dark:from-surface-dark dark:via-surface-dark/70',
                visible ? 'opacity-100' : 'pointer-events-none opacity-0',
            )}
        >
            <Icon
                icon={dir === 'left' ? 'mdi:chevron-left' : 'mdi:chevron-right'}
                width={28}
                height={28}
                className="text-ink-soft transition group-hover:scale-110 group-hover:text-ink dark:text-ink-soft-dark dark:group-hover:text-ink-dark"
                aria-hidden
            />
        </button>
    );
}

