import { Icon } from '@iconify/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useId, useRef, useState } from 'react';
import { useDismissable } from '@/hooks/useDismissable';
import { METRIC_GLOSSARY, type MetricGlossaryEntry, type MetricKey } from '@/lib/metricGlossary';
import { cn } from '@/lib/cn';

interface MetricExplainerProps {
    metricKey: MetricKey;
    /** Visual size of the question-mark trigger button. Default `sm` for KPI labels. */
    size?: 'xs' | 'sm';
    className?: string;
}

/**
 * Inline `(?)` trigger button + floating popover with a 1-2 sentence
 * Indonesian explanation pulled from {@link METRIC_GLOSSARY}. Use next
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
    useDismissable(open, containerRef, close);

    const iconSize = size === 'xs' ? 12 : 14;
    const buttonClass =
        size === 'xs'
            ? 'inline-flex h-4 w-4 items-center justify-center rounded-full text-ink-3 transition hover:bg-line/60 hover:text-ink'
            : 'inline-flex h-5 w-5 items-center justify-center rounded-full text-ink-3 transition hover:bg-line/60 hover:text-ink';

    return (
        <span ref={containerRef} className={cn('relative inline-flex align-middle', className)}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label={`Penjelasan ${entry.label}`}
                aria-expanded={open}
                aria-controls={open ? popoverId : undefined}
                className={buttonClass}
            >
                <Icon icon="mdi:help-circle-outline" width={iconSize} height={iconSize} aria-hidden />
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
                        className="absolute left-1/2 top-full z-30 mt-2 w-64 max-w-[min(18rem,calc(100vw-2rem))] -translate-x-1/2 overflow-hidden rounded-xl border border-leaf/25 bg-gradient-to-br from-surface-warm via-surface-elev to-leaf/10 text-left normal-case shadow-xl ring-1 ring-leaf/15"
                    >
                        <div aria-hidden className="absolute inset-y-0 left-0 w-1 bg-leaf" />
                        <div className="px-3.5 py-3 pl-4">
                            <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-leaf-deep">
                                <Icon icon="mdi:lightbulb-on-outline" width={12} height={12} aria-hidden />
                                <span>{entry.acronym ? `${entry.label} · ${entry.acronym}` : entry.label}</span>
                            </div>
                            <p className="mt-1.5 text-sm leading-relaxed text-ink">{entry.body}</p>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </span>
    );
}
