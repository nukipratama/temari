import { ArrowLeft, Bell } from 'lucide-react';

/**
 * resources/brand/settings-redesign.html's .topbar variant — Settings is a
 * pushed screen reached via the gear icon on Profile's top bar, so it gets a
 * back chevron instead of the brand lockup, no bottom nav.
 */
export function SettingsTopbar({
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
    );
}
