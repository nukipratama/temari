import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useId, useRef, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { usePopover } from '@/hooks/usePopover';
import { cn } from '@/lib/cn';
import {
    METRIC_GLOSSARY,
    type MetricGlossaryEntry,
    type MetricKey,
} from '@/lib/metricGlossary';

interface MetricExplainerProps {
    metricKey: MetricKey;
    /** Visual size of the question-mark trigger button. Default `sm` for KPI labels. */
    size?: 'xs' | 'sm';
    className?: string;
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
    const containerRef = useRef<HTMLSpanElement>(null);
    const popoverId = useId();

    const close = useCallback(() => setOpen(false), []);
    usePopover(open, containerRef, close);

    const iconSize = size === 'xs' ? 12 : 14;
    // The box is 24px (WCAG 2.5.8) while negative margins keep the glyph's
    // 16/20px footprint in the line, so no label row reflows.
    const buttonClass =
        size === 'xs'
            ? 'focus-ring -m-1 inline-flex h-6 w-6 items-center justify-center rounded-full text-text-3 transition hover:bg-muted hover:text-foreground'
            : 'focus-ring -m-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full text-text-3 transition hover:bg-muted hover:text-foreground';

    return (
        <span
            ref={containerRef}
            className={cn('relative inline-flex align-middle', className)}
        >
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label={`Explain ${entry.label}`}
                aria-expanded={open}
                aria-controls={open ? popoverId : undefined}
                className={buttonClass}
            >
                <Icon
                    icon="mdi:help-circle-outline"
                    width={iconSize}
                    height={iconSize}
                    aria-hidden
                />
            </button>

            <AnimatePresence>
                {open && (
                    <motion.div
                        id={popoverId}
                        role="dialog"
                        aria-label={entry.label}
                        initial={{ opacity: 0, y: -4, scale: 0.97 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -4, scale: 0.97 }}
                        transition={{ duration: 0.15 }}
                        className="absolute left-1/2 top-full z-30 mt-2 w-64 max-w-[min(18rem,calc(100vw-2rem))] -translate-x-1/2 overflow-hidden rounded-xl border border-leaf/25 bg-gradient-to-br from-surface-warm to-surface-elev text-left normal-case shadow-e2 ring-1 ring-leaf/15"
                    >
                        <div
                            aria-hidden
                            className="absolute inset-y-0 left-0 w-1 bg-leaf"
                        />
                        <div className="px-3.5 py-3 pl-4">
                            <div className="flex items-center gap-1.5 font-mono text-[0.6875rem] font-semibold uppercase tracking-wider text-leaf-ink">
                                <Icon
                                    icon="mdi:lightbulb-on-outline"
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
                    </motion.div>
                )}
            </AnimatePresence>
        </span>
    );
}
