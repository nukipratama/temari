import { Icon } from '@iconify/react';

/**
 * One-tap offer to pick up the filter the user last used. Deliberately an offer
 * rather than an auto-apply: landing on a silently pre-filtered list reads as a
 * history that lost runs. Dismissing forgets it, so the row can't nag.
 */
export default function ResumeFilterChip({
    summary,
    onResume,
    onDismiss,
}: Readonly<{ summary: string; onResume: () => void; onDismiss: () => void }>) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onClick={onResume}
                className="pressable focus-ring inline-flex items-center gap-1.5 rounded-full border border-line/60 bg-surface-warm py-1 pl-3 pr-3.5 text-xs font-medium text-ink-2"
            >
                <Icon icon="mdi:history" width={13} height={13} aria-hidden />
                Lanjutkan: {summary}
            </button>
            <button
                type="button"
                onClick={onDismiss}
                aria-label="Lupakan filter terakhir"
                className="focus-ring rounded px-1 text-xs font-medium text-ink-3 hover:text-ink-2"
            >
                <Icon icon="mdi:close" width={13} height={13} aria-hidden />
            </button>
        </div>
    );
}
