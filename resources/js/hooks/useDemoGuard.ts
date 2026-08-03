import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

/**
 * Front-door for the demo write-guard: a demo visitor's Telegram action opens
 * the friendly `DemoBlockedModal` instead of silently hitting the backend
 * `block-demo-telegram` 403/redirect. Non-demo users pass straight through.
 */
export function useDemoGuard() {
    const isDemo = usePage<SharedProps>().props.auth.user?.is_demo ?? false;
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
