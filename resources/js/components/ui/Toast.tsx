import type { ReactNode } from 'react';

import { useToast } from '@/hooks/useToast';
import { cn } from '@/lib/cn';

export function ToastMessage({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <p className="rounded-full border border-border bg-popover px-4 py-2.5 text-sm font-semibold text-popover-foreground shadow-e2 reveal">
            {children}
        </p>
    );
}

/**
 * The shell's one toast, for actions that would otherwise say nothing back.
 * The live region stays mounted so a screen reader hears each new message;
 * above the nav it clears the pill's own safe-area offset plus its height.
 */
export default function Toaster({ aboveNav }: Readonly<{ aboveNav: boolean }>) {
    const toast = useToast();

    return (
        <div
            role="status"
            aria-live="polite"
            className={cn(
                'pointer-events-none fixed inset-x-0 z-40 flex justify-center pl-[max(0.875rem,env(safe-area-inset-left))] pr-[max(0.875rem,env(safe-area-inset-right))]',
                aboveNav
                    ? 'bottom-[calc(max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))+4rem)]'
                    : 'bottom-[max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))]',
            )}
        >
            {toast !== null && (
                <ToastMessage key={toast.id}>{toast.message}</ToastMessage>
            )}
        </div>
    );
}
