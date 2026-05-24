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
                    className="mb-6 flex items-start gap-3 rounded-2xl border border-leaf/25 bg-leaf/10 p-4"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-leaf/20 text-leaf-deep">
                        <Icon icon="mdi:hand-wave" width={20} height={20} aria-hidden />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-ink">Halo! Strava kamu sudah tersambung.</p>
                        <p className="mt-1 text-sm leading-relaxed text-ink">
                            Begitu lari pertama kamu masuk, aku akan mulai menemani di sini. Sinkronisasi berjalan otomatis setiap jam.
                        </p>
                        <button
                            type="button"
                            onClick={dismiss}
                            className="mt-3 inline-flex items-center gap-1 rounded-lg bg-leaf px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-leaf-deep focus:outline-none focus:ring-4 focus:ring-leaf/30"
                        >
                            Baik, ditunggu
                        </button>
                    </div>
                </motion.aside>
            )}
        </AnimatePresence>
    );
}
