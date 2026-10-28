import { X } from 'lucide-react';
import { type ReactNode } from 'react';

import TemariMascot, {
    type MascotPose,
} from '@/components/temari/TemariMascot';
import { Icon, IconComponent } from '@/components/ui/Icon';
import PillButton, { type PillTone } from '@/components/ui/PillButton';
import Sheet from '@/components/ui/Sheet';

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

/**
 * The confirm {@link TemariNudgeModal} loads on its first open: a bottom
 * {@link Sheet} on phones with the primary action in thumb reach, and a
 * centered card from `md` up.
 */
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
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
            title={title}
            lead={
                <div className="mb-3 flex justify-center">
                    <TemariMascot pose={pose} size={72} drawIn />
                </div>
            }
            centeredFromMd
            className="text-center"
        >
            <p className="mt-2 font-sans text-sm leading-relaxed text-text-2">
                {body}
            </p>
            <div className="mt-5 flex flex-col gap-2">
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
        </Sheet>
    );
}
