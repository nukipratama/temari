import { CircleQuestionMark, Lightbulb } from 'lucide-react';
import { useCallback, useId, useLayoutEffect, useRef, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { useExitTransition } from '@/hooks/useExitTransition';
import { usePopover } from '@/hooks/usePopover';
import { cn } from '@/lib/cn';
import {
    METRIC_GLOSSARY,
    type MetricGlossaryEntry,
    type MetricKey,
} from '@/lib/metricGlossary';

const EXIT_MS = 150;

interface MetricExplainerProps {
    metricKey: MetricKey;
    /** Visual size of the question-mark trigger button. Default `sm` for KPI labels. */
    size?: 'xs' | 'sm';
    className?: string;
}

type PopoverAlign = 'center' | 'left' | 'right';

// Matches the page's own gutter — the popover should never touch the edge.
const EDGE_MARGIN = 16;

const ALIGN_CLASS: Record<PopoverAlign, string> = {
    center: 'left-1/2 -translate-x-1/2',
    left: 'left-0',
    right: 'right-0',
};

/** Flips the popover off center-under-trigger when its own rendered rect
 *  already overflows the viewport — measured after the (still-centered)
 *  first paint, rather than predicted from the trigger's position, since
 *  the popover centers on its own containing block, not the viewport. A
 *  trigger near either edge of the page (a narrow tile column, a
 *  left-aligned label) otherwise clips the popover off-screen. */
function correctedAlign(
    popoverRect: DOMRect,
    viewportWidth: number,
): PopoverAlign | null {
    if (popoverRect.left < EDGE_MARGIN) return 'left';
    if (popoverRect.right > viewportWidth - EDGE_MARGIN) return 'right';
    return null;
}

/**
 * Inline `(?)` trigger button + floating popover with a 1-2 sentence
 * explanation pulled from {@link METRIC_GLOSSARY}. Use next
 * to any sport-science label (CTL, ATL, TRIMP, HR zones, status chips,
 * etc.) so beginners aren't left guessing what the term means.
 *
 * Dismissal: Esc, click outside, or tap the trigger again.
 */
export default function MetricExplainer({
    metricKey,
    size = 'sm',
    className,
}: Readonly<MetricExplainerProps>) {
    const entry: MetricGlossaryEntry = METRIC_GLOSSARY[metricKey];
    const [open, setOpen] = useState(false);
    const [align, setAlign] = useState<PopoverAlign>('center');
    const containerRef = useRef<HTMLSpanElement>(null);
    const popoverRef = useRef<HTMLDivElement>(null);
    const popoverId = useId();

    // Resets to center on close (not on open) so a stale left/right
    // correction from a previous position (the trigger can move between
    // opens, e.g. a range chip reflowing the layout) is already gone by the
    // time the next open's measurement effect below reads the DOM — doing
    // the reset at open time instead would race that same-commit measurement
    // against a class update that hasn't painted yet. Done in the close
    // handlers themselves (not an effect keyed on `open`), since resetting
    // one piece of state in reaction to another is exactly what an event
    // handler is for.
    const close = useCallback(() => {
        setOpen(false);
        setAlign('center');
    }, []);
    usePopover(open, containerRef, close);

    const { rendered, closing } = useExitTransition(open, EXIT_MS);

    // Measures the popover's own rect once it has painted centered, and
    // flips it exactly once if that overflows — no correction loop, since
    // `left`/`right` both pin to the trigger's own edge rather than a second
    // viewport-relative computation that could overflow again.
    useLayoutEffect(() => {
        if (!open || !popoverRef.current) return;
        const corrected = correctedAlign(
            popoverRef.current.getBoundingClientRect(),
            window.innerWidth,
        );
        if (corrected !== null) setAlign(corrected);
    }, [open]);

    const iconSize = size === 'xs' ? 12 : 14;
    // Negative margins preserve the label row's original footprint.
    const buttonClass = cn(
        "relative focus-ring pressable inline-flex h-6 w-6 items-center justify-center rounded-full text-text-3 transition hover:bg-muted hover:text-foreground before:absolute before:-inset-2.5 before:content-['']",
        size === 'xs' ? '-m-1' : '-m-0.5',
    );

    return (
        <span
            ref={containerRef}
            className={cn('relative inline-flex align-middle', className)}
        >
            <button
                type="button"
                onClick={() => (open ? close() : setOpen(true))}
                aria-haspopup="dialog"
                aria-label={`Explain ${entry.label}`}
                aria-expanded={open}
                aria-controls={open ? popoverId : undefined}
                className={buttonClass}
            >
                <Icon
                    icon={CircleQuestionMark}
                    width={iconSize}
                    height={iconSize}
                    aria-hidden
                />
            </button>

            {rendered && (
                <div
                    ref={popoverRef}
                    id={popoverId}
                    role="dialog"
                    aria-label={entry.label}
                    data-closing={closing ? '' : undefined}
                    data-align={align}
                    className={cn(
                        'popover-reveal absolute top-full z-30 mt-2 w-64 max-w-[min(18rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-leaf/40 bg-popover text-left normal-case shadow-e2 ring-1 ring-leaf/15',
                        ALIGN_CLASS[align],
                    )}
                >
                    <div
                        aria-hidden
                        className="absolute inset-y-0 left-0 w-1 bg-leaf"
                    />
                    <div className="px-3.5 py-3 pl-4">
                        <div className="flex items-center gap-1.5 font-mono text-[0.6875rem] font-semibold uppercase tracking-wider text-leaf-ink">
                            <Icon
                                icon={Lightbulb}
                                width={12}
                                height={12}
                                aria-hidden
                            />
                            <span>
                                {entry.acronym
                                    ? `${entry.label} · ${entry.acronym}`
                                    : entry.label}
                            </span>
                        </div>
                        <p className="mt-1.5 text-sm leading-relaxed text-foreground">
                            {entry.body}
                        </p>
                    </div>
                </div>
            )}
        </span>
    );
}
