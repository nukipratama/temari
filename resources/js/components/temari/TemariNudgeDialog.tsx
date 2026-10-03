import { X } from 'lucide-react';
import { type ReactNode } from 'react';

import TemariMascot, {
    type MascotPose,
} from '@/components/temari/TemariMascot';
import { Icon, IconComponent } from '@/components/ui/Icon';
import Overlay, { OverlayTitle } from '@/components/ui/Overlay';
import PillButton, { type PillTone } from '@/components/ui/PillButton';
import { iconButtonVariants } from '@/lib/variants';

export interface TemariNudgeModalProps {
    open: boolean;
    onClose: () => void;
    title: string;
    body: ReactNode;
    /** Primary CTA. */
    primaryLabel: string;
    /** Iconify icon name shown before the primary label. */
    primaryIcon: IconComponent;
    /** Default `sky`; `danger` for a destructive confirmation. */
    primaryTone?: PillTone;
    onPrimary: () => void;
    /** Secondary dismiss label; defaults to a soft "Not now". */
    secondaryLabel?: string;
    /** Temari's pose; `concerned` for a destructive confirmation. */
    pose?: MascotPose;
}

/** The dialog {@link TemariNudgeModal} loads on its first open, on the shared {@link Overlay}. */
export default function TemariNudgeDialog({
    open,
    onClose,
    title,
    body,
    primaryLabel,
    primaryIcon,
    primaryTone = 'sky',
    onPrimary,
    secondaryLabel = 'not now',
    pose = 'neutral',
}: Readonly<TemariNudgeModalProps>) {
    return (
        <Overlay
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
            backdropProps={{
                className: 'backdrop-reveal fixed inset-0 z-[51]',
                style: {
                    background: 'rgba(0,0,0,0.5)',
                    backdropFilter: 'blur(6px)',
                },
            }}
            className="panel-reveal fixed inset-0 z-[51] m-auto flex h-fit max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-sm flex-col overflow-y-auto rounded-xl bg-card shadow-e4"
        >
            <div className="flex justify-start px-3 pt-3">
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close"
                    className={iconButtonVariants({ size: 'sm' })}
                >
                    <Icon icon={X} width={16} height={16} />
                </button>
            </div>

            <div className="flex flex-col items-center gap-4 px-6 pb-6 pt-1 text-center">
                <TemariMascot pose={pose} size={72} drawIn />
                <OverlayTitle className="font-serif text-2xl tracking-tight text-foreground">
                    {title}
                </OverlayTitle>
                <p className="font-sans text-sm leading-relaxed text-text-2">
                    {body}
                </p>
            </div>

            <div className="flex flex-col gap-2 border-t border-border bg-card px-5 py-4">
                <PillButton
                    tone={primaryTone}
                    onClick={onPrimary}
                    className="w-full justify-center py-3.5 font-semibold"
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
                    <Icon icon={X} width={16} height={16} aria-hidden />
                    {secondaryLabel}
                </PillButton>
            </div>
        </Overlay>
    );
}
