import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useId, useState } from 'react';

import type { FeedbackReason, FeedbackSubject } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import Sheet, { SheetClose } from '@/components/ui/Sheet';
import { cn } from '@/lib/cn';

/** Mirrors the `note` column and the max on StoreFeedbackRequest. */
const MAX_NOTE_LENGTH = 280;

/**
 * The reasons each subject offers, split the same way
 * `App\Enums\FeedbackReason::valuesFor()` splits them.
 */
const REASONS: Record<
    FeedbackSubject,
    readonly { value: FeedbackReason; label: string }[]
> = {
    narration: [
        { value: 'facts_wrong', label: 'facts wrong' },
        { value: 'tone_off', label: 'tone off' },
        { value: 'too_long', label: 'too long' },
        { value: 'ignores_my_plan', label: 'ignores my plan' },
    ],
    plan_day: [
        { value: 'wrong_day', label: 'wrong day' },
        { value: 'too_hard', label: 'too hard' },
        { value: 'too_easy', label: 'too easy' },
        { value: 'wrong_pace', label: 'wrong pace' },
    ],
};

const ICON_BUTTON_CLASS =
    'inline-flex size-11 flex-none items-center justify-center rounded-full';

/**
 * "This is wrong" on one plan day or one narration, as a single icon that
 * opens a sheet. The note stays optional — the reason is the signal worth
 * having, and asking for prose before accepting a flag would cost most of them.
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
    const noteId = useId();
    const [open, setOpen] = useState(false);
    const [sent, setSent] = useState(false);
    const [reason, setReason] = useState<FeedbackReason | null>(null);
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

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

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (reason === null) {
            return;
        }

        router.post(
            '/feedback',
            {
                subject_type: subjectType,
                subject_id: subjectId,
                reason,
                note: note.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onSuccess: () => {
                    setOpen(false);
                    setSent(true);
                },
            },
        );
    };

    return (
        <>
            <button
                type="button"
                aria-label={label}
                title={label}
                onClick={() => setOpen(true)}
                className={cn(
                    ICON_BUTTON_CLASS,
                    'focus-ring pressable transition-colors hover:text-foreground',
                    tone,
                )}
            >
                <Icon icon="mdi:flag-outline" className="size-5" aria-hidden />
            </button>
            <Sheet open={open} onOpenChange={setOpen} title="something off?">
                <form onSubmit={submit} className="flex flex-col gap-4 pt-4">
                    <div className="flex flex-wrap gap-2">
                        {REASONS[subjectType].map((choice) => (
                            <button
                                key={choice.value}
                                type="button"
                                aria-pressed={reason === choice.value}
                                onClick={() => setReason(choice.value)}
                                className={cn(
                                    'focus-ring pad-chip min-h-11 rounded-full border text-sm transition-colors',
                                    reason === choice.value
                                        ? 'border-transparent bg-horizon text-sky'
                                        : 'border-border-strong text-foreground',
                                )}
                            >
                                {choice.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-col gap-2">
                        <label htmlFor={noteId} className="text-xs text-text-2">
                            anything to add?
                        </label>
                        <textarea
                            id={noteId}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            maxLength={MAX_NOTE_LENGTH}
                            rows={3}
                            placeholder="optional"
                            className="focus-ring w-full rounded-sm border border-border-strong bg-card px-3 py-2 text-sm text-foreground placeholder:text-text-3"
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <PillButton
                            type="submit"
                            tone="sky"
                            size="sm"
                            className="min-h-11"
                            disabled={reason === null || sending}
                        >
                            send
                        </PillButton>
                        <SheetClose className="focus-ring pad-chip min-h-11 rounded-full text-sm text-text-2">
                            never mind
                        </SheetClose>
                    </div>
                </form>
            </Sheet>
        </>
    );
}
