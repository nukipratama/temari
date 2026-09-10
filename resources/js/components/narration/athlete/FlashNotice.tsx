import { X } from 'lucide-react';
import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';

/**
 * The `back()->with('info', …)` confirmation an action leaves behind. This page
 * renders standalone rather than under AppShell, so it reads the flash itself
 * instead of relying on a shared toast.
 */
export default function FlashNotice({
    message,
}: Readonly<{ message: string }>) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) {
        return null;
    }

    return (
        <Card
            tone="card"
            padding="panel"
            className="mb-4 flex items-center justify-between gap-3 bg-popover text-sm text-foreground"
        >
            <span>{message}</span>
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="close"
                className="focus-ring shrink-0 rounded-full p-1 text-text-3 hover:text-foreground"
            >
                <Icon icon={X} width={16} aria-hidden />
            </button>
        </Card>
    );
}
