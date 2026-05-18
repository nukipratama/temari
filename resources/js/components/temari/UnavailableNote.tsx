import { Icon } from '@iconify/react';

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
    message = 'Narasi Temari belum tersedia, coba lagi nanti.',
    size = 'md',
}: Props) {
    return (
        <span
            className={`inline-flex items-center rounded-full bg-surface-sunken text-ink-meta ${SIZE_CLASSES[size]}`}
            role="status"
        >
            <Icon icon="mdi:clock-alert-outline" aria-hidden />
            <span>{message}</span>
        </span>
    );
}
