import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';

interface TemariPeekProps {
    /** Lines to choose from; one is picked at random on first appearance. */
    lines: ReadonlyArray<string>;
    /** Delay before the bubble first appears (ms). Default 12s. */
    delayMs?: number;
    /** How long the bubble stays before hiding itself (ms). Default 5s. */
    visibleMs?: number;
}

const SHOWN_THIS_SESSION_KEY = 'tl.temari.peek.shown';

/**
 * Occasional one-line peek bubble next to the mascot — Temari "saying"
 * something brief without the user having to click. Fires once per
 * session storage scope (not localStorage — fresh tab = fresh peek)
 * after a delay, then hides. Skipped under `prefers-reduced-motion`.
 *
 * Anchored absolutely to the mascot container, so the parent must be
 * `position: relative`. Default tuning (12s delay, 5s visible) keeps it
 * a "huh, nice" moment rather than a Clippy-style nag.
 */
export default function TemariPeek({ lines, delayMs = 12000, visibleMs = 5000 }: Readonly<TemariPeekProps>) {
    const [visible, setVisible] = useState(false);
    const [line] = useState(() => lines[Math.floor(Math.random() * lines.length)] ?? null);

    useEffect(() => {
        if (line === null) return;
        if (typeof window === 'undefined') return;
        if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
        if (globalThis.sessionStorage?.getItem(SHOWN_THIS_SESSION_KEY) === '1') return;

        const showTimer = setTimeout(() => {
            setVisible(true);
            globalThis.sessionStorage?.setItem(SHOWN_THIS_SESSION_KEY, '1');
        }, delayMs);
        return () => clearTimeout(showTimer);
    }, [line, delayMs]);

    useEffect(() => {
        if (!visible) return;
        const hideTimer = setTimeout(() => setVisible(false), visibleMs);
        return () => clearTimeout(hideTimer);
    }, [visible, visibleMs]);

    if (line === null) return null;

    return (
        <AnimatePresence>
            {visible && (
                <motion.div
                    role="status"
                    aria-live="polite"
                    initial={{ opacity: 0, scale: 0.8, y: 6 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={{ opacity: 0, scale: 0.85, y: 4 }}
                    transition={{ duration: 0.25, ease: 'easeOut' }}
                    className="pointer-events-none absolute -right-2 top-0 z-10 max-w-[160px] -translate-y-2 rounded-2xl rounded-bl-sm border border-line bg-surface-elev px-3 py-2 text-xs leading-relaxed text-ink shadow-md dark:border-line-dark dark:bg-surface-dark-elev dark:text-ink-dark sm:right-0 sm:translate-x-6"
                >
                    {line}
                </motion.div>
            )}
        </AnimatePresence>
    );
}
