import { Flag } from 'lucide-react';
import { Suspense, useState } from 'react';

import type { FeedbackSubject } from '@/types/generated';

import { Icon } from '@/components/ui/Icon';
import { useSharedProps } from '@/hooks/useSharedProps';
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
    compact = false,
}: Readonly<{
    subjectType: FeedbackSubject;
    subjectId: number;
    /** What the icon says it flags, e.g. `flag this read`. */
    label: string;
    /** Already flagged by this athlete, per the server. */
    flagged?: boolean;
    /** Draw on a small box, keeping the 44px target — see {@link COMPACT_BUTTON_CLASS}. */
    compact?: boolean;
}>) {
    const isDemo = useSharedProps().auth.user?.is_demo === true;
    const [open, setOpen] = useState(false);
    const [asked, setAsked] = useState(false);
    const [sent, setSent] = useState(false);

    // The demo is a shared sandbox: the server refuses the write, so offering
    // the control would only promise something that can't land.
    if (isDemo) {
        return null;
    }

    const box = compact ? COMPACT_BUTTON_CLASS : ICON_BUTTON_CLASS;

    return (
        <>
            {!flagged && !sent && (
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
                        'focus-ring pressable text-text-3 transition-colors hover:text-foreground',
                    )}
                >
                    <Icon icon={Flag} className="size-5" aria-hidden />
                </button>
            )}
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
