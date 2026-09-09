import { usePage } from '@inertiajs/react';
import { Suspense, lazy, useState } from 'react';

import type { FeedbackSubject } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

const FlagSheet = lazy(() => import('./FlagSheet'));

const ICON_BUTTON_CLASS =
    'inline-flex size-11 flex-none items-center justify-center rounded-full';

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
}: Readonly<{
    subjectType: FeedbackSubject;
    subjectId: number;
    /** What the icon says it flags, e.g. `flag this read`. */
    label: string;
    /** Already flagged by this athlete, per the server. */
    flagged?: boolean;
    /** Cream-on-sky styling, for a block drawn on a dark panel. */
    onSky?: boolean;
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

    if (flagged || sent) {
        return (
            <span
                aria-label="flagged"
                title="flagged"
                className={cn(ICON_BUTTON_CLASS, tone)}
            >
                <Icon
                    icon="mdi:flag"
                    className="size-5 fill-current"
                    aria-hidden
                />
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
                    ICON_BUTTON_CLASS,
                    'focus-ring pressable transition-colors hover:text-foreground',
                    tone,
                )}
            >
                <Icon icon="mdi:flag-outline" className="size-5" aria-hidden />
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
