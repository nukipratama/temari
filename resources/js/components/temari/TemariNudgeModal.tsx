import { AnimatePresence, motion } from 'framer-motion';
import { usePage } from '@inertiajs/react';
import { useRef, type ReactNode } from 'react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { useDismissable } from '@/hooks/useDismissable';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import { useBodyScrollLock } from '@/hooks/useBodyScrollLock';
import PillButton from '@/components/ui/PillButton';
import TemariProto, { type TemariPose } from '@/components/temari/TemariProto';
import { serverToEquipped } from '@/lib/equippedAccessories';
import { iconButtonVariants } from '@/lib/variants';
import type { SharedProps } from '@/types/inertia';

interface TemariNudgeModalProps {
    open: boolean;
    onClose: () => void;
    title: string;
    body: ReactNode;
    /** Primary CTA. */
    primaryLabel: string;
    /** Iconify icon name shown before the primary label. */
    primaryIcon: string;
    /** Extra classes merged onto the primary CTA (e.g. a brand color override). */
    primaryClassName?: string;
    onPrimary: () => void;
    /** Secondary dismiss label; defaults to a soft "Nanti aja". */
    secondaryLabel?: string;
    pose?: TemariPose;
}

/**
 * The shared shell for Temari's soft "front door" modals: a calm mascot nudge
 * (not a celebration) with a title, a short body, and a primary + dismiss CTA.
 * Backs {@see DemoBlockedModal} and {@see EnableNotificationsModal} so the framer
 * shell, focus trap and equipped-mascot read live in one place.
 */
export default function TemariNudgeModal({
    open,
    onClose,
    title,
    body,
    primaryLabel,
    primaryIcon,
    primaryClassName,
    onPrimary,
    secondaryLabel = 'Nanti aja',
    pose = 'observational',
}: Readonly<TemariNudgeModalProps>) {
    const panelRef = useRef<HTMLDivElement>(null);

    const equippedAccessories = usePage<SharedProps>().props.equippedAccessories;
    const equipped = equippedAccessories ? serverToEquipped(equippedAccessories) : null;

    useDismissable(open, panelRef, onClose);
    useFocusTrap(open, panelRef);
    useBodyScrollLock(open);

    // Keep AnimatePresence mounted and toggle its child, so the coded exit
    // fade/scale actually runs on close (an early `return null` unmounts the
    // whole tree in the same pass and skips the exit).
    return (
        <AnimatePresence>
            {open && (
                <motion.div
                    key="temari-nudge-backdrop"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    className="fixed inset-0 z-[51] flex items-center justify-center p-4"
                    style={{ background: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(6px)' }}
                >
                    <motion.div
                        key="temari-nudge-panel"
                        ref={panelRef}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="temari-nudge-title"
                        initial={{ opacity: 0, scale: 0.96, y: 8 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.96, y: 8 }}
                        transition={{ duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                        className="flex w-full max-w-sm flex-col overflow-hidden rounded-3xl bg-cream shadow-2xl"
                    >
                        <div className="flex justify-start px-3 pt-3">
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label="Tutup"
                                className={iconButtonVariants({ size: 'sm' })}
                            >
                                <Icon icon="mdi:close" width={16} height={16} />
                            </button>
                        </div>

                        <div className="flex flex-col items-center gap-4 px-6 pb-6 pt-1 text-center">
                            <TemariProto pose={pose} size={120} equipped={equipped} animate />
                            <h2 id="temari-nudge-title" className="font-display text-2xl tracking-tight text-ink">
                                {title}
                            </h2>
                            <p className="font-sans text-sm leading-relaxed text-ink-2">{body}</p>
                        </div>

                        <div className="flex flex-col gap-2 border-t border-cream-deep bg-cream px-5 py-4">
                            <PillButton
                                tone="sky"
                                onClick={onPrimary}
                                className={cn('w-full justify-center py-3.5 font-semibold', primaryClassName)}
                            >
                                <Icon icon={primaryIcon} width={16} height={16} aria-hidden />
                                {primaryLabel}
                            </PillButton>
                            <PillButton tone="ghost" onClick={onClose} className="w-full justify-center">
                                <Icon icon="mdi:close" width={16} height={16} aria-hidden />
                                {secondaryLabel}
                            </PillButton>
                        </div>
                    </motion.div>
                </motion.div>
            )}
        </AnimatePresence>
    );
}
