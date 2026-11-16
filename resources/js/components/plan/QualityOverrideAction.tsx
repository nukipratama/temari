import { router } from '@inertiajs/react';
import { Check, Lock, RotateCcw } from 'lucide-react';
import { useState } from 'react';

import type { PlanDayOverride } from '@/types/inertia';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { showToast } from '@/hooks/useToast';

/** The quiet link that opens a change's full reason, at the end of its one-line why. */
export function WhyLink({
    open,
    onToggle,
}: Readonly<{ open: boolean; onToggle: () => void }>) {
    return (
        <button
            type="button"
            aria-expanded={open}
            onClick={onToggle}
            className="pressable focus-ring font-semibold text-horizon-ink underline underline-offset-2"
        >
            why
        </button>
    );
}

function WhyNote({ reason }: Readonly<{ reason: string }>) {
    return (
        <p className="mt-2 border-l-2 border-border-strong pl-3 text-xs leading-relaxed text-text-2">
            {reason}
        </p>
    );
}

/**
 * The week's one "i'm ready" override on a day: the offer behind a confirm
 * sheet carrying the plan's own reason, the rule that refuses it, or, once
 * taken, the line saying the athlete chose it and "ease it again" until a run
 * lands. The full reason opens from a "why" link: the day's own reason line
 * carries it when the caller passes `whyOpen`, else it sits beside the action.
 */
export default function QualityOverrideAction({
    sessionId,
    override,
    whyOpen,
}: Readonly<{
    sessionId: number;
    override: PlanDayOverride;
    /** Set when the caller's reason line carries the "why" link. */
    whyOpen?: boolean;
}>) {
    const [confirming, setConfirming] = useState(false);
    const [ownWhyOpen, setOwnWhyOpen] = useState(false);
    const reasonOpen = whyOpen ?? ownWhyOpen;
    const ownLink = whyOpen === undefined && (
        <span className="text-xs">
            <WhyLink
                open={ownWhyOpen}
                onToggle={() => setOwnWhyOpen((open) => !open)}
            />
        </span>
    );
    const [processing, setProcessing] = useState(false);
    const url = `/plan/sessions/${sessionId}/override`;
    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onError: (errors: Record<string, string>) => {
            if (errors.override) {
                showToast(errors.override);
            }
        },
    };

    if (override.state === 'taken') {
        return (
            <div className="mt-2">
                <p className="text-xs leading-relaxed text-text-2">
                    {override.note}
                </p>
                {override.undoable && (
                    <PillButton
                        tone="horizon"
                        size="sm"
                        className="mt-2"
                        disabled={processing}
                        onClick={() => router.delete(url, options)}
                    >
                        ease it again
                    </PillButton>
                )}
            </div>
        );
    }

    if (override.state === 'locked') {
        return (
            <div className="mt-2">
                {reasonOpen && <WhyNote reason={override.reason} />}
                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <p className="inline-flex items-center gap-1.5 rounded-sm bg-muted px-2.5 py-1 text-xs text-text-2">
                        <Icon icon={Lock} className="size-3" aria-hidden />
                        can't bring back: {override.blocker}
                    </p>
                    {ownLink}
                </div>
            </div>
        );
    }

    return (
        <div className="mt-2">
            {reasonOpen && <WhyNote reason={override.reason} />}
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <PillButton
                    tone="outline"
                    size="sm"
                    className="border-horizon-ink/50 text-horizon-ink hover:border-horizon-ink hover:text-horizon-ink"
                    disabled={processing}
                    onClick={() => setConfirming(true)}
                >
                    <Icon icon={RotateCcw} className="size-3.5" aria-hidden />
                    {override.label}
                </PillButton>
                {ownLink}
            </div>
            <TemariNudgeModal
                open={confirming}
                onClose={() => setConfirming(false)}
                title={override.label}
                body={`${override.reason} the coach will remember this.`}
                primaryLabel="confirm"
                primaryIcon={Check}
                secondaryLabel="cancel"
                onPrimary={() => {
                    setConfirming(false);
                    router.post(url, {}, options);
                }}
            />
        </div>
    );
}
