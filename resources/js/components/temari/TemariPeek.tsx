import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';

interface TemariPeekProps {
    lines: ReadonlyArray<string>;
    delayMs?: number;
    visibleMs?: number;
}

const SHOWN_THIS_SESSION_KEY = 'tl.temari.peek.shown';

// Absolutely positioned — parent must be `position: relative`.
// sessionStorage (not local) so a fresh tab gets a fresh peek.
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
                    className="pointer-events-none absolute -right-2 top-0 z-10 max-w-[160px] -translate-y-2 rounded-2xl rounded-bl-sm border border-line bg-surface-elev px-3 py-2 text-xs leading-relaxed text-ink shadow-md sm:right-0 sm:translate-x-6"
                >
                    {line}
                </motion.div>
            )}
        </AnimatePresence>
    );
}
