import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState } from 'react';

import type { SharedProps, UnlockFlash } from '@/types/inertia';

import ConfettiBurst from '@/components/ConfettiBurst';
import { Icon } from '@/components/ui/Icon';

const DISMISS_MS = 5000;

export default function UnlockToast() {
    const { props } = usePage<SharedProps>();
    const unlock = props.flash?.unlock ?? null;
    const [active, setActive] = useState<UnlockFlash | null>(() =>
        unlock !== null && !unlock.is_major ? unlock : null,
    );
    const [lastUnlock, setLastUnlock] = useState(unlock);

    // Show the toast when a new (non-major) unlock flash arrives — adjusted during
    // render (React-endorsed) so the sync setState isn't inside an effect.
    if (unlock !== lastUnlock) {
        setLastUnlock(unlock);
        if (unlock !== null && !unlock.is_major) {
            setActive(unlock);
        }
    }

    useEffect(() => {
        if (active === null) return;
        const t = window.setTimeout(() => setActive(null), DISMISS_MS);
        return () => window.clearTimeout(t);
    }, [active]);

    return (
        <>
            <ConfettiBurst burstKey={active?.unlock_key ?? null} count={20} />
            <AnimatePresence>
                {active && (
                    <motion.div
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: 10 }}
                        className="fixed bottom-[calc(5.5rem+env(safe-area-inset-bottom))] left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg border border-citrus/25 bg-popover px-5 py-3 shadow-e2 lg:bottom-6"
                        role="status"
                    >
                        <Icon
                            icon={active.icon}
                            width={24}
                            height={24}
                            className="text-citrus-ink"
                            aria-hidden
                        />
                        <div>
                            <div className="font-mono text-xs font-bold uppercase tracking-wider text-text-2">
                                New unlock
                            </div>
                            <div className="text-sm font-semibold text-foreground">
                                {active.name}
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => setActive(null)}
                            aria-label="Dismiss notification"
                            className="focus-ring ml-2 rounded-full p-1 text-text-3 hover:bg-line/40 hover:text-foreground"
                        >
                            <Icon
                                icon="mdi:close"
                                width={14}
                                height={14}
                                aria-hidden
                            />
                        </button>
                    </motion.div>
                )}
            </AnimatePresence>
        </>
    );
}
