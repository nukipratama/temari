import { Icon } from '@iconify/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
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

    useEffect(() => {
        if (!open) return;

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') close();
        };
        const onPointer = (e: PointerEvent) => {
            if (!containerRef.current?.contains(e.target as Node)) close();
        };

        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onPointer);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onPointer);
        };
    }, [open, close]);

    const iconSize = size === 'xs' ? 12 : 14;
    const buttonClass =
        size === 'xs'
            ? 'inline-flex h-4 w-4 items-center justify-center rounded-full text-ink-meta transition hover:bg-line/60 hover:text-ink'
            : 'inline-flex h-5 w-5 items-center justify-center rounded-full text-ink-meta transition hover:bg-line/60 hover:text-ink';

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
                        initial={{ opacity: 0, y: -4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -4 }}
                        transition={{ duration: 0.15 }}
                        className="absolute left-1/2 top-full z-30 mt-2 w-56 max-w-[min(16rem,calc(100vw-2rem))] -translate-x-1/2 rounded-xl border border-line bg-surface-elev p-3 text-left shadow-lg"
                    >
                        <div className="text-xs font-semibold uppercase tracking-wider text-ink-meta">
                            {entry.acronym ? `${entry.label} · ${entry.acronym}` : entry.label}
                        </div>
                        <p className="mt-1.5 text-sm leading-relaxed text-ink">{entry.body}</p>
                    </motion.div>
                )}
            </AnimatePresence>
        </span>
    );
}
