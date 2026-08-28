import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

export type UnavailableNoteSize = 'sm' | 'md';

interface Props {
    message?: string;
    size?: UnavailableNoteSize;
}

const SIZE_CLASSES: Record<UnavailableNoteSize, string> = {
    sm: 'text-xs px-2 py-1 gap-1.5',
    md: 'text-sm px-3 py-2 gap-2',
};

export default function UnavailableNote({
    message = 'Temari is taking a moment. Try again shortly.',
    size = 'md',
}: Readonly<Props>) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full bg-muted text-text-2',
                SIZE_CLASSES[size],
            )}
            role="status"
        >
            <Icon icon="mdi:clock-alert-outline" aria-hidden />
            <span>{message}</span>
        </span>
    );
}
