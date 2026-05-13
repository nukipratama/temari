import { useEffect, useState, type RefObject } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import TemariMascot from './TemariMascot';
import type { Mood } from '@/types/inertia';

interface TemariFollowProps {
    /** Element that, while in the viewport, keeps the follow-mascot hidden. */
    sentinelRef: RefObject<HTMLElement | null>;
    mood: Mood;
    sigilPattern?: string;
}

/**
 * Floating mini mascot pinned to the bottom-right. Hidden whenever the
 * sentinel (the dashboard hero) is on screen — only appears after the
 * user has scrolled past it. Click scrolls smoothly back to the top so
 * it doubles as a "back to briefing" affordance.
 *
 * Small (`h-12 w-12`) on purpose so it stays out of the way. Honours
 * reduced-motion via FM's internal handling on the entry transition.
 * Hidden under `lg:hidden`? No — we keep it cross-device because the
 * scroll-to-top utility is useful on mobile too. Repositioned above
 * the iOS safe area via `bottom-6`.
 */
export default function TemariFollow({ sentinelRef, mood, sigilPattern = 'dddd' }: Readonly<TemariFollowProps>) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = sentinelRef.current;
        if (el === null) return;
        if (typeof IntersectionObserver === 'undefined') return;

        const observer = new IntersectionObserver(
            ([entry]) => setVisible(!entry.isIntersecting),
            { threshold: 0, rootMargin: '-80px 0px 0px 0px' },
        );
        observer.observe(el);
        return () => observer.disconnect();
    }, [sentinelRef]);

    const scrollToTop = () => {
        globalThis.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <AnimatePresence>
            {visible && (
                <motion.button
                    type="button"
                    onClick={scrollToTop}
                    aria-label="Kembali ke briefing Temari"
                    initial={{ opacity: 0, scale: 0.7, x: 24 }}
                    animate={{ opacity: 1, scale: 1, x: 0 }}
                    exit={{ opacity: 0, scale: 0.7, x: 24 }}
                    transition={{ duration: 0.25, ease: 'easeOut' }}
                    className="fixed bottom-6 right-6 z-30 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    <TemariMascot
                        mood={mood}
                        sigilPattern={sigilPattern}
                        sizeClass="h-12 w-12"
                        sigilPixels={48}
                        ringClass="ring-2 ring-offset-2 ring-offset-surface dark:ring-offset-surface-dark"
                        idle="breath"
                    />
                </motion.button>
            )}
        </AnimatePresence>
    );
}
