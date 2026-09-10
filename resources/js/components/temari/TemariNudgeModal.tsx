import { useRef, type ReactNode } from 'react';

import FaceIcon from '@/components/temari/FaceIcon';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { useExitTransition } from '@/hooks/useExitTransition';
import { useModal } from '@/hooks/useModal';
import { cn } from '@/lib/cn';
import { iconButtonVariants } from '@/lib/variants';

const EXIT_MS = 300;

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
    /** Secondary dismiss label; defaults to a soft "Not now". */
    secondaryLabel?: string;
}

/**
 * The shared shell for Temari's soft "front door" modals: a calm nudge (not a
 * celebration) with a title, a short body, and a primary + dismiss CTA. Backs
 * {@see DemoBlockedModal} and {@see EnableNotificationsModal} so the framer
 * shell and focus trap live in one place.
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
    secondaryLabel = 'Not now',
}: Readonly<TemariNudgeModalProps>) {
    const panelRef = useRef<HTMLDivElement>(null);

    useModal(open, panelRef, onClose);

    const { rendered, closing } = useExitTransition(open, EXIT_MS);

    if (!rendered) {
        return null;
    }

    return (
        <div
            data-closing={closing ? '' : undefined}
            className="backdrop-reveal fixed inset-0 z-[51] flex items-center justify-center p-4"
            style={{
                background: 'rgba(0,0,0,0.5)',
                backdropFilter: 'blur(6px)',
            }}
        >
            <div
                data-closing={closing ? '' : undefined}
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="temari-nudge-title"
                className="panel-reveal flex w-full max-w-sm flex-col overflow-hidden rounded-xl bg-card shadow-e4"
            >
                <div className="flex justify-start px-3 pt-3">
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className={iconButtonVariants({ size: 'sm' })}
                    >
                        <Icon icon="mdi:close" width={16} height={16} />
                    </button>
                </div>

                <div className="flex flex-col items-center gap-4 px-6 pb-6 pt-1 text-center">
                    <FaceIcon size={72} />
                    <h2
                        id="temari-nudge-title"
                        className="font-serif text-2xl tracking-tight text-foreground"
                    >
                        {title}
                    </h2>
                    <p className="font-sans text-sm leading-relaxed text-text-2">
                        {body}
                    </p>
                </div>

                <div className="flex flex-col gap-2 border-t border-border bg-card px-5 py-4">
                    <PillButton
                        tone="sky"
                        onClick={onPrimary}
                        className={cn(
                            'w-full justify-center py-3.5 font-semibold',
                            primaryClassName,
                        )}
                    >
                        <Icon
                            icon={primaryIcon}
                            width={16}
                            height={16}
                            aria-hidden
                        />
                        {primaryLabel}
                    </PillButton>
                    <PillButton
                        tone="ghost"
                        onClick={onClose}
                        className="w-full justify-center"
                    >
                        <Icon
                            icon="mdi:close"
                            width={16}
                            height={16}
                            aria-hidden
                        />
                        {secondaryLabel}
                    </PillButton>
                </div>
            </div>
        </div>
    );
}
