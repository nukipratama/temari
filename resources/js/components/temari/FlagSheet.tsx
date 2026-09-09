import { router } from '@inertiajs/react';
import { type FormEvent, useId, useState } from 'react';

import type { FeedbackReason, FeedbackSubject } from '@/types/generated';

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

/**
 * The sheet FlagWrong opens, kept in its own module so the Base UI dialog it
 * rests on is fetched on the first tap rather than on every page's first paint.
 * The note stays optional — the reason is the signal worth having, and asking
 * for prose before accepting a flag would cost most of them.
 */
export default function FlagSheet({
    subjectType,
    subjectId,
    open,
    onOpenChange,
    onSent,
}: Readonly<{
    subjectType: FeedbackSubject;
    subjectId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSent: () => void;
}>) {
    const noteId = useId();
    const [reason, setReason] = useState<FeedbackReason | null>(null);
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

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
                    onOpenChange(false);
                    onSent();
                },
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange} title="something off?">
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
    );
}
