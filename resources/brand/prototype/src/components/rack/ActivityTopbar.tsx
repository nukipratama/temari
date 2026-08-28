import { ArrowLeft } from 'lucide-react';

/**
 * Run-detail's back link — real Runs/Show.tsx has a plain "History · Log"
 * BackLink, no gear/bell equivalent; icon-only chevron matches the other
 * pushed-screen topbars' back-button treatment.
 */
export function ActivityTopbar() {
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
