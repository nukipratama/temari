import { useEffect, useState, type ReactNode } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/inertia';

interface PageOnboardingTooltipProps {
    /** Per-page slug. Used to build the storage key per user. */
    pageKey: string;
    /** Optional single emoji rendered before the title (D5). */
    icon?: string;
    title: string;
    children: ReactNode;
    /** Optional override className for outer wrapper margin/positioning. */
    className?: string;
}

/**
 * One reusable tooltip, instanced once per major page (HariIni, Koleksi,
 * Riwayat, Aku, Runs/Show). Shows on first visit per user, dismissable.
 *
 * Storage key: `tl.onboarding.${userId}.${pageKey}.done`.
 * Honors `ONBOARDING_FORCE_SHOW` env (session-only dismissal in force mode).
 */
export default function PageOnboardingTooltip({
    pageKey,
    icon,
    title,
    children,
    className,
}: Readonly<PageOnboardingTooltipProps>) {
    const { props } = usePage<SharedProps>();
    const userId = props.auth.user?.id ?? null;
    const forceShow = props.onboarding.forceShow;
    const storageKey = userId !== null ? `tl.onboarding.${userId}.${pageKey}.done` : null;

    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (storageKey === null) return;
        if (forceShow) {
            setVisible(true);
            return;
        }
        const done = globalThis.localStorage?.getItem(storageKey) === '1';
        if (!done) setVisible(true);
    }, [storageKey, forceShow]);

    const dismiss = () => {
        setVisible(false);
        if (!forceShow && storageKey !== null) {
            globalThis.localStorage?.setItem(storageKey, '1');
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
                    className={`relative mb-6 flex items-start gap-3 rounded-2xl border border-leaf/25 bg-leaf/10 p-4 ${className ?? ''}`}
                >
                    {icon !== undefined && (
                        <span
                            aria-hidden
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-leaf/20 text-lg"
                        >
                            {icon}
                        </span>
                    )}
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-ink">{title}</p>
                        <div className="mt-1 text-sm leading-relaxed text-ink">{children}</div>
                        <button
                            type="button"
                            onClick={dismiss}
                            className="mt-3 inline-flex items-center gap-1 rounded-lg bg-leaf px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-leaf-deep focus:outline-none focus:ring-4 focus:ring-leaf/30"
                        >
                            Sip, ngerti
                        </button>
                    </div>
                    <button
                        type="button"
                        onClick={dismiss}
                        aria-label="Tutup"
                        className="absolute right-2.5 top-2.5 inline-flex h-7 w-7 items-center justify-center rounded-full text-ink-3 hover:bg-leaf/20 hover:text-ink-2"
                    >
                        <Icon icon="mdi:close" width={16} height={16} aria-hidden />
                    </button>
                </motion.aside>
            )}
        </AnimatePresence>
    );
}
