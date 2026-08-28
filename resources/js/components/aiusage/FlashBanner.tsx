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
        <div className="mb-4 flex items-center justify-between gap-3 rounded-lg border border-border bg-popover px-4 py-3 text-sm text-foreground">
            <span>{message}</span>
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="Close"
                className="focus-ring shrink-0 rounded-full p-1 text-text-3 hover:text-foreground"
            >
                <Icon icon="mdi:close" width={16} aria-hidden />
            </button>
        </div>
    );
}
