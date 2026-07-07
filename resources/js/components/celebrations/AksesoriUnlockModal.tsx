import { router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { useRef } from 'react';
import TemariProto from '@/components/temari/TemariProto';
import { keyToPreviewEquipped } from '@/lib/equippedAccessories';
import { useDismissable } from '@/hooks/useDismissable';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import type { UnlockFlash } from '@/types/inertia';

interface AksesoriUnlockModalProps {
    unlock: UnlockFlash | null;
    onClose: () => void;
}

export default function AksesoriUnlockModal({
    unlock,
    onClose,
}: Readonly<AksesoriUnlockModalProps>) {
    const equipped = unlock ? keyToPreviewEquipped(unlock.unlock_key) : { headband: 'epik' as const };
    const panelRef = useRef<HTMLDivElement>(null);
    const isOpen = unlock?.is_major === true;

    useDismissable(isOpen, panelRef, onClose);
    useFocusTrap(isOpen, panelRef);

    const handleEquip = () => {
        onClose();
        router.visit('/aksesori', { preserveScroll: false });
    };

    return (
        <AnimatePresence>
            {unlock !== null && unlock.is_major && <motion.div
                key="aksesori-backdrop"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
                style={{
                    background: 'rgba(0,0,0,0.55)',
                    backdropFilter: 'blur(4px)',
                }}
            >
                <motion.div
                    key="aksesori-panel"
                    ref={panelRef}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="aksesori-unlock-title"
                    initial={{ opacity: 0, y: 24, scale: 0.97 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: 16, scale: 0.97 }}
                    transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
                    className="relative w-full max-w-[390px] overflow-hidden rounded-t-3xl sm:rounded-3xl"
                    style={{
                        background:
                            'linear-gradient(170deg, var(--color-sky-deep) 0%, var(--color-sky) 45%, oklch(58% 0.10 38) 90%, var(--color-horizon-deep) 100%)',
                        color: 'var(--color-cream)',
                        padding: '56px 24px 32px',
                        display: 'flex',
                        flexDirection: 'column',
                    }}
                >
                    {/* Glow */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute bottom-[25%] left-1/2 h-80 w-80 -translate-x-1/2 rounded-full"
                        style={{
                            background:
                                'radial-gradient(circle, oklch(82% 0.14 55 / 0.5), transparent 60%)',
                            filter: 'blur(2px)',
                        }}
                    />

                    {/* Confetti dots */}
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-0 overflow-hidden"
                    >
                        {Array.from({ length: 14 }, (_, i) => {
                            const seed = ((i * 9301 + 49297) % 233280) / 233280;
                            const colors = [
                                'var(--color-horizon)',
                                'var(--color-citrus)',
                                'var(--color-cream)',
                            ];
                            const color = colors[Math.floor(seed * 3)];
                            return (
                                <span
                                    key={i}
                                    className="absolute rounded-sm opacity-85"
                                    style={{
                                        left: `${seed * 100}%`,
                                        top: `${((seed * 49) % 60) + 5}%`,
                                        width: 5 + seed * 6,
                                        height: 2 + seed * 3,
                                        background: color,
                                        transform: `rotate(${seed * 360}deg)`,
                                    }}
                                />
                            );
                        })}
                    </div>

                    {/* Headline */}
                    <div className="relative mt-5 text-center">
                        <div className="mb-3.5 font-mono text-[11px] font-bold uppercase tracking-[0.2em] text-horizon">
                            ★ Aksesori baru
                        </div>
                        <h2 id="aksesori-unlock-title" className="mb-6 font-display text-[36px] leading-[0.95] tracking-[-0.02em] text-cream">
                            <em className="italic text-horizon">{unlock.name}</em>
                            <br />
                            terbuka!
                        </h2>
                    </div>

                    {/* Mascot */}
                    <div className="relative mb-7 flex justify-center">
                        <TemariProto pose="glow" size={200} equipped={equipped} />
                    </div>

                    {/* CTAs */}
                    <div className="relative flex flex-col gap-2.5">
                        <button
                            type="button"
                            onClick={handleEquip}
                            className="focus-ring-on-sky inline-flex w-full items-center justify-center gap-1.5 rounded-full bg-horizon py-[14px] font-sans text-sm font-semibold text-sky transition-opacity hover:opacity-90"
                        >
                            <Icon icon="mdi:hanger" width={16} height={16} aria-hidden />
                            Pakai sekarang
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="focus-ring-on-sky inline-flex w-full items-center justify-center gap-1.5 rounded-full border border-cream/30 py-3 font-sans text-[13px] font-medium text-cream transition-colors hover:bg-cream/10"
                        >
                            <Icon icon="mdi:close" width={14} height={14} aria-hidden />
                            Nanti aja
                        </button>
                    </div>
                </motion.div>
            </motion.div>}
        </AnimatePresence>
    );
}
