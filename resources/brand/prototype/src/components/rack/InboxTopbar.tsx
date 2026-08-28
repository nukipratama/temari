import { ArrowLeft } from 'lucide-react';

/**
 * Inbox has no HTML mockup — real Inbox.tsx has no dedicated topbar of its
 * own (reached via the shared app chrome's bell icon), so this follows the
 * same pushed-screen back-chevron treatment as ActivityTopbar/SettingsTopbar.
 */
export function InboxTopbar() {
    return (
        <div className="absolute inset-x-0 top-0 z-10 flex items-center p-4 pb-2.5">
            <a
                href="#"
                aria-label="Back"
                className="flex size-8 items-center justify-center rounded-full bg-muted text-foreground shadow-e1"
            >
                <ArrowLeft className="size-[18px]" aria-hidden />
            </a>
        </div>
    );
}
