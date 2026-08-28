import { Bell } from 'lucide-react';

import { TemariMark } from '@/components/rack/TemariMark';

/**
 * Shared post-auth chrome — the mockups' .topbar,
 * floating over content (position:absolute, no background) rather than
 * sitting in normal flow. Static: unread count and avatar initial are the
 * same fixture values every mockup uses.
 */
export function AppTopbar({
    onOpenInbox,
}: Readonly<{ onOpenInbox: () => void }>) {
    return (
        <div className="absolute inset-x-0 top-0 z-10 flex items-center justify-between p-4 pb-2.5">
            <div className="flex items-center gap-1.75 rounded-full bg-muted py-1.75 pr-3.25 pl-2.5 text-sm font-extrabold shadow-[0_1px_2px_rgba(58,45,20,.06),0_1px_1px_rgba(58,45,20,.04)]">
                <TemariMark />
                temari
            </div>
            <div className="flex items-center gap-1.5">
                <button
                    type="button"
                    onClick={onOpenInbox}
                    className="relative flex size-8 items-center justify-center rounded-full bg-muted text-text-3 shadow-[0_1px_2px_rgba(58,45,20,.06),0_1px_1px_rgba(58,45,20,.04)]"
                >
                    <Bell className="size-[18px]" aria-hidden />
                    <span className="absolute top-0.5 right-0.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-destructive px-0.75 font-mono text-[8.5px] font-extrabold text-white">
                        2
                    </span>
                </button>
                <div className="flex size-7 items-center justify-center rounded-full bg-muted font-mono text-[11px] font-bold text-text-2 shadow-[0_1px_2px_rgba(58,45,20,.06),0_1px_1px_rgba(58,45,20,.04)]">
                    N
                </div>
            </div>
        </div>
    );
}
