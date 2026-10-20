import { X } from 'lucide-react';
import { useState } from 'react';

import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';

/**
 * Inline confirmation for a `back()->with('info', …)` flash (e.g. a retry
 * confirmation). The narration pages render standalone, not under AppShell, so
 * each reads `flash.info` and passes it in as `message`.
 */
export default function FlashBanner({
    message,
}: Readonly<{ message: string }>) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) {
        return null;
    }

    return (
        <Card
            padding="panel"
            className="mb-4 flex items-center justify-between gap-3 bg-popover text-sm text-foreground"
        >
            <span>{message}</span>
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="Close"
                className="focus-ring shrink-0 rounded-full p-1 text-text-3 hover:text-foreground"
            >
                <Icon icon={X} width={16} aria-hidden />
            </button>
        </Card>
    );
}
