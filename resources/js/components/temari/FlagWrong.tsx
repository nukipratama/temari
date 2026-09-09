import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useId, useState } from 'react';

import type { FeedbackSubject } from '@/types/generated';
import type { SharedProps } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';

/** Mirrors the `note` column and the max on StoreFeedbackRequest. */
const MAX_NOTE_LENGTH = 280;

/**
 * "This is wrong" on one plan day or one narration. The note is optional — the
 * flag itself is the signal worth having, and asking for an explanation before
 * accepting one would cost most of them.
 */
export default function FlagWrong({
    subjectType,
    subjectId,
    label,
    onSky = false,
}: Readonly<{
    subjectType: FeedbackSubject;
    subjectId: number;
    label: string;
    /** Cream-on-sky styling, for a block drawn on a dark panel. */
    onSky?: boolean;
}>) {
    const isDemo = usePage<SharedProps>().props.auth.user?.is_demo === true;
    const noteId = useId();
    const [open, setOpen] = useState(false);
    const [sent, setSent] = useState(false);
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

    // The demo is a shared sandbox: the server refuses the write, so offering
    // the control would only promise something that can't land.
    if (isDemo) {
        return null;
    }

    if (sent) {
        return (
            <span
                className={`mt-3 block text-xs ${onSky ? 'text-ink-on-sky' : 'text-text-2'}`}
            >
                noted, thanks
            </span>
        );
    }

    if (!open) {
        return (
            <PillButton
                tone="ghost"
                size="sm"
                onSky={onSky}
                className="mt-3 min-h-11"
                onClick={() => setOpen(true)}
            >
                <Icon
                    icon="mdi:flag-outline"
                    className="size-3.5"
                    aria-hidden
                />
                <span>{label}</span>
            </PillButton>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            '/feedback',
            {
                subject_type: subjectType,
                subject_id: subjectId,
                note: note.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onSuccess: () => setSent(true),
            },
        );
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-2">
            <label
                htmlFor={noteId}
                className={`text-xs ${onSky ? 'text-ink-on-sky' : 'text-text-2'}`}
            >
                what&apos;s off about it?
            </label>
            <textarea
                id={noteId}
                value={note}
                onChange={(event) => setNote(event.target.value)}
                maxLength={MAX_NOTE_LENGTH}
                rows={2}
                placeholder="optional"
                className="focus-ring w-full rounded-sm border border-border-strong bg-card px-3 py-2 text-sm text-foreground placeholder:text-text-3"
            />
            <div className="flex flex-wrap gap-2">
                <PillButton
                    type="submit"
                    tone="sky"
                    size="sm"
                    onSky={onSky}
                    className="min-h-11"
                    disabled={sending}
                >
                    send
                </PillButton>
                <PillButton
                    tone="ghost"
                    size="sm"
                    onSky={onSky}
                    className="min-h-11"
                    onClick={() => setOpen(false)}
                >
                    never mind
                </PillButton>
            </div>
        </form>
    );
}
