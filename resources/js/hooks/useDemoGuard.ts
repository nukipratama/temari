import { useState } from 'react';

import { useSharedProps } from '@/hooks/useSharedProps';

/**
 * Front-door for the demo write-guard: a demo visitor's guarded write (Telegram, race, time-trial answer, sync now, zones resync) opens
 * the friendly `DemoBlockedModal` instead of silently hitting the backend
 * `block-demo-writes` 403/redirect. Non-demo users pass straight through.
 */
export function useDemoGuard() {
    const isDemo = useSharedProps().auth.user?.is_demo ?? false;
    const [open, setOpen] = useState(false);

    const guard = (action: () => void) => {
        if (isDemo) {
            setOpen(true);
            return;
        }
        action();
    };

    return { isDemo, open, setOpen, guard };
}
