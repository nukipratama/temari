import { ChevronLeft } from 'lucide-react';

import { Icon } from '@/components/ui/Icon';

/** The step header's back control, the same size and tone on every step. */
export default function BackButton({
    onClick,
}: Readonly<{ onClick: () => void }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label="Back"
            className="focus-ring pressable flex size-11 flex-none items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
        >
            <Icon icon={ChevronLeft} width={18} height={18} aria-hidden />
        </button>
    );
}
