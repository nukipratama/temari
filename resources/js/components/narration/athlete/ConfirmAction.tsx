import { useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { Icon, type IconComponent } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';

interface ConfirmActionProps {
    label: string;
    icon: IconComponent;
    /** What the confirm step says before anything is posted. */
    question: ReactNode;
    action: string;
    /** Extra form fields to post alongside the confirmation. */
    data?: Record<string, string | number>;
    /** Hides the trigger entirely; use for an action that cannot run right now. */
    disabledReason?: string | null;
    tone?: 'sky' | 'ghost';
}

/**
 * Every operator action here spends money, queues work, or moves an athlete's
 * ceiling, so none of them fire on a single click. The trigger arms an inline
 * confirm step that states what is about to happen; the browser's own
 * `confirm()` is never used, since it cannot say any of that.
 */
export default function ConfirmAction({
    label,
    icon,
    question,
    action,
    data = {},
    disabledReason = null,
    tone = 'sky',
}: Readonly<ConfirmActionProps>) {
    const [armed, setArmed] = useState(false);
    const { post, processing } = useForm(data);

    if (disabledReason !== null) {
        return <p className="text-xs text-text-3">{disabledReason}</p>;
    }

    if (!armed) {
        return (
            <PillButton
                type="button"
                tone={tone}
                size="sm"
                onClick={() => setArmed(true)}
            >
                <Icon icon={icon} aria-hidden />
                <span>{label}</span>
            </PillButton>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-muted px-3 py-2">
            <p className="text-xs text-text-2">{question}</p>
            <PillButton
                type="button"
                tone="sky"
                size="sm"
                disabled={processing}
                onClick={() => post(action, { preserveScroll: true })}
            >
                confirm
            </PillButton>
            <PillButton
                type="button"
                tone="ghost"
                size="sm"
                onClick={() => setArmed(false)}
            >
                cancel
            </PillButton>
        </div>
    );
}
