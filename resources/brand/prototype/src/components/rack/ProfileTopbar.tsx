import { ArrowLeft, Bell, Settings } from 'lucide-react';

/**
 * resources/brand/profile-redesign.html's .topbar variant — Profile is a
 * pushed sub-page (reached by tapping the avatar elsewhere), not a bottom-nav
 * destination, so the brand lockup is replaced by a back chevron.
 */
export function ProfileTopbar({
    onOpenInbox,
}: Readonly<{ onOpenInbox: () => void }>) {
    return (
        <div className="absolute inset-x-0 top-0 z-10 flex items-center justify-between p-4 pb-2.5">
            <a
                href="#"
                aria-label="Back"
                className="flex size-8 items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
            >
                <ArrowLeft className="size-[18px]" aria-hidden />
            </a>
            <div className="flex items-center gap-1.5">
                <a
                    href="#"
                    aria-label="Settings"
                    className="flex size-8 items-center justify-center rounded-full bg-muted text-text-3 shadow-e1"
                >
                    <Settings className="size-[18px]" aria-hidden />
                </a>
                <button
                    type="button"
                    onClick={onOpenInbox}
                    className="relative flex size-8 items-center justify-center rounded-full bg-muted text-text-3 shadow-e1"
                >
                    <Bell className="size-[18px]" aria-hidden />
                    <span className="absolute top-0.5 right-0.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-destructive px-0.75 font-mono text-[8.5px] font-extrabold text-white">
                        2
                    </span>
                </button>
            </div>
        </div>
    );
}
