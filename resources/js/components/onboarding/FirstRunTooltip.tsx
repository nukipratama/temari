import { useEffect, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/inertia';

const STORAGE_KEY = 'tl.onboarding.dismissed';

interface FirstRunTooltipProps {
    recentRunCount: number;
}

export default function FirstRunTooltip({ recentRunCount }: Readonly<FirstRunTooltipProps>) {
    const { props } = usePage<SharedProps>();
    const forceShow = props.onboarding.forceShow;
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (forceShow) {
            setVisible(true);
            return;
        }
        if (recentRunCount > 0) return;
        const dismissed = globalThis.localStorage?.getItem(STORAGE_KEY) === '1';
        if (!dismissed) setVisible(true);
    }, [forceShow, recentRunCount]);

    const dismiss = () => {
        setVisible(false);
        // In force mode, dismissal is session-only — don't persist.
        if (!forceShow) {
            globalThis.localStorage?.setItem(STORAGE_KEY, '1');
        }
    };

    return (
        <AnimatePresence>
            {visible && (
                <motion.aside
                    initial={{ opacity: 0, y: -8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -8 }}
                    transition={{ duration: 0.25, ease: 'easeOut' }}
                    role="status"
                    aria-live="polite"
                    className="mb-6 flex items-start gap-3 rounded-2xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-700 dark:bg-brand-900/40"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500/20 text-brand-700 dark:text-brand-300">
                        <Icon icon="mdi:hand-wave" width={20} height={20} aria-hidden />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-ink dark:text-ink-dark">Hai! Strava udah nyambung.</p>
                        <p className="mt-1 text-sm leading-relaxed text-ink dark:text-ink-dark">
                            Begitu lari pertama lo masuk, Temari bakal mulai temenin di sini. Sync jalan otomatis tiap jam.
                        </p>
                        <button
                            type="button"
                            onClick={dismiss}
                            className="mt-3 inline-flex items-center gap-1 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-500/30"
                        >
                            Oke, ditunggu
                        </button>
                    </div>
                </motion.aside>
            )}
        </AnimatePresence>
    );
}
