import { usePage } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import { Suspense, useState } from 'react';

import type { FeedbackSubject } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { lazyIsland } from '@/lib/lazyIsland';

const FlagSheet = lazyIsland(() => import('./FlagSheet'));

const ICON_BUTTON_CLASS =
    'inline-flex size-11 flex-none items-center justify-center rounded-full';

/**
 * The same 44px target on a 20px box, so the control can sit on an eyebrow
 * line without the row growing around it.
 */
const COMPACT_BUTTON_CLASS =
    "relative inline-flex size-5 flex-none items-center justify-center rounded-full before:absolute before:-inset-3 before:content-['']";

/**
 * "This is wrong" on one plan day or one narration, as a single icon that
 * opens a sheet. Only the icon is on the first-paint path; the sheet and the
 * dialog under it arrive with the first tap.
 */
export default function FlagWrong({
    subjectType,
    subjectId,
    label,
    flagged = false,
    onSky = false,
    compact = false,
}: Readonly<{
    subjectType: FeedbackSubject;
    subjectId: number;
    /** What the icon says it flags, e.g. `flag this read`. */
    label: string;
    /** Already flagged by this athlete, per the server. */
    flagged?: boolean;
    /** Cream-on-sky styling, for a block drawn on a dark panel. */
    onSky?: boolean;
    /** Draw on a small box, keeping the 44px target — see {@link COMPACT_BUTTON_CLASS}. */
    compact?: boolean;
}>) {
    const isDemo = usePage<SharedProps>().props.auth.user?.is_demo === true;
    const [open, setOpen] = useState(false);
    const [asked, setAsked] = useState(false);
    const [sent, setSent] = useState(false);

    // The demo is a shared sandbox: the server refuses the write, so offering
    // the control would only promise something that can't land.
    if (isDemo) {
        return null;
    }

    const tone = onSky ? 'text-ink-on-sky' : 'text-text-3';
    const box = compact ? COMPACT_BUTTON_CLASS : ICON_BUTTON_CLASS;

    if (flagged || sent) {
        return (
            <span
                aria-label="flagged"
                title="flagged"
                className={cn(box, tone)}
            >
                <Icon icon={Flag} className="size-5 fill-current" aria-hidden />
            </span>
        );
    }

    return (
        <>
            <button
                type="button"
                aria-label={label}
                title={label}
                onClick={() => {
                    setAsked(true);
                    setOpen(true);
                }}
                className={cn(
                    box,
                    'focus-ring pressable transition-colors hover:text-foreground',
                    tone,
                )}
            >
                <Icon icon={Flag} className="size-5" aria-hidden />
            </button>
            {asked && (
                <Suspense fallback={null}>
                    <FlagSheet
                        subjectType={subjectType}
                        subjectId={subjectId}
                        open={open}
                        onOpenChange={setOpen}
                        onSent={() => setSent(true)}
                    />
                </Suspense>
            )}
        </>
    );
}
