import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';

export interface ActiveChip {
    /** Stable identity for React and for the remove handler. */
    key: string;
    label: string;
    onRemove: () => void;
}

interface ActiveFilterChipsProps {
    chips: ReadonlyArray<ActiveChip>;
    onClearAll?: () => void;
    className?: string;
}

/**
 * The active filters, spelled out beneath the trigger instead of hidden behind
 * it. The popover's count badge tells you *how many* filters are on; only chips
 * tell you *which*, and let you drop one without reopening the panel and hunting
 * for it. That matters most on the states where a narrowed list is otherwise
 * indistinguishable from a short history.
 *
 * Renders nothing when no filter is active, so the row costs no vertical space
 * in the common case.
 */
export default function ActiveFilterChips({
    chips,
    onClearAll,
    className,
}: Readonly<ActiveFilterChipsProps>) {
    if (chips.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            {chips.map((chip) => (
                <button
                    key={chip.key}
                    type="button"
                    onClick={chip.onRemove}
                    aria-label={`Hapus filter ${chip.label}`}
                    className="pressable focus-ring inline-flex items-center gap-1.5 rounded-full border border-sky/40 bg-sky/[0.06] py-1 pl-3 pr-2 text-xs font-medium text-sky"
                >
                    {chip.label}
                    <Icon icon="mdi:close" width={13} height={13} aria-hidden />
                </button>
            ))}
            {chips.length > 1 && onClearAll && (
                <button
                    type="button"
                    onClick={onClearAll}
                    className="focus-ring rounded px-1 text-xs font-medium text-ink-3 underline-offset-2 hover:text-ink-2 hover:underline"
                >
                    Hapus semua
                </button>
            )}
        </div>
    );
}
