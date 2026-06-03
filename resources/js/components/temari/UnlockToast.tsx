import { AnimatePresence, motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import ConfettiBurst from '@/components/ConfettiBurst';
import type { SharedProps } from '@/types/inertia';

interface UnlockFlash {
    unlock_key: string;
    name: string;
    icon: string;
    is_major: boolean;
}

interface UnlockFlashProps extends SharedProps {
    flash: SharedProps['flash'] & { unlock?: UnlockFlash | null };
}

const DISMISS_MS = 5000;

export default function UnlockToast() {
    const { props } = usePage<UnlockFlashProps>();
    const unlock = props.flash?.unlock ?? null;
    const [active, setActive] = useState<UnlockFlash | null>(null);

    useEffect(() => {
        if (unlock === null || unlock.is_major) return;
        setActive(unlock);
        const t = window.setTimeout(() => setActive(null), DISMISS_MS);
        return () => window.clearTimeout(t);
    }, [unlock]);

    return (
        <>
            <ConfettiBurst burstKey={active?.unlock_key ?? null} count={20} />
            <AnimatePresence>
                {active && (
                    <motion.div
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: 10 }}
                        className="fixed bottom-6 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-2xl border border-citrus/25 bg-surface-elev px-5 py-3 shadow-lg"
                        role="status"
                    >
                        <Icon icon={active.icon} width={24} height={24} className="text-citrus-deep" aria-hidden />
                        <div>
                            <div className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">Unlock baru</div>
                            <div className="text-sm font-semibold text-ink">{active.name}</div>
                        </div>
                        <button
                            type="button"
                            onClick={() => setActive(null)}
                            aria-label="Tutup notifikasi"
                            className="focus-ring ml-2 rounded-full p-1 text-ink-3 hover:bg-line/40 hover:text-ink"
                        >
                            <Icon icon="mdi:close" width={14} height={14} aria-hidden />
                        </button>
                    </motion.div>
                )}
            </AnimatePresence>
        </>
    );
}
