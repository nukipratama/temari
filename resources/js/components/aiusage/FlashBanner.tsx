import { Icon } from '@iconify/react';
import { useState } from 'react';

/** Inline confirmation for a `back()->with('info', …)` flash (e.g. a retry
 * confirmation). This page renders standalone, not under AppShell, so it
 * reads `usePage().props.flash` itself rather than relying on a shared toast. */
export default function FlashBanner({
    message,
}: Readonly<{ message: string }>) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) {
        return null;
    }

    return (
        <div className="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface-elev px-4 py-3 text-sm text-ink">
            <span>{message}</span>
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="Tutup"
                className="focus-ring shrink-0 rounded-full p-1 text-ink-3 hover:text-ink"
            >
                <Icon icon="mdi:close" width={16} aria-hidden />
            </button>
        </div>
    );
}
